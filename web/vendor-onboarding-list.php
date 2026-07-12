<?php
/**
 * Vendor Onboarding List - The Big Board
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the main list page for browsing all vendor onboarding requests.
 * It's basically the command center for the entire TPRM workflow -- you can
 * filter by status (draft, submitted, in review, approved, etc.), search by
 * vendor name, and take action on requests. Admins and cyber TPRM can approve
 * or move requests to review right from the list, while stakeholders get a
 * simplified view of just their assigned vendors. Also has a "Vendor Tasks"
 * tab that pulls in assessment data, and shows annual review status badges
 * so you can see who's overdue at a glance. The sidebar navigation is
 * permission-aware -- stakeholders get the cliff notes version.
 */

require_once 'includes/init.php';
requireAuth(); // Gotta be logged in to see the goods

// The usual gang of singletons
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// Load SRS service for calculating vendor security grades in the table
require_once __DIR__ . '/includes/classes/SRSService.php';
$srsService = new SRSService();

// ============================================================================
// PERMISSION CHECKS
// Three levels of read access:
//   read = see everything (admins)
//   read_own = see only what you created
//   read_assigned = see vendors you're a stakeholder on
// Also check create, export/import, and approve permissions.
// ============================================================================
$canReadAll = $acl->hasPermission('onboarding.read') || $acl->hasGroup(['administrator', 'cyber_tprm', 'procurement']);
$canReadOwn = $acl->hasPermission('onboarding.read_own') || $acl->hasGroup(['administrator', 'cyber_tprm', 'procurement', 'stakeholder']);
$canReadAssigned = $acl->hasPermission('onboarding.read_assigned') || $acl->hasGroup(['stakeholder']);
$canCreate = $acl->hasPermission('onboarding.create') || $acl->hasGroup(['administrator', 'cyber_tprm', 'procurement', 'stakeholder']);
$canExportImport = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');
$canApprove = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');

if (!$canReadAll && !$canReadOwn && !$canReadAssigned) {
    http_response_code(403);
    die(e(t('vendor-onboarding-list.access_denied')));
}

// ============================================================================
// STATUS CHANGE HANDLER (POST)
// Admins and cyber TPRM can change a request's status right from the list.
// Two actions: set_in_review and set_approved. CSRF token required obviously.
// ============================================================================
$security = Security::getInstance();
$canDelete = $acl->hasPermission('onboarding.delete') || $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['csrf_token']) && $security->validateCSRFToken($_POST['csrf_token'])) {
        $action = $_POST['action'] ?? '';
        $requestId = intval($_POST['request_id'] ?? 0);

        // Force annual review: set last_annual_review_due to today
        if ($action === 'force_review' && $requestId > 0 && $canApprove) {
            try {
                $db->update('vendor_onboarding_requests', ['last_annual_review_due' => date('Y-m-d')], 'id = :id', [':id' => $requestId]);
                redirect('vendor-onboarding-list.php?success=force_review');
            } catch (Exception $e) {
                error_log('Force review error: ' . $e->getMessage());
            }
        }

        if ($requestId > 0 && in_array($action, ['set_in_review', 'set_approved', 'set_evaluation']) && $canApprove) {
            $statusMap = [
                'set_in_review' => 'in_review',
                'set_approved' => 'approved',
                'set_evaluation' => 'evaluation',
            ];
            $newStatus = $statusMap[$action];
            try {
                $updateData = ['status' => $newStatus];
                // If approving/evaluating and vendor_id is null, assign placeholder vendor_id
                if (in_array($newStatus, ['approved', 'evaluation'])) {
                    $row = $db->fetchOne('SELECT vendor_id FROM vendor_onboarding_requests WHERE id = :id', [':id' => $requestId]);
                    if ($row && (empty($row['vendor_id']) || $row['vendor_id'] === null)) {
                        $updateData['vendor_id'] = 99999;
                    }
                }
                $db->update('vendor_onboarding_requests', $updateData, 'id = :id', [':id' => $requestId]);

                redirect('vendor-onboarding-list.php?success=' . $newStatus);
            } catch (Exception $e) {
                error_log('Status change error: ' . $e->getMessage());
            }
        }

        // Delete draft requests (admins/cyber_tprm or creator of the draft)
        if ($action === 'delete_draft' && $requestId > 0) {
            $req = $db->fetchOne(
                'SELECT id, vendor_name, status, created_by FROM vendor_onboarding_requests WHERE id = :id',
                [':id' => $requestId]
            );
            $isReqOwner = $req && intval($req['created_by']) === intval($user['id']);
            $canDeleteThis = $canDelete || ($isReqOwner && $req['status'] === 'draft');
            if ($req && $req['status'] === 'draft' && $canDeleteThis) {
                try {
                    $auth->audit($user['id'], 'vendor_delete', 'vendor_onboarding_requests', $requestId, [
                        'old' => ['vendor_name' => $req['vendor_name'], 'status' => 'draft']
                    ]);
                    $db->query('DELETE FROM vendor_assessment_responses WHERE assessment_id IN (SELECT id FROM vendor_assessments WHERE vendor_request_id = :rid)', [':rid' => $requestId]);
                    $db->delete('vendor_assessments', 'vendor_request_id = :rid', [':rid' => $requestId]);
                    $db->delete('vendor_onboarding_stakeholders', 'request_id = :rid', [':rid' => $requestId]);
                    $db->delete('vendor_onboarding_requests', 'id = :id', [':id' => $requestId]);
                    redirect('vendor-onboarding-list.php?success=deleted');
                } catch (Exception $e) {
                    error_log('Delete draft error: ' . $e->getMessage());
                }
            }
        }

        // Mass delete draft requests (admin/cyber_tprm only)
        if ($action === 'mass_delete_drafts' && $canDelete) {
            $ids = $_POST['delete_ids'] ?? '';
            $idArray = array_filter(array_map('intval', explode(',', $ids)));
            if (!empty($idArray)) {
                $deletedCount = 0;
                foreach ($idArray as $delId) {
                    $req = $db->fetchOne(
                        'SELECT id, vendor_name, status FROM vendor_onboarding_requests WHERE id = :id AND status = :status',
                        [':id' => $delId, ':status' => 'draft']
                    );
                    if (!$req) continue;
                    try {
                        $auth->audit($user['id'], 'vendor_delete', 'vendor_onboarding_requests', $delId, [
                            'old' => ['vendor_name' => $req['vendor_name'], 'status' => 'draft', 'bulk_delete' => true]
                        ]);
                        $db->query('DELETE FROM vendor_assessment_responses WHERE assessment_id IN (SELECT id FROM vendor_assessments WHERE vendor_request_id = :rid)', [':rid' => $delId]);
                        $db->delete('vendor_assessments', 'vendor_request_id = :rid', [':rid' => $delId]);
                        $db->delete('vendor_onboarding_stakeholders', 'request_id = :rid', [':rid' => $delId]);
                        $db->delete('vendor_onboarding_requests', 'id = :id', [':id' => $delId]);
                        $deletedCount++;
                    } catch (Exception $e) {
                        error_log('Mass delete draft error (id=' . $delId . '): ' . $e->getMessage());
                    }
                }
                redirect('vendor-onboarding-list.php?success=mass_deleted&count=' . $deletedCount);
            }
        }
    }
}

// ============================================================================
// SUCCESS/ERROR MESSAGES
// These come from query params set by redirects after actions
// ============================================================================
$success = '';
$error = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'submitted':
            $success = t('vendor-onboarding-list.success_submitted');
            break;
        case 'in_review':
            $success = t('vendor-onboarding-list.success_in_review');
            break;
        case 'approved':
            $success = t('vendor-onboarding-list.success_approved');
            break;
        case 'deactivated':
            $success = t('vendor-onboarding-list.success_deactivated');
            break;
        case 'deleted':
            $success = t('vendor-onboarding-list.success_deleted');
            break;
        case 'mass_deleted':
            $delCount = intval($_GET['count'] ?? 0);
            $success = $delCount . t('vendor-onboarding-list.success_mass_deleted_suffix');
            break;
        case 'evaluation':
            $success = t('vendor-onboarding-list.success_evaluation');
            break;
        case 'force_review':
            $success = t('vendor-onboarding-list.success_force_review');
            break;
    }
}
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'not_found':
            $error = t('vendor-onboarding-list.error_not_found');
            break;
    }
}

// Filter parameters from the URL
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
require_once __DIR__ . '/includes/classes/Pagination.php';
// Sortable columns for the main onboarding table. Each whitelisted sort key maps to a
// fully-qualified SQL expression so ORDER BY is unambiguous AND injection-safe (only
// these keys can pass Pagination::getParams validation and reach the query).
$onbSortMap = [
    'vendor_name'      => 'r.vendor_name',
    'vendor_type'      => 'r.vendor_type',
    'status'           => 'r.status',
    'stakeholder_name' => 'stakeholder_name',
    'updated_at'       => 'r.updated_at',
];
$pgParams = Pagination::getParams([
    'per_page' => 25,
    'sort_column' => 'updated_at',
    'sort_dir' => 'DESC',
    'valid_sort_columns' => array_keys($onbSortMap),
]);
$onbPerPage = $pgParams['per_page'];
$onbCurrentPage = $pgParams['page'];
$onbSortSql = $onbSortMap[$pgParams['sort_column']] ?? 'r.updated_at';
// Renders a clickable, sort-toggling table header with a direction arrow.
$sortableTh = function (string $col, string $label) use ($pgParams) {
    $url = Pagination::buildSortUrl($col, $pgParams['sort_column'], $pgParams['sort_dir']);
    $ind = Pagination::getSortIndicator($col, $pgParams['sort_column'], $pgParams['sort_dir']);
    echo '<th><a href="' . e($url) . '" style="color:inherit;text-decoration:none;white-space:nowrap;">' . e($label) . $ind . '</a></th>';
};

// ============================================================================
// BUILD THE MAIN QUERY
// ============================================================================
$params = [];
$whereConditions = [];

if ($canReadAll) {
    // Admins see everything
} elseif ($canReadOwn && $canReadAssigned) {
    $whereConditions[] = "(r.created_by = :user_id OR EXISTS (
        SELECT 1 FROM vendor_onboarding_stakeholders s
        WHERE s.request_id = r.id AND s.user_id = :user_id2
    ))";
    $params[':user_id'] = $user['id'];
    $params[':user_id2'] = $user['id'];
} elseif ($canReadOwn) {
    $whereConditions[] = "r.created_by = :user_id";
    $params[':user_id'] = $user['id'];
} elseif ($canReadAssigned) {
    $whereConditions[] = "EXISTS (
        SELECT 1 FROM vendor_onboarding_stakeholders s
        WHERE s.request_id = r.id AND s.user_id = :user_id
    )";
    $params[':user_id'] = $user['id'];
}

if ($statusFilter === 'review') {
    // The "Review" pill is the union of In Review + AI Review.
    $whereConditions[] = "r.status IN ('in_review', 'ai_review')";
} elseif (!empty($statusFilter) && in_array($statusFilter, ['draft', 'submitted', 'in_review', 'ai_review', 'approved', 'rejected', 'inactive', 'evaluation'])) {
    $whereConditions[] = "r.status = :status";
    $params[':status'] = $statusFilter;
}

// Procurement multi-select ("Provide Procurement with Update") is available to
// cyber_tprm/admins only, and only while the Review pill is active.
$showProcurementSelect = $canApprove && ($statusFilter === 'review');

// Human-friendly onboarding status labels (ai_review must render as "AI Review",
// not the ucfirst default "Ai review").
$statusLabels = [
    'draft' => t('vendor-onboarding-list.status_draft'), 'submitted' => t('vendor-onboarding-list.status_submitted'), 'in_review' => t('vendor-onboarding-list.status_in_review'),
    'ai_review' => t('vendor-onboarding-list.status_ai_review'), 'evaluation' => t('vendor-onboarding-list.status_evaluation'), 'approved' => t('vendor-onboarding-list.status_approved'),
    'rejected' => t('vendor-onboarding-list.status_rejected'), 'inactive' => t('vendor-onboarding-list.status_inactive'),
];

if (!empty($searchQuery)) {
    $whereConditions[] = "(r.vendor_name LIKE :search OR r.vendor_type LIKE :search2 OR r.relationship_manager LIKE :search3 OR r.project LIKE :search4 OR r.cost_center LIKE :search5)";
    $params[':search'] = '%' . $searchQuery . '%';
    $params[':search2'] = '%' . $searchQuery . '%';
    $params[':search3'] = '%' . $searchQuery . '%';
    $params[':search4'] = '%' . $searchQuery . '%';
    $params[':search5'] = '%' . $searchQuery . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Count query for pagination
$countRow = $db->fetchOne("SELECT COUNT(*) AS cnt FROM vendor_onboarding_requests r {$whereClause}", $params);
$onbTotalRows = (int)($countRow['cnt'] ?? 0);
$onbPg = Pagination::paginate($onbTotalRows, $onbPerPage, $onbCurrentPage);

$query = "
    SELECT r.*,
           u.full_name as created_by_name,
           u.email as created_by_email,
           (SELECT COUNT(*) FROM vendor_procurement_updates pu WHERE pu.request_id = r.id) as note_count,
           (SELECT su.full_name
            FROM vendor_onboarding_stakeholders vs
            JOIN users su ON vs.user_id = su.id
            WHERE vs.request_id = r.id AND vs.role = 'stakeholder'
            ORDER BY vs.assigned_at DESC LIMIT 1) as stakeholder_name
    FROM vendor_onboarding_requests r
    LEFT JOIN users u ON r.created_by = u.id
    {$whereClause}
    ORDER BY {$onbSortSql} {$pgParams['sort_dir']}, r.id DESC
    LIMIT {$onbPerPage} OFFSET {$onbPg['offset']}
";

$requests = $db->fetchAll($query, $params);

// ============================================================================
// PRE-FETCH STAKEHOLDER ASSIGNMENTS FOR CURRENT USER
// Avoids N+1 queries when checking edit permissions in the table loop
// ============================================================================
$userStakeholderRequestIds = [];
if ($acl->hasPermission('onboarding.update_assigned')) {
    $stakeholderRows = $db->fetchAll(
        'SELECT request_id FROM vendor_onboarding_stakeholders WHERE user_id = :uid',
        [':uid' => $user['id']]
    );
    $userStakeholderRequestIds = array_column($stakeholderRows, 'request_id');
}

// ============================================================================
// STATUS COUNTS FOR THE FILTER TAB BADGES
// Runs a separate GROUP BY query so we can show "Approved (12)" etc.
// Uses the same permission filters as the main query.
// ============================================================================
$countQuery = "
    SELECT r.status, COUNT(*) as count
    FROM vendor_onboarding_requests r
    " . ($canReadAll ? '' : (isset($params[':user_id']) ?
        "WHERE " . ($canReadOwn && $canReadAssigned ?
            "(r.created_by = :user_id OR EXISTS (SELECT 1 FROM vendor_onboarding_stakeholders s WHERE s.request_id = r.id AND s.user_id = :user_id2))" :
            ($canReadOwn ? "r.created_by = :user_id" : "EXISTS (SELECT 1 FROM vendor_onboarding_stakeholders s WHERE s.request_id = r.id AND s.user_id = :user_id)")
        ) : '')) . "
    GROUP BY r.status
";
$countParams = [];
if (isset($params[':user_id'])) {
    $countParams[':user_id'] = $params[':user_id'];
}
if (isset($params[':user_id2'])) {
    $countParams[':user_id2'] = $params[':user_id2'];
}
$statusCounts = [];
try {
    $countsResult = $db->fetchAll($countQuery, $countParams);
    foreach ($countsResult as $row) {
        $statusCounts[$row['status']] = $row['count'];
    }
} catch (Exception $e) {
    // If the count query fails, just show zeros -- not the end of the world
}

$totalCount = array_sum($statusCounts);

// ============================================================================
// ANNUAL REVIEW STATUS HELPER
// Calculates whether a vendor's annual review is overdue, due soon, or OK.
// Same logic as the annual reviews list page, but returns a simpler object
// with a link flag for making the badge clickable.
// ============================================================================
function calculateAnnualReviewStatus($vendor) {
    if ($vendor['status'] !== 'approved') {
        return ['label' => t('vendor-onboarding-list.na'), 'class' => 'na', 'days' => null];
    }

    // Figure out the due date -- stored > last review + 365 > approval + 365
    $dueDate = null;
    if (!empty($vendor['last_annual_review_due'])) {
        $dueDate = $vendor['last_annual_review_due'];
    } elseif (!empty($vendor['last_annual_review'])) {
        $dueDate = date('Y-m-d', strtotime($vendor['last_annual_review'] . ' +365 days'));
    } else {
        $approvalDate = $vendor['submitted_at'] ?? $vendor['created_at'];
        if (!empty($approvalDate)) {
            $dueDate = date('Y-m-d', strtotime($approvalDate . ' +365 days'));
        }
    }

    if (!$dueDate) {
        return ['label' => t('vendor-onboarding-list.na'), 'class' => 'na', 'days' => null];
    }

    $now = strtotime(date('Y-m-d'));
    $due = strtotime($dueDate);
    $daysDiff = floor(($due - $now) / 86400);

    if ($daysDiff < 0) {
        // Overdue -- red badge, clickable link to the review form
        $daysOverdue = abs($daysDiff);
        return [
            'label' => $daysOverdue . t('vendor-onboarding-list.review_days_overdue_suffix'),
            'class' => 'overdue',
            'days' => $daysDiff,
            'link' => true
        ];
    } elseif ($daysDiff <= 30) {
        // Due soon -- orange badge, also clickable
        return [
            'label' => t('vendor-onboarding-list.review_due_in_prefix') . $daysDiff . t('vendor-onboarding-list.review_days_suffix'),
            'class' => 'due-soon',
            'days' => $daysDiff,
            'link' => true
        ];
    } else {
        // All good -- green badge, no link needed
        return [
            'label' => t('vendor-onboarding-list.review_ok'),
            'class' => 'ok',
            'days' => $daysDiff,
            'link' => false
        ];
    }
}

// ============================================================================
// NAVIGATION PERMISSION FLAGS
// Same pattern as index.php -- controls what shows up in the sidebar
// ============================================================================
$session = Session::getInstance();
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isProcurement = hasGroup('procurement');
$isCyberTPRM = hasGroup('cyber_tprm');
$isStakeholderGroup = hasGroup('stakeholder');
$isStakeholderOnly = $isStakeholderGroup && !$isAdmin && !$isProcurement && !$isCyberTPRM;

$showFairModule = (hasPermission('analysis.create') ||
                  hasPermission('analysis.read') ||
                  $isCyberTPRM ||
                  $isAdmin) && !$isStakeholderOnly;

$showSRSModule = ($isCyberTPRM || $isAdmin) && !$isStakeholderOnly;

// ============================================================================
// CONTRACT CASE POST HANDLERS
// Must run BEFORE generateCSRFToken() so validation uses the session token
// from the previous page load (same pattern as status change handler above).
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($isAdmin || $isProcurement || $isCyberTPRM)) {
    if (isset($_POST['csrf_token']) && $security->validateCSRFToken($_POST['csrf_token'])) {
        // Reassign contract case (auto-creates case if needed)
        if (isset($_POST['reassign_contract_case'])) {
            $caseId = intval($_POST['case_id'] ?? 0);
            $documentId = intval($_POST['document_id'] ?? 0);
            $newAssigneeId = intval($_POST['new_assignee_id'] ?? 0);
            if ($newAssigneeId > 0 && ($caseId > 0 || $documentId > 0)) {
                try {
                    require_once __DIR__ . '/includes/classes/CyberTodoService.php';
                    $todoService = new CyberTodoService($db, $srsService);

                    // Auto-create case if none exists
                    if ($caseId <= 0 && $documentId > 0) {
                        $caseId = _ensureContractCase($db, $documentId, $auth, $user['id']);
                    }

                    $updatedCase = $todoService->reassignCase($caseId, $newAssigneeId, $user['id']);

                    // Audit log the case assignment
                    $auth->audit($user['id'], 'case_assign', 'cyber_todo_activities', $caseId, [
                        'new' => [
                            'assigned_to' => $newAssigneeId,
                            'assigned_to_name' => $updatedCase['assigned_to_name'] ?? null,
                            'case_title' => $updatedCase['title'] ?? null,
                            'vendor_name' => $updatedCase['vendor_name'] ?? null,
                        ]
                    ]);

                    // Try to send notification email
                    try {
                        require_once __DIR__ . '/includes/classes/EmailService.php';
                        require_once __DIR__ . '/includes/classes/Encryption.php';
                        $encryption = new Encryption();
                        $emailService = new EmailService($db, $encryption);
                        if ($emailService->isEnabled() && $updatedCase && !empty($updatedCase['assigned_to_email'])) {
                            $emailService->sendCaseAssignmentNotification($updatedCase, $updatedCase['assigned_to_email'], $user['full_name']);
                        }
                    } catch (Exception $e) {
                        error_log('Contract case assignment email failed: ' . $e->getMessage());
                    }

                    header('Location: vendor-onboarding-list.php?view=expiring_contracts&msg=reassigned');
                    exit;
                } catch (Exception $e) {
                    error_log('Reassign contract case error: ' . $e->getMessage());
                }
            }
        }

        // Update contract case status (close/reopen, auto-creates case if needed)
        if (isset($_POST['update_contract_case_status'])) {
            $caseId = intval($_POST['case_id'] ?? 0);
            $documentId = intval($_POST['document_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';
            if (in_array($newStatus, ['closed', 'open']) && ($caseId > 0 || $documentId > 0)) {
                try {
                    require_once __DIR__ . '/includes/classes/CyberTodoService.php';
                    $todoService = new CyberTodoService($db, $srsService);

                    // Auto-create case if none exists
                    if ($caseId <= 0 && $documentId > 0) {
                        $caseId = _ensureContractCase($db, $documentId, $auth, $user['id']);
                    }

                    $todoService->updateActivityStatus($caseId, $newStatus, $user['id']);

                    // Audit log the case status change
                    $auth->audit($user['id'], 'case_status_update', 'cyber_todo_activities', $caseId, [
                        'new' => ['status' => $newStatus]
                    ]);

                    header('Location: vendor-onboarding-list.php?view=expiring_contracts&msg=' . ($newStatus === 'closed' ? 'closed' : 'reopened'));
                    exit;
                } catch (Exception $e) {
                    error_log('Update contract case status error: ' . $e->getMessage());
                }
            }
        }
    }
}

// Generate CSRF token AFTER all POST handlers so validation works correctly
$csrfToken = $security->generateCSRFToken();

// Calculate task count for the "Vendor Tasks" tab badge in the sidebar
$taskCount = 0;
$showOnboarding = $canReadAll || $canReadOwn || $canReadAssigned;
if ($showOnboarding) {
    $taskCountParams = [];
    $taskCountWhere = [];

    // Same permission pattern -- filter assessments based on what the user can see
    if ($canReadAll) {
        // Can see all assessments -- no filter
    } elseif ($canReadOwn && $canReadAssigned) {
        $taskCountWhere[] = "(va.created_by = :tc_user_id OR EXISTS (
            SELECT 1 FROM vendor_onboarding_stakeholders s
            WHERE s.request_id = va.vendor_request_id AND s.user_id = :tc_user_id2
        ))";
        $taskCountParams[':tc_user_id'] = $user['id'];
        $taskCountParams[':tc_user_id2'] = $user['id'];
    } elseif ($canReadOwn) {
        $taskCountWhere[] = "va.created_by = :tc_user_id";
        $taskCountParams[':tc_user_id'] = $user['id'];
    } elseif ($canReadAssigned) {
        $taskCountWhere[] = "EXISTS (
            SELECT 1 FROM vendor_onboarding_stakeholders s
            INNER JOIN vendor_onboarding_requests vor ON s.request_id = vor.id
            WHERE va.vendor_request_id = vor.id AND s.user_id = :tc_user_id
        )";
        $taskCountParams[':tc_user_id'] = $user['id'];
    }

    $taskCountWhereClause = !empty($taskCountWhere) ? 'WHERE ' . implode(' AND ', $taskCountWhere) : '';

    $taskCountQuery = "SELECT COUNT(*) as count FROM vendor_assessments va {$taskCountWhereClause}";
    try {
        $taskCountResult = $db->fetchOne($taskCountQuery, $taskCountParams);
        $taskCount = $taskCountResult['count'] ?? 0;
    } catch (Exception $e) {
        error_log('Task count error: ' . $e->getMessage());
    }
}

// Case management counts for sidebar
$openCasesCount = 0;
$closedCasesCount = 0;
$assignedToMeCasesCount = 0;
try {
    if ($isAdmin || $isProcurement || $isCyberTPRM) {
        $openCasesCount = intval($db->fetchOne(
            "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND status IN ('open','in_progress') AND (parent_id IS NULL OR parent_id = 0)"
        )['c'] ?? 0);
        $closedCasesCount = intval($db->fetchOne(
            "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND status='closed' AND (parent_id IS NULL OR parent_id = 0)"
        )['c'] ?? 0);
    } elseif ($isStakeholderOnly) {
        $stakeholderVendorIds = $db->fetchAll(
            "SELECT request_id FROM vendor_onboarding_stakeholders WHERE user_id = :uid",
            [':uid' => $user['id']]
        );
        if (!empty($stakeholderVendorIds)) {
            $ids = array_column($stakeholderVendorIds, 'request_id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $openCasesCount = intval($db->fetchOne(
                "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND reference_id IN ($placeholders) AND status IN ('open','in_progress') AND (parent_id IS NULL OR parent_id = 0)", $ids
            )['c'] ?? 0);
            $closedCasesCount = intval($db->fetchOne(
                "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND reference_id IN ($placeholders) AND status='closed' AND (parent_id IS NULL OR parent_id = 0)", $ids
            )['c'] ?? 0);
        }
    }
    // Assigned to me count (for all roles)
    $assignedToMeCasesCount = intval($db->fetchOne(
        "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND assigned_to = :uid AND status IN ('open','in_progress') AND (parent_id IS NULL OR parent_id = 0)",
        [':uid' => $user['id']]
    )['c'] ?? 0);
} catch (Exception $e) {}

// Expiring contract count (from vendor_documents directly — no cron dependency)
$expiringContractCasesCount = 0;
try {
    if ($isAdmin || $isProcurement || $isCyberTPRM) {
        $expiringContractCasesCount = intval($db->fetchOne(
            "SELECT COUNT(*) as c FROM vendor_documents
             WHERE document_type = 'contract' AND is_active = 1
               AND contract_expiration_date IS NOT NULL
               AND contract_expiration_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"
        )['c'] ?? 0);
    }
} catch (Exception $e) {}

// Current view mode
$currentView = isset($_GET['view']) ? $_GET['view'] : '';

// Fetch cases for case views
$casesList = [];
if (in_array($currentView, ['open_cases', 'closed_cases', 'assigned_to_me'])) {
    try {
        if ($currentView === 'assigned_to_me') {
            // Assigned to me - direct query by assigned_to
            $casesList = $db->fetchAll(
                "SELECT a.*, u.full_name as created_by_name, au.full_name as assigned_to_name,
                        v.vendor_name, v.id as vendor_id
                 FROM cyber_todo_activities a
                 LEFT JOIN users u ON a.created_by = u.id
                 LEFT JOIN users au ON a.assigned_to = au.id
                 LEFT JOIN vendor_onboarding_requests v ON a.reference_id = v.id
                 WHERE a.reference_type = 'vendor_onboarding_requests'
                   AND a.assigned_to = :uid
                   AND a.status IN ('open','in_progress')
                   AND (a.parent_id IS NULL OR a.parent_id = 0)
                 ORDER BY a.due_date ASC, a.created_at DESC",
                [':uid' => $user['id']]
            );
        } else {
            $caseVendorIds = [];
            if ($isStakeholderOnly) {
                $svRows = $db->fetchAll(
                    "SELECT request_id FROM vendor_onboarding_stakeholders WHERE user_id = :uid",
                    [':uid' => $user['id']]
                );
                $caseVendorIds = array_column($svRows, 'request_id');
            } elseif ($canReadAll) {
                // Admin/cyber_tprm/procurement - get all vendor IDs with cases
                $allRows = $db->fetchAll("SELECT DISTINCT reference_id FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests'");
                $caseVendorIds = array_column($allRows, 'reference_id');
            }

            if (!empty($caseVendorIds)) {
                $placeholders = implode(',', array_fill(0, count($caseVendorIds), '?'));
                $statusCondition = $currentView === 'open_cases' ? "AND a.status IN ('open','in_progress')" : "AND a.status = 'closed'";
                $casesList = $db->fetchAll(
                    "SELECT a.*, u.full_name as created_by_name, au.full_name as assigned_to_name,
                            v.vendor_name, v.id as vendor_id
                     FROM cyber_todo_activities a
                     LEFT JOIN users u ON a.created_by = u.id
                     LEFT JOIN users au ON a.assigned_to = au.id
                     LEFT JOIN vendor_onboarding_requests v ON a.reference_id = v.id
                     WHERE a.reference_type = 'vendor_onboarding_requests'
                       AND a.reference_id IN ($placeholders)
                       AND (a.parent_id IS NULL OR a.parent_id = 0)
                       $statusCondition
                     ORDER BY a.due_date ASC, a.created_at DESC",
                    $caseVendorIds
                );
            }
        }
    } catch (Exception $e) {
        error_log('Cases list error: ' . $e->getMessage());
    }
}

// Fetch expiring contracts from vendor_documents, LEFT JOIN any existing cases
$expiringContractCases = [];
if ($currentView === 'expiring_contracts' && ($isAdmin || $isProcurement || $isCyberTPRM)) {
    try {
        $expiringContractCases = $db->fetchAll(
            "SELECT vd.id as document_id, vd.contract_name, vd.contract_type,
                    vd.contract_expiration_date, vd.vendor_request_id,
                    r.vendor_name, r.id as vendor_id,
                    DATEDIFF(vd.contract_expiration_date, CURDATE()) as days_until_expiry,
                    a.id as case_id, a.status as case_status, a.assigned_to,
                    au.full_name as assigned_to_name, au.email as assigned_to_email
             FROM vendor_documents vd
             JOIN vendor_onboarding_requests r ON vd.vendor_request_id = r.id
             LEFT JOIN cyber_todo_activities a ON a.todo_type = 'contract_expiry'
                 AND a.reference_type = 'vendor_onboarding_requests'
                 AND a.reference_id = vd.vendor_request_id
                 AND (a.parent_id IS NULL OR a.parent_id = 0)
                 AND a.metadata LIKE CONCAT('%\"document_id\":', vd.id, '%')
             LEFT JOIN users au ON a.assigned_to = au.id
             WHERE vd.document_type = 'contract'
               AND vd.is_active = 1
               AND vd.contract_expiration_date IS NOT NULL
               AND vd.contract_expiration_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
             ORDER BY vd.contract_expiration_date ASC"
        );
    } catch (Exception $e) {
        error_log('Expiring contracts list error: ' . $e->getMessage());
    }
}

/**
 * Auto-create a cyber_todo_activities case for a contract document if none exists.
 * Returns the case ID (existing or newly created).
 */
function _ensureContractCase($db, int $documentId, $auth = null, $userId = 0): int {
    $doc = $db->fetchOne(
        "SELECT vd.id, vd.contract_name, vd.contract_type, vd.contract_expiration_date,
                vd.vendor_request_id, r.vendor_name
         FROM vendor_documents vd
         JOIN vendor_onboarding_requests r ON vd.vendor_request_id = r.id
         WHERE vd.id = :id",
        [':id' => $documentId]
    );
    if (!$doc) throw new Exception('Contract document not found');

    // Check for existing case
    $existing = $db->fetchOne(
        "SELECT id FROM cyber_todo_activities
         WHERE todo_type = 'contract_expiry'
           AND reference_type = 'vendor_onboarding_requests'
           AND reference_id = :ref_id
           AND (parent_id IS NULL OR parent_id = 0)
           AND metadata LIKE :doc_pattern",
        [':ref_id' => $doc['vendor_request_id'], ':doc_pattern' => '%"document_id":' . $documentId . '%']
    );
    if ($existing) return (int)$existing['id'];

    $contractName = $doc['contract_name'] ?: 'Unnamed Contract';
    $daysUntil = (int)floor((strtotime($doc['contract_expiration_date']) - time()) / 86400);
    $title = $daysUntil < 0 ? "Contract Expired: {$contractName}" : "Contract Expiring: {$contractName}";

    $newId = $db->insert('cyber_todo_activities', [
        'todo_type' => 'contract_expiry',
        'reference_type' => 'vendor_onboarding_requests',
        'reference_id' => $doc['vendor_request_id'],
        'activity_type' => 'reminder',
        'title' => $title,
        'description' => "Vendor: {$doc['vendor_name']}. Contract: {$contractName}. Type: " . ($doc['contract_type'] ?? 'N/A') . ". Expires: {$doc['contract_expiration_date']}.",
        'metadata' => json_encode([
            'document_id' => $documentId,
            'contract_name' => $contractName,
            'contract_type' => $doc['contract_type'] ?? 'N/A',
            'expiration_date' => $doc['contract_expiration_date'],
        ]),
        'status' => 'open',
        'due_date' => $doc['contract_expiration_date'],
        'created_by' => 0,
    ]);

    // Audit log the case creation
    if ($auth) {
        $auth->audit($userId, 'case_create', 'cyber_todo_activities', (int)$newId, [
            'new' => [
                'todo_type' => 'contract_expiry',
                'title' => $title,
                'vendor_name' => $doc['vendor_name'],
                'contract_name' => $contractName,
                'expiration_date' => $doc['contract_expiration_date'],
            ]
        ]);
    }

    return (int)$newId;
}

?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('vendor-onboarding-list.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
    <style>
        /* Theme CSS variables -- the whole app is themed from the database */
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color']); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color']); ?>;
            --sidebar-width: <?php echo e($theme['nav_width']); ?>px;
        }

        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Roboto', sans-serif; }

        /* Full height page layout with flexbox */
        .page {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .page-header { display: none !important; }

        /* Top Bar - User Menu Only */
        .top-bar {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-shrink: 0;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-menu a {
            color: #333;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 4px;
            background: rgba(255,101,67,0.1);
            transition: background 0.2s;
            font-size: 14px;
        }
        .user-menu a:hover {
            background: rgba(255,101,67,0.2);
        }

        /* Main Layout -- sidebar + content */
        .main-layout {
            display: flex;
            flex: 1 1 auto;
            min-height: 0;
        }

        /* Sidebar -- themed navigation */
        .sidebar {
            width: var(--sidebar-width) !important;
            min-width: var(--sidebar-width) !important;
            max-width: var(--sidebar-width) !important;
            background: var(--nav-fill-color) !important;
            padding: 0;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title {
            color: var(--nav-font-color);
            font-size: 13px;
            font-weight: 500;
            margin-top: 8px;
            opacity: 0.9;
        }

        .sidebar-content {
            flex: 1;
            padding: 20px 0;
            overflow-y: auto;
        }

        .sidebar-section { margin-bottom: 25px; }

        .sidebar-section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--nav-font-color);
            opacity: 0.5;
            padding: 0 20px;
            margin-bottom: 10px;
        }

        .sidebar-nav {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-nav li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 20px;
            color: var(--nav-font-color);
            opacity: 0.85;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }

        .sidebar-nav li a:hover {
            background: rgba(255,255,255,0.1);
            opacity: 1;
            border-left-color: var(--nav-font-color);
        }

        .sidebar-nav li a.active {
            background: rgba(255,255,255,0.15);
            opacity: 1;
            border-left-color: var(--nav-font-color);
            font-weight: 500;
        }

        .sidebar-nav li a .icon {
            font-size: 16px;
            width: 20px;
            text-align: center;
            opacity: 0.9;
        }

        .sidebar-nav li a .badge {
            margin-left: auto;
            background: rgba(255,255,255,0.2);
            color: var(--nav-font-color);
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 10px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 35px 40px;
            background: #f9fafb;
            min-width: 0;
            overflow-y: auto;
        }

        .page-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title { font-size: 22px; font-weight: 600; color: #333; margin: 0; }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: var(--theme-button-color);
            color: white;
        }
        .btn-primary:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }
        .btn-outline {
            background: white;
            color: var(--theme-header-color);
            border: 2px solid var(--theme-header-color);
        }
        .btn-outline:hover {
            background: var(--theme-header-color);
            color: white;
        }

        /* Filter bar and search */
        .filter-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .filter-bar input, .filter-bar select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .filter-bar input { flex: 1; min-width: 200px; }

        /* Status tabs -- pill-shaped filter buttons with counts */
        .status-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .status-tab {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            color: #666;
            background: #f0f0f0;
            transition: all 0.2s;
        }
        .status-tab:hover { background: #e0e0e0; }
        .status-tab.active {
            background: var(--theme-header-color);
            color: white;
        }
        .status-tab .count {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            background: rgba(0,0,0,0.1);
            margin-left: 5px;
            font-size: 11px;
        }
        .status-tab.active .count { background: rgba(255,255,255,0.3); }

        /* Annual review badges in the table -- overdue/due-soon/ok */
        .review-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .review-badge.overdue { background: #dc3545; color: white; }
        .review-badge.due-soon { background: #ff9800; color: white; }
        .review-badge.ok { background: #28a745; color: white; }
        .review-badge.na { background: #e0e0e0; color: #666; }

        /* The main requests table */
        .requests-table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .requests-table th, .requests-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .requests-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            color: #666;
            text-transform: uppercase;
        }
        .requests-table tr:hover { background: #fafafa; }
        .requests-table tr:last-child td { border-bottom: none; }

        .vendor-name {
            font-weight: 500;
            color: var(--theme-header-color);
        }
        .vendor-name a { color: inherit; text-decoration: none; }
        .vendor-name a:hover { text-decoration: underline; }

        /* Status badges -- color-coded pills for each workflow state */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .status-draft { background: #ffc107; color: #000; }
        .status-submitted { background: #17a2b8; color: #fff; }
        .status-in_review { background: #6f42c1; color: #fff; }
        .status-approved { background: #28a745; color: #fff; }
        .status-rejected { background: #dc3545; color: #fff; }
        .status-inactive { background: #6c757d; color: #fff; }
        .status-evaluation { background: #0ea5e9; color: #fff; }

        .meta-info { font-size: 12px; color: #888; }

        .action-links a {
            color: var(--theme-header-color);
            text-decoration: none;
            margin-right: 15px;
            font-size: 13px;
        }
        .action-links a:hover { text-decoration: underline; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
        }
        .empty-state h3 { color: #666; margin-bottom: 10px; font-size: 18px; font-weight: 600; }
        .empty-state p { color: #999; margin-bottom: 20px; }

        .alert {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            font-size: 14px;
        }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

        /* Footer */
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }
        .footer-modern .brand img { max-height: 45px; }

        .preloader { display: none !important; }

        /* Responsive -- Tablet */
        @media (max-width: 992px) {
            .main-layout { flex-direction: column; }
            .sidebar {
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
            }
            .sidebar-brand {
                display: flex;
                align-items: center;
                gap: 15px;
                padding: 15px 20px;
            }
            .sidebar-brand img { max-width: 150px; }
            .sidebar-brand .brand-title { margin-top: 0; }
            .sidebar-content { padding: 10px 0; }
            .sidebar-section { margin-bottom: 10px; }
            .sidebar-section-title { padding: 0 15px; margin-bottom: 8px; }
            .sidebar-nav {
                display: flex;
                flex-wrap: wrap;
                padding: 0 10px;
            }
            .sidebar-nav li { flex: 0 0 auto; }
            .sidebar-nav li a {
                padding: 8px 14px;
                border-radius: 6px;
                margin: 3px;
                border-left: none;
            }
            .sidebar-nav li a:hover,
            .sidebar-nav li a.active {
                border-left: none;
                background: rgba(255,255,255,0.2);
            }
            .main-content { padding: 25px 20px; }
        }

        /* Responsive -- Mobile */
        @media (max-width: 576px) {
            .top-bar {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .top-bar > span { margin-right: 0 !important; }
            .user-menu {
                flex-wrap: wrap;
                justify-content: center;
                gap: 8px;
            }
            .sidebar-brand {
                flex-direction: column;
                text-align: center;
            }
            .sidebar-nav {
                flex-direction: column;
                padding: 0 10px;
            }
            .sidebar-nav li { width: 100%; }
            .sidebar-nav li a {
                margin: 2px 0;
                border-radius: 6px;
            }
            .main-content { padding: 20px 15px; }
            .requests-table { display: block; overflow-x: auto; }
            .filter-bar { flex-direction: column; }
            .filter-bar input { width: 100%; }
            .page-header-row { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <?php renderImpersonationBanner(); ?>
    <div class="page">
        <!-- Top Bar with User Menu -->
        <div class="top-bar">
            <span style="color: #666; margin-right: 15px;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <?php if ($isAdmin): ?>
                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                <?php endif; ?>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <?php
            // Set currentPage based on which view is active
            $_view = $currentView ?? '';
            $_pageMap = [
                'tasks' => 'vendor_onboarding_tasks',
                'open_cases' => 'open_cases',
                'assigned_to_me' => 'assigned_to_me_cases',
                'closed_cases' => 'closed_cases',
                'expiring_contracts' => 'expiring_contracts_cases',
            ];
            $currentPage = $_pageMap[$_view] ?? 'vendor_onboarding';
            include __DIR__ . '/includes/sidebar_nav.php';
            ?>

            <!-- =============================================================
                 MAIN CONTENT AREA
                 Header with action buttons, status tabs, view tabs (Requests
                 vs Tasks), search/filter bar, and the big vendor table.
                 ============================================================= -->
            <main class="main-content">
                <div class="page-header-row">
                    <h1 class="page-title" style="margin: 0;"><?php echo e(t('vendor-onboarding-list.page_heading')); ?></h1>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <?php if ($canExportImport): ?>
                            <a href="vendor-onboarding-export.php<?php echo (!empty($statusFilter) || !empty($searchQuery)) ? '?' . http_build_query(array_filter(['status' => $statusFilter, 'search' => $searchQuery])) : ''; ?>" class="btn btn-outline" title="<?php echo e(t('vendor-onboarding-list.export_to_csv_title')); ?>"><?php echo e(t('vendor-onboarding-list.export_csv')); ?></a>
                            <a href="vendor-onboarding-import.php" class="btn btn-outline" title="<?php echo e(t('vendor-onboarding-list.import_from_csv_title')); ?>"><?php echo e(t('vendor-onboarding-list.import_csv')); ?></a>
                        <?php endif; ?>
                        <?php if ($canCreate): ?>
                            <a href="vendor-onboarding.php" class="btn btn-primary">+ <?php echo e(t('vendor-onboarding-list.new_request')); ?></a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php // Flash messages from redirects ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo e($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>

                <!-- Status Tabs -- filter by workflow state -->
                <div class="status-tabs">
                    <a href="vendor-onboarding-list.php<?php echo !empty($searchQuery) ? '?search=' . urlencode($searchQuery) : ''; ?>"
                       class="status-tab <?php echo empty($statusFilter) ? 'active' : ''; ?>">
                        <?php echo e(t('vendor-onboarding-list.tab_all')); ?> <span class="count"><?php echo $totalCount; ?></span>
                    </a>
                    <?php
                    // The "Review" pill combines In Review + AI Review into one tab.
                    $statusCounts['review'] = ($statusCounts['in_review'] ?? 0) + ($statusCounts['ai_review'] ?? 0);
                    // Only show status tabs that have records (or are currently selected)
                    $statuses = [
                        'draft' => t('vendor-onboarding-list.status_draft'),
                        'submitted' => t('vendor-onboarding-list.status_submitted'),
                        'review' => t('vendor-onboarding-list.status_review'),
                        'evaluation' => t('vendor-onboarding-list.status_evaluation'),
                        'approved' => t('vendor-onboarding-list.status_approved'),
                        'rejected' => t('vendor-onboarding-list.status_rejected'),
                        'inactive' => t('vendor-onboarding-list.status_inactive')
                    ];
                    foreach ($statuses as $key => $label):
                        $count = $statusCounts[$key] ?? 0;
                        if ($count > 0 || $statusFilter === $key):
                    ?>
                        <a href="vendor-onboarding-list.php?status=<?php echo $key; ?><?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>"
                           class="status-tab <?php echo $statusFilter === $key ? 'active' : ''; ?>">
                            <?php echo e($label); ?> <span class="count"><?php echo $count; ?></span>
                        </a>
                    <?php
                        endif;
                    endforeach;
                    ?>
                </div>

                <!-- View Tabs -- toggle between Vendor Requests, Tasks, and Cases -->
                <div class="status-tabs" style="margin-bottom: 20px;">
                    <a href="vendor-onboarding-list.php"
                       class="status-tab <?php echo empty($currentView) ? 'active' : ''; ?>">
                        <?php echo e(t('vendor-onboarding-list.tab_vendor_requests')); ?>
                    </a>
                    <a href="vendor-onboarding-list.php?view=tasks"
                       class="status-tab <?php echo $currentView === 'tasks' ? 'active' : ''; ?>">
                        <?php echo e(t('vendor-onboarding-list.tab_vendor_tasks')); ?> <span class="count"><?php echo $taskCount; ?></span>
                    </a>
                    <a href="vendor-onboarding-list.php?view=open_cases"
                       class="status-tab <?php echo $currentView === 'open_cases' ? 'active' : ''; ?>">
                        <?php echo e(t('vendor-onboarding-list.tab_open_cases')); ?> <?php if ($openCasesCount > 0): ?><span class="count"><?php echo $openCasesCount; ?></span><?php endif; ?>
                    </a>
                    <a href="vendor-onboarding-list.php?view=assigned_to_me"
                       class="status-tab <?php echo $currentView === 'assigned_to_me' ? 'active' : ''; ?>">
                        <?php echo e(t('vendor-onboarding-list.tab_assigned_to_me')); ?> <?php if ($assignedToMeCasesCount > 0): ?><span class="count"><?php echo $assignedToMeCasesCount; ?></span><?php endif; ?>
                    </a>
                    <a href="vendor-onboarding-list.php?view=closed_cases"
                       class="status-tab <?php echo $currentView === 'closed_cases' ? 'active' : ''; ?>">
                        <?php echo e(t('vendor-onboarding-list.tab_closed_cases')); ?> <?php if ($closedCasesCount > 0): ?><span class="count"><?php echo $closedCasesCount; ?></span><?php endif; ?>
                    </a>
                    <?php if ($isAdmin || $isProcurement || $isCyberTPRM): ?>
                    <a href="vendor-onboarding-list.php?view=expiring_contracts"
                       class="status-tab <?php echo $currentView === 'expiring_contracts' ? 'active' : ''; ?>">
                        <?php echo ($isProcurement && !$isAdmin && !$isCyberTPRM) ? e(t('vendor-onboarding-list.tab_contracts')) : e(t('vendor-onboarding-list.tab_expiring_contracts')); ?> <?php if ($expiringContractCasesCount > 0): ?><span class="count"><?php echo $expiringContractCasesCount; ?></span><?php endif; ?>
                    </a>
                    <?php endif; ?>
                </div>

                <?php if ($currentView === 'tasks'): ?>
                    <?php
                    // Load the Vendor Tasks sub-view (separate partial files)
                    require_once 'includes/partials/vendor-tasks-data.php';
                    require_once 'includes/partials/vendor-tasks-view.php';
                    ?>

                <?php elseif (in_array($currentView, ['open_cases', 'closed_cases', 'assigned_to_me'])): ?>
                    <!-- Cases View -->
                    <?php if (empty($casesList)): ?>
                        <div class="empty-state">
                            <?php
                            $viewLabels = ['open_cases' => 'open', 'closed_cases' => 'closed', 'assigned_to_me' => 'assigned to you'];
                            $viewLabel = $viewLabels[$currentView] ?? $currentView;
                            ?>
                            <h3>No <?php echo $viewLabel; ?> cases found</h3>
                            <p>There are no <?php echo $viewLabel; ?> cases<?php echo $currentView !== 'assigned_to_me' ? ' for your vendors' : ''; ?>.</p>
                        </div>
                    <?php else: ?>
                        <div style="margin-bottom: 15px;">
                            <input type="text" id="casesSearch" placeholder="<?php echo e(t('vendor-onboarding-list.cases_search_placeholder')); ?>" autocomplete="off"
                                   style="width: 100%; max-width: 400px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                        </div>
                        <table class="requests-table" id="casesTable">
                            <thead>
                                <tr>
                                    <th><?php echo e(t('vendor-onboarding-list.col_vendor')); ?></th>
                                    <th><?php echo e(t('vendor-onboarding-list.col_title')); ?></th>
                                    <th><?php echo e(t('vendor-onboarding-list.col_opened_by')); ?></th>
                                    <th><?php echo e(t('vendor-onboarding-list.col_assigned_to')); ?></th>
                                    <th><?php echo e(t('vendor-onboarding-list.col_due_date')); ?></th>
                                    <th><?php echo e(t('vendor-onboarding-list.col_status')); ?></th>
                                    <th><?php echo e(t('vendor-onboarding-list.col_created')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($casesList as $case):
                                    $caseStatusColors = [
                                        'open' => 'background: #dbeafe; color: #1e40af',
                                        'in_progress' => 'background: #fef3c7; color: #92400e',
                                        'closed' => 'background: #dcfce7; color: #166534',
                                        'deferred' => 'background: #f3f4f6; color: #6b7280',
                                    ];
                                    $caseStyle = $caseStatusColors[$case['status']] ?? 'background: #f3f4f6; color: #333';
                                    $isOverdue = !empty($case['due_date']) && $case['status'] !== 'closed' && strtotime($case['due_date']) < strtotime('today');
                                ?>
                                <tr>
                                    <td class="vendor-name">
                                        <a href="vendor-onboarding.php?id=<?php echo intval($case['vendor_id']); ?>&tab=notes">
                                            <?php echo e($case['vendor_name'] ?: t('vendor-onboarding-list.unknown_vendor')); ?>
                                        </a>
                                    </td>
                                    <td><?php echo e($case['title']); ?></td>
                                    <td><?php echo e($case['created_by_name'] ?: t('vendor-onboarding-list.unknown')); ?></td>
                                    <td><?php echo e($case['assigned_to_name'] ?: '-'); ?></td>
                                    <td>
                                        <?php if (!empty($case['due_date'])): ?>
                                            <?php echo date('M j, Y', strtotime($case['due_date'])); ?>
                                            <?php if ($isOverdue): ?>
                                                <span style="color: #dc2626; font-weight: 500; font-size: 11px;"><?php echo e(t('vendor-onboarding-list.overdue_paren')); ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 500; <?php echo $caseStyle; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $case['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="meta-info">
                                            <?php echo date('M j, Y', strtotime($case['created_at'])); ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                <?php elseif ($currentView === 'expiring_contracts' && ($isAdmin || $isProcurement || $isCyberTPRM)): ?>
                    <!-- Expiring Contracts View -->
                    <?php
                    // Pre-fetch assign-to users once (fix: use group_name not name)
                    $assignUsers = [];
                    try {
                        $assignUsers = $db->fetchAll("SELECT DISTINCT u.id, u.full_name FROM users u JOIN user_acl_groups uag ON u.id = uag.user_id JOIN acl_groups ag ON uag.group_id = ag.id WHERE ag.group_name IN ('procurement','administrator','cyber_tprm') AND u.is_active = 1 ORDER BY u.full_name");
                    } catch (Exception $e) {}

                    // Calculate stats from document data
                    $ecTotal = count($expiringContractCases);
                    $ecExpiringSoon = 0; $ecExpired = 0; $ecAssigned = 0; $ecClosed = 0;
                    foreach ($expiringContractCases as $ec) {
                        if (($ec['case_status'] ?? '') === 'closed') { $ecClosed++; continue; }
                        $d = (int)($ec['days_until_expiry'] ?? 0);
                        if ($d < 0) $ecExpired++;
                        else $ecExpiringSoon++;
                        if (!empty($ec['assigned_to'])) $ecAssigned++;
                    }
                    ?>

                    <?php
                    $msgKey = $_GET['msg'] ?? '';
                    $msgs = ['reassigned' => t('vendor-onboarding-list.case_reassigned_success'), 'closed' => t('vendor-onboarding-list.case_closed'), 'reopened' => t('vendor-onboarding-list.case_reopened')];
                    if (isset($msgs[$msgKey])):
                    ?>
                    <div style="padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 13px;
                        <?php if ($msgKey === 'reassigned'): ?>background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0;<?php endif; ?>
                        <?php if ($msgKey === 'closed'): ?>background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;<?php endif; ?>
                        <?php if ($msgKey === 'reopened'): ?>background: #fef3c7; color: #92400e; border: 1px solid #fde68a;<?php endif; ?>">
                        <?php echo e($msgs[$msgKey]); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Stat badges -->
                    <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
                        <div style="background: #f3f4f6; border-radius: 8px; padding: 12px 18px; min-width: 100px; text-align: center;">
                            <div style="font-size: 22px; font-weight: 700; color: #374151;"><?php echo $ecTotal; ?></div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo e(t('vendor-onboarding-list.stat_total')); ?></div>
                        </div>
                        <div style="background: #fef3c7; border-radius: 8px; padding: 12px 18px; min-width: 100px; text-align: center;">
                            <div style="font-size: 22px; font-weight: 700; color: #92400e;"><?php echo $ecExpiringSoon; ?></div>
                            <div style="font-size: 11px; color: #92400e; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo e(t('vendor-onboarding-list.stat_expiring_soon')); ?></div>
                        </div>
                        <div style="background: #fee2e2; border-radius: 8px; padding: 12px 18px; min-width: 100px; text-align: center;">
                            <div style="font-size: 22px; font-weight: 700; color: #991b1b;"><?php echo $ecExpired; ?></div>
                            <div style="font-size: 11px; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo e(t('vendor-onboarding-list.stat_expired')); ?></div>
                        </div>
                        <div style="background: #dbeafe; border-radius: 8px; padding: 12px 18px; min-width: 100px; text-align: center;">
                            <div style="font-size: 22px; font-weight: 700; color: #1e40af;"><?php echo $ecAssigned; ?></div>
                            <div style="font-size: 11px; color: #1e40af; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo e(t('vendor-onboarding-list.stat_assigned')); ?></div>
                        </div>
                        <div style="background: #d1fae5; border-radius: 8px; padding: 12px 18px; min-width: 100px; text-align: center;">
                            <div style="font-size: 22px; font-weight: 700; color: #065f46;"><?php echo $ecClosed; ?></div>
                            <div style="font-size: 11px; color: #065f46; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo e(t('vendor-onboarding-list.stat_closed')); ?></div>
                        </div>
                    </div>

                    <?php if (empty($expiringContractCases)): ?>
                        <div class="empty-state">
                            <h3><?php echo e(t('vendor-onboarding-list.no_expiring_contracts')); ?></h3>
                            <p><?php echo e(t('vendor-onboarding-list.no_expiring_contracts_desc')); ?></p>
                        </div>
                    <?php else: ?>
                        <!-- Contract cards -->
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($expiringContractCases as $ec):
                            $ecContractName = $ec['contract_name'] ?: t('vendor-onboarding-list.unnamed_contract');
                            $ecContractType = $ec['contract_type'] ?? t('vendor-onboarding-list.na');
                            $ecExpDate = $ec['contract_expiration_date'] ?? '';
                            $ecDaysUntil = (int)($ec['days_until_expiry'] ?? 0);
                            $ecIsExpired = $ecDaysUntil < 0;
                            $ecIsClosed = (($ec['case_status'] ?? '') === 'closed');
                            $ecCaseId = $ec['case_id'] ?? 0;
                        ?>
                        <div style="background: white; border-radius: 8px; border: 1px solid <?php echo $ecIsClosed ? '#d1d5db' : ($ecIsExpired ? '#fca5a5' : '#fde68a'); ?>; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); <?php echo $ecIsClosed ? 'opacity: 0.7;' : ''; ?>">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                                <div style="flex: 1; min-width: 200px;">
                                    <div style="font-weight: 600; font-size: 15px; color: #1f2937; margin-bottom: 4px;">
                                        <?php echo e($ec['vendor_name'] ?? t('vendor-onboarding-list.unknown_vendor')); ?>
                                    </div>
                                    <div style="font-size: 13px; color: #6b7280; margin-bottom: 8px;">
                                        <?php echo e($ecContractName); ?>
                                        <span style="color: #9ca3af;"> &middot; </span>
                                        <?php echo e($ecContractType); ?>
                                    </div>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                        <?php if ($ecIsClosed): ?>
                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46;"><?php echo e(t('vendor-onboarding-list.badge_closed')); ?></span>
                                        <?php elseif ($ecIsExpired): ?>
                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #fee2e2; color: #991b1b;"><?php echo e(t('vendor-onboarding-list.expired_before')); ?> <?php echo abs($ecDaysUntil); ?> <?php echo e(t('vendor-onboarding-list.days_ago')); ?></span>
                                        <?php elseif ($ecDaysUntil <= 0): ?>
                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #fee2e2; color: #991b1b;"><?php echo e(t('vendor-onboarding-list.expires_today')); ?></span>
                                        <?php else: ?>
                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #fef3c7; color: #92400e;"><?php echo e(t('vendor-onboarding-list.expires_in')); ?> <?php echo $ecDaysUntil; ?> <?php echo e(t('vendor-onboarding-list.days')); ?></span>
                                        <?php endif; ?>

                                        <?php if (!empty($ecExpDate)): ?>
                                            <span style="font-size: 12px; color: #9ca3af;"><?php echo date('M j, Y', strtotime($ecExpDate)); ?></span>
                                        <?php endif; ?>

                                        <?php if (!empty($ec['assigned_to_name'])): ?>
                                            <span style="font-size: 12px; color: #6b7280;">&#128100; <?php echo e($ec['assigned_to_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                    <!-- Reassign dropdown -->
                                    <form method="POST" style="display: inline-flex; gap: 4px; align-items: center;">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="case_id" value="<?php echo (int)$ecCaseId; ?>">
                                        <input type="hidden" name="document_id" value="<?php echo (int)$ec['document_id']; ?>">
                                        <select name="new_assignee_id" style="padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; max-width: 140px;">
                                            <option value=""><?php echo e(t('vendor-onboarding-list.assign_to')); ?></option>
                                            <?php foreach ($assignUsers as $au): ?>
                                            <option value="<?php echo $au['id']; ?>" <?php echo ($ec['assigned_to'] == $au['id']) ? 'selected' : ''; ?>><?php echo e($au['full_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="reassign_contract_case" class="btn btn-outline" style="padding: 4px 10px; font-size: 11px;"><?php echo e(t('vendor-onboarding-list.assign')); ?></button>
                                    </form>

                                    <!-- Close/Reopen -->
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="case_id" value="<?php echo (int)$ecCaseId; ?>">
                                        <input type="hidden" name="document_id" value="<?php echo (int)$ec['document_id']; ?>">
                                        <?php if ($ecIsClosed): ?>
                                            <input type="hidden" name="new_status" value="open">
                                            <button type="submit" name="update_contract_case_status" class="btn btn-outline" style="padding: 4px 10px; font-size: 11px; color: #92400e; border-color: #fde68a;"><?php echo e(t('vendor-onboarding-list.reopen')); ?></button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="closed">
                                            <button type="submit" name="update_contract_case_status" class="btn btn-outline" style="padding: 4px 10px; font-size: 11px; color: #065f46; border-color: #a7f3d0;"><?php echo e(t('vendor-onboarding-list.close')); ?></button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>

                <!-- Search/Filter Bar -->
                <form method="GET" class="filter-bar">
                    <?php if (!empty($statusFilter)): ?>
                        <input type="hidden" name="status" value="<?php echo e($statusFilter); ?>">
                    <?php endif; ?>
                    <input type="text" name="search" placeholder="<?php echo e(t('vendor-onboarding-list.search_placeholder')); ?>"
                           value="<?php echo e($searchQuery); ?>">
                    <button type="submit" class="btn btn-outline"><?php echo e(t('vendor-onboarding-list.search')); ?></button>
                    <?php if (!empty($searchQuery) || !empty($statusFilter)): ?>
                        <a href="vendor-onboarding-list.php" class="btn btn-outline"><?php echo e(t('vendor-onboarding-list.clear')); ?></a>
                    <?php endif; ?>
                </form>

                <?php if (empty($requests)): ?>
                    <!-- Empty state -- no requests match the current filters -->
                    <div class="empty-state">
                        <h3><?php echo e(t('vendor-onboarding-list.no_requests_found')); ?></h3>
                        <p>
                            <?php if (!empty($searchQuery) || !empty($statusFilter)): ?>
                                <?php echo e(t('vendor-onboarding-list.try_adjusting')); ?>
                            <?php else: ?>
                                <?php echo e(t('vendor-onboarding-list.get_started')); ?>
                            <?php endif; ?>
                        </p>
                        <?php if ($canCreate): ?>
                            <a href="vendor-onboarding.php" class="btn btn-primary"><?php echo e(t('vendor-onboarding-list.create_new_request')); ?></a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- The big table -- vendor name, type, status, tier/SRS, creator, dates, review status, actions -->
                    <?php
                    // Check if any draft rows exist on this page (for showing select-all)
                    $hasDraftsOnPage = $canDelete && count(array_filter($requests, fn($r) => $r['status'] === 'draft')) > 0;
                    // Check if any In Review / AI Review rows exist (for the procurement-update select-all)
                    $hasReviewOnPage = $showProcurementSelect && count(array_filter($requests, fn($r) => in_array($r['status'], ['in_review', 'ai_review'], true))) > 0;
                    ?>
                    <table class="requests-table" id="vendorTable">
                        <thead>
                            <tr>
                                <?php if ($canDelete || $showProcurementSelect): ?>
                                <th style="width: 40px; text-align: center;">
                                    <?php if ($hasDraftsOnPage): ?>
                                    <input type="checkbox" id="selectAllDrafts" title="<?php echo e(t('vendor-onboarding-list.select_all_drafts_title')); ?>" style="cursor: pointer;">
                                    <?php elseif ($hasReviewOnPage): ?>
                                    <input type="checkbox" id="selectAllReview" title="<?php echo e(t('vendor-onboarding-list.select_all_vendors_title')); ?>" style="cursor: pointer;">
                                    <?php endif; ?>
                                </th>
                                <?php endif; ?>
                                <?php $sortableTh('vendor_name', t('vendor-onboarding-list.col_vendor')); ?>
                                <?php $sortableTh('vendor_type', t('vendor-onboarding-list.col_type')); ?>
                                <?php $sortableTh('status', t('vendor-onboarding-list.col_status')); ?>
                                <?php if ($canExportImport): ?>
                                <th><?php echo e(t('vendor-onboarding-list.col_tier_srs')); ?></th>
                                <?php endif; ?>
                                <?php $sortableTh('stakeholder_name', t('vendor-onboarding-list.col_stakeholder')); ?>
                                <?php $sortableTh('updated_at', t('vendor-onboarding-list.col_last_updated')); ?>
                                <th><?php echo e(t('vendor-onboarding-list.col_annual_review')); ?></th>
                                <th><?php echo e(t('vendor-onboarding-list.col_actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                                <tr>
                                    <?php if ($canDelete || $showProcurementSelect): ?>
                                    <td style="text-align: center;">
                                        <?php if ($canDelete && $req['status'] === 'draft'): ?>
                                        <input type="checkbox" class="draft-checkbox" value="<?php echo $req['id']; ?>" data-vendor="<?php echo e($req['vendor_name'] ?: t('vendor-onboarding-list.untitled')); ?>" style="cursor: pointer;">
                                        <?php elseif ($showProcurementSelect && in_array($req['status'], ['in_review', 'ai_review'], true)): ?>
                                        <input type="checkbox" class="review-checkbox" value="<?php echo $req['id']; ?>" data-vendor="<?php echo e($req['vendor_name'] ?: t('vendor-onboarding-list.untitled')); ?>" style="cursor: pointer;">
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td class="vendor-name">
                                        <a href="vendor-onboarding.php?id=<?php echo $req['id']; ?>">
                                            <?php echo e($req['vendor_name'] ?: t('vendor-onboarding-list.untitled_request')); ?>
                                        </a>
                                        <?php if (!empty($req['note_count'])): ?>
                                        <button type="button" class="notes-pill" data-action="viewNotes" data-request-id="<?php echo (int)$req['id']; ?>" data-vendor="<?php echo e($req['vendor_name'] ?: t('vendor-onboarding-list.untitled_request')); ?>" title="<?php echo e(t('vendor-onboarding-list.view_status_notes_title')); ?>" style="margin-left:6px; display:inline-flex; align-items:center; gap:4px; padding:1px 8px; border-radius:20px; border:1px solid #c7d2fe; background:#eef2ff; color:#3730a3; font-size:11px; font-weight:600; cursor:pointer; vertical-align:middle;">
                                            <?php echo e(t('vendor-onboarding-list.status_notes')); ?> <span style="background:#3730a3; color:#fff; border-radius:10px; padding:0 5px; font-size:10px;"><?php echo (int)$req['note_count']; ?></span>
                                        </button>
                                        <?php endif; ?>
                                        <?php if (!empty($req['expected_procurement_date'])): ?>
                                            <div class="meta-info">
                                                <?php echo e(t('vendor-onboarding-list.target')); ?> <?php echo date('M j, Y', strtotime($req['expected_procurement_date'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo e($req['vendor_type'] ?: '-'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $req['status']; ?>">
                                            <?php echo e($statusLabels[$req['status']] ?? ucfirst(str_replace('_', ' ', $req['status']))); ?>
                                        </span>
                                    </td>
                                    <?php if ($canExportImport): ?>
                                    <td>
                                        <?php // Vendor tier badge -- color-coded by risk level ?>
                                        <?php if (!empty($req['vendor_tier'])): ?>
                                            <span style="font-size: 11px; padding: 2px 8px; border-radius: 10px; background: <?php echo $req['vendor_tier'] === '1' ? '#fef2f2' : ($req['vendor_tier'] === '3' ? '#f0fdf4' : '#fef3c7'); ?>; color: <?php echo $req['vendor_tier'] === '1' ? '#991b1b' : ($req['vendor_tier'] === '3' ? '#166534' : '#92400e'); ?>;">
                                                T<?php echo e($req['vendor_tier']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php // SRS score + grade -- click to see full details ?>
                                        <?php if (!empty($req['current_srs_score'])): ?>
                                            <?php
                                            $srsScore = intval($req['current_srs_score']);
                                            $srsGrade = $srsService->calculateGrade($srsScore);
                                            $gradeColor = $srsGrade === 'A' ? '#166534' : ($srsGrade === 'B' ? '#15803d' : ($srsGrade === 'C' ? '#ca8a04' : ($srsGrade === 'D' ? '#ea580c' : '#dc2626')));
                                            ?>
                                            <a href="vendor-srs-details.php?id=<?php echo $req['id']; ?>" style="text-decoration: none; margin-left: 5px;">
                                                <span style="font-size: 11px; font-weight: 600; color: <?php echo $gradeColor; ?>;">
                                                    <?php echo $srsScore; ?> (<?php echo $srsGrade; ?>)
                                                </span>
                                            </a>
                                        <?php elseif (empty($req['vendor_tier'])): ?>
                                            <span style="color: #999; font-size: 11px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php echo e($req['stakeholder_name'] ?: '-'); ?>
                                    </td>
                                    <td>
                                        <div class="meta-info">
                                            <?php echo date('M j, Y g:i A', strtotime($req['updated_at'])); ?>
                                            <?php if (!empty($req['last_autosave'])): ?>
                                                <br><?php echo e(t('vendor-onboarding-list.autosaved')); ?> <?php echo date('M j, g:i A', strtotime($req['last_autosave'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        // Annual review badge -- overdue links to the review form
                                        $reviewStatus = calculateAnnualReviewStatus($req);
                                        if ($reviewStatus['link']):
                                        ?>
                                            <a href="vendor-annual-review.php?id=<?php echo $req['id']; ?>" style="text-decoration: none;">
                                                <span class="review-badge <?php echo $reviewStatus['class']; ?>">
                                                    <?php echo htmlspecialchars($reviewStatus['label']); ?>
                                                </span>
                                            </a>
                                        <?php else: ?>
                                            <span class="review-badge <?php echo $reviewStatus['class']; ?>">
                                                <?php echo htmlspecialchars($reviewStatus['label']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="action-links">
                                        <?php
                                        $isOwner = ($req['created_by'] == $user['id']);
                                        $isStakeholderOnReq = in_array($req['id'], $userStakeholderRequestIds);
                                        $reqCanEdit = $acl->hasPermission('onboarding.update') ||
                                                     ($acl->hasPermission('onboarding.update_own') && $isOwner) ||
                                                     ($acl->hasPermission('onboarding.update_assigned') && $isStakeholderOnReq);
                                        ?>
                                        <a href="vendor-onboarding.php?id=<?php echo $req['id']; ?>"><?php echo e(t('vendor-onboarding-list.action_view')); ?></a>
                                        <?php if ($reqCanEdit && $req['status'] !== 'inactive'): ?>
                                        <a href="vendor-onboarding.php?id=<?php echo $req['id']; ?>&edit=1"><?php echo e(t('vendor-onboarding-list.action_edit')); ?></a>
                                        <?php endif; ?>
                                        <?php if ($req['status'] === 'draft' && ($canDelete || $isOwner)): ?>
                                        <form method="POST" style="display: inline;" data-confirm="<?php echo e(t('vendor-onboarding-list.confirm_delete_draft')); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                            <input type="hidden" name="action" value="delete_draft">
                                            <button type="submit" style="background: none; border: none; color: #ef4444; font-weight: 500; cursor: pointer; padding: 0; font-size: 13px;"><?php echo e(t('vendor-onboarding-list.action_delete')); ?></button>
                                        </form>
                                        <?php endif; ?>
                                        <?php // Admins/cyber_tprm can approve directly from draft ?>
                                        <?php if ($canApprove && $req['status'] === 'draft'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                            <input type="hidden" name="action" value="set_approved">
                                            <button type="submit" style="background: none; border: none; color: #28a745; font-weight: 500; cursor: pointer; padding: 0; font-size: 13px;"><?php echo e(t('vendor-onboarding-list.action_approve')); ?></button>
                                        </form>
                                        <?php endif; ?>
                                        <?php // Inline status change buttons for admins -- submitted can go to in_review or approved ?>
                                        <?php if ($canApprove && $req['status'] === 'submitted'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                            <input type="hidden" name="action" value="set_in_review">
                                            <button type="submit" style="background: none; border: none; color: #6f42c1; font-weight: 500; cursor: pointer; padding: 0; font-size: 13px;"><?php echo e(t('vendor-onboarding-list.action_in_review')); ?></button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                            <input type="hidden" name="action" value="set_approved">
                                            <button type="submit" style="background: none; border: none; color: #28a745; font-weight: 500; cursor: pointer; padding: 0; font-size: 13px;"><?php echo e(t('vendor-onboarding-list.action_approve')); ?></button>
                                        </form>
                                        <?php endif; ?>
                                        <?php // In-review can only go to approved ?>
                                        <?php if ($canApprove && $req['status'] === 'in_review'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                            <input type="hidden" name="action" value="set_approved">
                                            <button type="submit" style="background: none; border: none; color: #28a745; font-weight: 500; cursor: pointer; padding: 0; font-size: 13px;"><?php echo e(t('vendor-onboarding-list.action_approve')); ?></button>
                                        </form>
                                        <?php endif; ?>
                                        <?php // Eval link -- sets status to evaluation and vendor_id to 99999 ?>
                                        <?php if ($canApprove && !in_array($req['status'], ['evaluation', 'approved', 'inactive'])): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                            <input type="hidden" name="action" value="set_evaluation">
                                            <button type="submit" style="background: none; border: none; color: #0ea5e9; font-weight: 500; cursor: pointer; padding: 0; font-size: 13px;"><?php echo e(t('vendor-onboarding-list.action_eval')); ?></button>
                                        </form>
                                        <?php endif; ?>
                                        <?php // Force Review -- sets last_annual_review_due to today ?>
                                        <?php if ($canApprove && $req['status'] === 'approved'): ?>
                                        <form method="POST" style="display: inline;" data-confirm="<?php echo e(t('vendor-onboarding-list.confirm_force_review')); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                            <input type="hidden" name="action" value="force_review">
                                            <button type="submit" style="background: none; border: none; color: #f59e0b; font-weight: 500; cursor: pointer; padding: 0; font-size: 13px;"><?php echo e(t('vendor-onboarding-list.action_force_review')); ?></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($canDelete): ?>
                <!-- Mass Delete Action Bar (hidden until checkboxes are selected) -->
                <div id="massDeleteBar" style="display: none; position: sticky; bottom: 0; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 20px; margin-top: 15px; box-shadow: 0 -2px 8px rgba(0,0,0,0.1); z-index: 10;">
                    <form method="POST" id="massDeleteForm" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="mass_delete_drafts">
                        <input type="hidden" name="request_id" value="0">
                        <input type="hidden" name="delete_ids" id="massDeleteIds" value="">
                        <span style="font-size: 14px; color: #991b1b; font-weight: 500;">
                            <span id="selectedCount">0</span> <?php echo e(t('vendor-onboarding-list.drafts_selected')); ?>
                        </span>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" id="clearSelection" style="padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 6px; background: white; color: #374151; font-size: 13px; cursor: pointer;">
                                <?php echo e(t('vendor-onboarding-list.clear_selection')); ?>
                            </button>
                            <button type="submit" style="padding: 8px 16px; border: none; border-radius: 6px; background: #dc2626; color: white; font-size: 13px; font-weight: 600; cursor: pointer;">
                                <?php echo e(t('vendor-onboarding-list.delete_selected')); ?>
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($showProcurementSelect): ?>
                <!-- Procurement Update Action Bar (hidden until vendors are selected) -->
                <div id="procUpdateBar" style="display: none; position: sticky; bottom: 0; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 12px 20px; margin-top: 15px; box-shadow: 0 -2px 8px rgba(0,0,0,0.1); z-index: 10; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <span style="font-size: 14px; color: #3730a3; font-weight: 500;">
                        <span id="procSelectedCount">0</span><?php echo e(t('vendor-onboarding-list.vendors_selected_suffix')); ?>
                    </span>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" id="procClearSelection" style="padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 6px; background: white; color: #374151; font-size: 13px; cursor: pointer;"><?php echo e(t('vendor-onboarding-list.clear_selection')); ?></button>
                        <button type="button" id="procProvideUpdateBtn" style="padding: 8px 16px; border: none; border-radius: 6px; background: #4f46e5; color: white; font-size: 13px; font-weight: 600; cursor: pointer;"><?php echo e(t('vendor-onboarding-list.provide_procurement_update')); ?></button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pagination Controls -->
                <?php if (!isset($_GET['view']) || $_GET['view'] !== 'expiring_contracts'): ?>
                <?php if ($onbTotalRows > 0): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-top: 1px solid #e5e7eb; flex-wrap: wrap; gap: 10px;">
                    <div style="font-size: 13px; color: #666;">
                        <?php echo e(t('vendor-onboarding-list.showing')); ?> <?php echo $onbPg['start_row']; ?>-<?php echo $onbPg['end_row']; ?> <?php echo e(t('vendor-onboarding-list.of')); ?> <?php echo $onbPg['total_rows']; ?> <?php echo e(t('vendor-onboarding-list.vendors')); ?>
                    </div>
                    <?php if ($onbPg['total_pages'] > 1): ?>
                    <ul style="display: flex; gap: 4px; list-style: none; margin: 0; padding: 0;">
                        <?php if ($onbPg['has_prev']): ?>
                        <li><a href="<?php echo Pagination::buildPageUrl(1); ?>" style="display: inline-block; padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;">&laquo;</a></li>
                        <li><a href="<?php echo Pagination::buildPageUrl($onbPg['current_page'] - 1); ?>" style="display: inline-block; padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;">&lsaquo;</a></li>
                        <?php endif; ?>
                        <?php
                        $startP = max(1, $onbPg['current_page'] - 2);
                        $endP = min($onbPg['total_pages'], $onbPg['current_page'] + 2);
                        for ($i = $startP; $i <= $endP; $i++): ?>
                        <li><?php if ($i === $onbPg['current_page']): ?>
                        <span style="display: inline-block; padding: 6px 12px; border: 1px solid var(--theme-header-color, #ff6543); border-radius: 4px; background: var(--theme-header-color, #ff6543); color: white; font-size: 13px;"><?php echo $i; ?></span>
                        <?php else: ?>
                        <a href="<?php echo Pagination::buildPageUrl($i); ?>" style="display: inline-block; padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;"><?php echo $i; ?></a>
                        <?php endif; ?></li>
                        <?php endfor; ?>
                        <?php if ($onbPg['has_next']): ?>
                        <li><a href="<?php echo Pagination::buildPageUrl($onbPg['current_page'] + 1); ?>" style="display: inline-block; padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;">&rsaquo;</a></li>
                        <li><a href="<?php echo Pagination::buildPageUrl($onbPg['total_pages']); ?>" style="display: inline-block; padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;">&raquo;</a></li>
                        <?php endif; ?>
                    </ul>
                    <?php endif; ?>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size: 12px; color: #666;"><?php echo e(t('vendor-onboarding-list.show')); ?></label>
                        <select data-action="changePerPage" style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                            <option value="25" <?php echo $onbPerPage == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $onbPerPage == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $onbPerPage == 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                        <label style="font-size: 12px; color: #666;"><?php echo e(t('vendor-onboarding-list.per_page')); ?></label>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php endif; // End view conditional (tasks vs requests) ?>
            </div>
        </div>

        <!-- Status Notes viewer modal (read-only; available to any role that can see the vendor) -->
        <div id="notesViewModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:8px; width:90%; max-width:560px; max-height:90vh; overflow:auto; box-shadow:0 10px 40px rgba(0,0,0,0.2);">
                <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:16px; color:#111827;"><?php echo e(t('vendor-onboarding-list.status_notes')); ?> &mdash; <span id="notesVendorName"></span></h3>
                    <button type="button" id="notesModalClose" style="background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#6b7280;">&times;</button>
                </div>
                <div style="padding:20px;" id="notesModalBody">
                    <p style="color:#6b7280; font-size:13px;"><?php echo e(t('vendor-onboarding-list.loading')); ?></p>
                </div>
                <div style="padding:16px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end;">
                    <button type="button" id="notesModalCancel" style="padding:8px 16px; border:1px solid #d1d5db; border-radius:6px; background:white; color:#374151; font-size:13px; cursor:pointer;"><?php echo e(t('vendor-onboarding-list.close')); ?></button>
                </div>
            </div>
        </div>

        <?php if ($showProcurementSelect): ?>
        <!-- Provide Procurement with Update modal (cyber_tprm / admin) -->
        <div id="procUpdateModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:8px; width:90%; max-width:560px; max-height:90vh; overflow:auto; box-shadow:0 10px 40px rgba(0,0,0,0.2);">
                <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:16px; color:#111827;"><?php echo e(t('vendor-onboarding-list.provide_procurement_update')); ?></h3>
                    <button type="button" id="procModalClose" style="background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#6b7280;">&times;</button>
                </div>
                <div style="padding:20px;">
                    <p style="margin:0 0 12px; font-size:13px; color:#6b7280;"><span id="procModalCount">0</span><?php echo e(t('vendor-onboarding-list.vendors_selected_colon')); ?><span id="procModalVendors" style="color:#374151;"></span></p>
                    <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;"><?php echo e(t('vendor-onboarding-list.update_label')); ?></label>
                    <textarea id="procUpdateText" rows="5" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; font-family:inherit; box-sizing:border-box;" placeholder="<?php echo e(t('vendor-onboarding-list.update_placeholder')); ?>"></textarea>
                    <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin:14px 0 6px;"><?php echo e(t('vendor-onboarding-list.change_status_optional')); ?></label>
                    <select id="procNewStatus" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
                        <option value=""><?php echo e(t('vendor-onboarding-list.keep_current_status')); ?></option>
                        <option value="in_review"><?php echo e(t('vendor-onboarding-list.status_in_review')); ?></option>
                        <option value="ai_review"><?php echo e(t('vendor-onboarding-list.status_ai_review')); ?></option>
                        <option value="evaluation"><?php echo e(t('vendor-onboarding-list.status_evaluation')); ?></option>
                        <option value="approved"><?php echo e(t('vendor-onboarding-list.status_approved')); ?></option>
                        <option value="rejected"><?php echo e(t('vendor-onboarding-list.status_rejected')); ?></option>
                        <option value="inactive"><?php echo e(t('vendor-onboarding-list.status_inactive')); ?></option>
                    </select>
                    <div id="procModalMsg" style="margin-top:14px; font-size:13px;"></div>
                </div>
                <div style="padding:16px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" id="procModalCancel" style="padding:8px 16px; border:1px solid #d1d5db; border-radius:6px; background:white; color:#374151; font-size:13px; cursor:pointer;"><?php echo e(t('vendor-onboarding-list.close')); ?></button>
                    <button type="button" id="procModalSubmit" style="padding:8px 16px; border:none; border-radius:6px; background:#4f46e5; color:white; font-size:13px; font-weight:600; cursor:pointer;"><?php echo e(t('vendor-onboarding-list.save_update')); ?></button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($theme['footer_logo_url'])): ?>
                            <a class="brand" href="index.php">
                                <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('vendor-onboarding-list.footer_logo_alt')); ?>" style="max-height: 45px;">
                            </a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date('Y'); ?></span>
                        <span>.&nbsp;</span>
                        <span><?php echo e(t('vendor-onboarding-list.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        var search = document.getElementById('casesSearch');
        var table = document.getElementById('casesTable');
        if (!search || !table) return;
        search.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            var rows = table.querySelectorAll('tbody tr');
            rows.forEach(function(row) {
                row.style.display = !q || row.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
            });
        });
    })();
    document.addEventListener('change', function(e) {
        var el = e.target.closest('[data-action="changePerPage"]');
        if (!el) return;
        var params = new URLSearchParams(window.location.search);
        params.set('per_page', el.value);
        params.delete('page');
        window.location.href = '?' + params.toString();
    });

    // Mass delete drafts - checkbox management
    (function() {
        var selectAll = document.getElementById('selectAllDrafts');
        var checkboxes = document.querySelectorAll('.draft-checkbox');
        var bar = document.getElementById('massDeleteBar');
        var countEl = document.getElementById('selectedCount');
        var form = document.getElementById('massDeleteForm');
        var idsInput = document.getElementById('massDeleteIds');
        var clearBtn = document.getElementById('clearSelection');

        if (!checkboxes.length || !bar) return;

        function updateBar() {
            var checked = document.querySelectorAll('.draft-checkbox:checked');
            var count = checked.length;
            countEl.textContent = count;
            bar.style.display = count > 0 ? '' : 'none';
            // Update select-all state
            if (selectAll) {
                selectAll.checked = count > 0 && count === checkboxes.length;
                selectAll.indeterminate = count > 0 && count < checkboxes.length;
            }
        }

        // Select all toggle
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
                updateBar();
            });
        }

        // Individual checkbox changes
        checkboxes.forEach(function(cb) {
            cb.addEventListener('change', updateBar);
        });

        // Clear selection
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                checkboxes.forEach(function(cb) { cb.checked = false; });
                updateBar();
            });
        }

        // Form submit - collect IDs and confirm
        if (form) {
            form.addEventListener('submit', function(e) {
                var checked = document.querySelectorAll('.draft-checkbox:checked');
                if (checked.length === 0) { e.preventDefault(); return; }
                var ids = Array.prototype.map.call(checked, function(cb) { return cb.value; });
                idsInput.value = ids.join(',');
                if (!confirm(<?php echo json_encode(t('vendor-onboarding-list.js_mass_delete_confirm_prefix')); ?> + checked.length + <?php echo json_encode(t('vendor-onboarding-list.js_mass_delete_confirm_suffix')); ?>)) {
                    e.preventDefault();
                }
            });
        }
    })();

    // ---- Procurement: read-only Status Notes viewer + Provide-Update modal ----
    (function() {
        var csrf = <?php echo json_encode($csrfToken); ?>;
        var reloadOnClose = false;

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        // Read-only Status Notes viewer (pill is visible to any role that can see the vendor)
        var notesModal = document.getElementById('notesViewModal');
        function closeNotes() { if (notesModal) notesModal.style.display = 'none'; }
        if (notesModal) {
            var nClose = document.getElementById('notesModalClose');
            var nCancel = document.getElementById('notesModalCancel');
            if (nClose) nClose.addEventListener('click', closeNotes);
            if (nCancel) nCancel.addEventListener('click', closeNotes);
        }
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-action="viewNotes"]');
            if (!btn || !notesModal) return;
            var rid = btn.getAttribute('data-request-id');
            document.getElementById('notesVendorName').textContent = btn.getAttribute('data-vendor') || '';
            var body = document.getElementById('notesModalBody');
            body.innerHTML = '<p style="color:#6b7280;font-size:13px;">' + <?php echo json_encode(t('vendor-onboarding-list.loading')); ?> + '</p>';
            notesModal.style.display = 'flex';
            fetch('api/procurement-notes-list.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrf, request_id: rid })
            }).then(function(r) { return r.json(); }).then(function(resp) {
                if (resp.csrf_token) csrf = resp.csrf_token;
                if (!resp.success) { body.innerHTML = '<p style="color:#dc2626;font-size:13px;">' + escapeHtml(resp.error || <?php echo json_encode(t('vendor-onboarding-list.unable_to_load_notes')); ?>) + '</p>'; return; }
                if (!resp.notes || !resp.notes.length) { body.innerHTML = '<p style="color:#6b7280;font-size:13px;">' + <?php echo json_encode(t('vendor-onboarding-list.no_notes_yet')); ?> + '</p>'; return; }
                var html = '';
                resp.notes.forEach(function(n) {
                    html += '<div style="border:1px solid #e5e7eb;border-radius:6px;padding:10px 12px;margin-bottom:10px;">'
                        + '<div style="font-size:12px;color:#6b7280;margin-bottom:4px;">' + escapeHtml(n.date) + ' &middot; ' + escapeHtml(n.author)
                        + (n.status ? ' &middot; <span style="color:#3730a3;">' + escapeHtml(n.status) + '</span>' : '') + '</div>'
                        + '<div style="font-size:13px;color:#374151;white-space:pre-wrap;">' + escapeHtml(n.text) + '</div></div>';
                });
                body.innerHTML = html;
            }).catch(function() { body.innerHTML = '<p style="color:#dc2626;font-size:13px;">' + <?php echo json_encode(t('vendor-onboarding-list.network_error')); ?> + '</p>'; });
        });

        // Provide Procurement with Update (cyber_tprm / admin; Review pill only)
        var pBar = document.getElementById('procUpdateBar');
        var pModal = document.getElementById('procUpdateModal');
        var reviewBoxes = document.querySelectorAll('.review-checkbox');
        var selectAllReview = document.getElementById('selectAllReview');
        if (pBar && reviewBoxes.length) {
            var pCount = document.getElementById('procSelectedCount');
            function pUpdateBar() {
                var checked = document.querySelectorAll('.review-checkbox:checked');
                pCount.textContent = checked.length;
                pBar.style.display = checked.length > 0 ? 'flex' : 'none';
                if (selectAllReview) {
                    selectAllReview.checked = checked.length > 0 && checked.length === reviewBoxes.length;
                    selectAllReview.indeterminate = checked.length > 0 && checked.length < reviewBoxes.length;
                }
            }
            if (selectAllReview) selectAllReview.addEventListener('change', function() { reviewBoxes.forEach(function(cb) { cb.checked = selectAllReview.checked; }); pUpdateBar(); });
            reviewBoxes.forEach(function(cb) { cb.addEventListener('change', pUpdateBar); });
            var pClear = document.getElementById('procClearSelection');
            if (pClear) pClear.addEventListener('click', function() { reviewBoxes.forEach(function(cb) { cb.checked = false; }); pUpdateBar(); });

            var openBtn = document.getElementById('procProvideUpdateBtn');
            if (openBtn && pModal) {
                openBtn.addEventListener('click', function() {
                    var checked = document.querySelectorAll('.review-checkbox:checked');
                    if (!checked.length) return;
                    var names = Array.prototype.map.call(checked, function(cb) { return cb.getAttribute('data-vendor'); });
                    document.getElementById('procModalCount').textContent = checked.length;
                    document.getElementById('procModalVendors').textContent = names.join(', ');
                    document.getElementById('procUpdateText').value = '';
                    document.getElementById('procNewStatus').value = '';
                    document.getElementById('procModalMsg').textContent = '';
                    pModal.style.display = 'flex';
                });
            }
        }
        if (pModal) {
            // Modal stays open until the user explicitly closes it (no backdrop / Escape close).
            function closeProc() { pModal.style.display = 'none'; if (reloadOnClose) window.location.reload(); }
            var pmClose = document.getElementById('procModalClose');
            var pmCancel = document.getElementById('procModalCancel');
            if (pmClose) pmClose.addEventListener('click', closeProc);
            if (pmCancel) pmCancel.addEventListener('click', closeProc);
            var pmSubmit = document.getElementById('procModalSubmit');
            if (pmSubmit) pmSubmit.addEventListener('click', function() {
                var text = document.getElementById('procUpdateText').value.trim();
                var msg = document.getElementById('procModalMsg');
                if (!text) { msg.style.color = '#dc2626'; msg.textContent = <?php echo json_encode(t('vendor-onboarding-list.please_enter_update')); ?>; return; }
                var checked = document.querySelectorAll('.review-checkbox:checked');
                var ids = Array.prototype.map.call(checked, function(cb) { return parseInt(cb.value, 10); });
                if (!ids.length) { msg.style.color = '#dc2626'; msg.textContent = <?php echo json_encode(t('vendor-onboarding-list.no_vendors_selected')); ?>; return; }
                pmSubmit.disabled = true;
                msg.style.color = '#6b7280'; msg.textContent = <?php echo json_encode(t('vendor-onboarding-list.saving')); ?>;
                fetch('api/procurement-update-save.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: csrf, vendor_ids: ids, update_text: text, new_status: document.getElementById('procNewStatus').value })
                }).then(function(r) { return r.json(); }).then(function(resp) {
                    pmSubmit.disabled = false;
                    if (resp.csrf_token) csrf = resp.csrf_token;
                    if (!resp.success) { msg.style.color = '#dc2626'; msg.textContent = resp.error || <?php echo json_encode(t('vendor-onboarding-list.failed_to_save')); ?>; return; }
                    reloadOnClose = true;
                    msg.style.color = '#166534';
                    msg.textContent = <?php echo json_encode(t('vendor-onboarding-list.js_saved_update_prefix')); ?> + (resp.updated || ids.length) + <?php echo json_encode(t('vendor-onboarding-list.js_saved_update_suffix')); ?>;
                }).catch(function() { pmSubmit.disabled = false; msg.style.color = '#dc2626'; msg.textContent = <?php echo json_encode(t('vendor-onboarding-list.network_error')); ?>; });
            });
        }
    })();
    </script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
