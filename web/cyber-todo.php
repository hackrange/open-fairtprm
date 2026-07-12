<?php
/**
 * Cyber To-Do List - The TPRM Team's Never-Ending Honey-Do List
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Welcome to the nerve center of vendor risk management. This page auto-generates
 * a prioritized todo list by scanning the entire vendor database for things that
 * need attention: expiring ISO certificates, vendors overdue for SRS rescoring,
 * significant score drops, annual reviews that are past due, vendors stuck in
 * approval limbo, and vendors that nobody bothered to tier yet. Plus custom
 * user-created todos because sometimes you just need a sticky note. Each todo
 * item supports an activity log (notes, status changes, closure notes) so you
 * have a full audit trail of who did what and when. Think of it as the world's
 * most paranoid task manager -- it assumes everything is on fire until proven
 * otherwise. Also handles import audit trails and the ability to revert bulk
 * imports, because we all make mistakes and deserve a second chance.
 *
 * Business logic lives in CyberTodoService -- this file is the controller/view.
 */

// Initialize the framework and make sure we have an authenticated human
require_once 'includes/init.php';
requireAuth();

// Round up all our singleton service objects
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// formatLocalTime() lives in init.php now -- one timezone helper to rule them all

// Gate check: only cyber_tprm and admins get to see the todo list.
// Everyone else can go back to their dashboards and stop being nosy.
$isAdmin = $acl->hasGroup('administrator');
$isCyberTPRM = $acl->hasGroup('cyber_tprm');
$isAuditor = $acl->hasGroup('auditor');

if (!$isAdmin && !$isCyberTPRM && !$isAuditor) {
    http_response_code(403);
    die(t('cyber-todo.access_denied'));
}

require_once __DIR__ . '/includes/classes/SRSService.php';
$srsService = new SRSService();
$todoService = new CyberTodoService($db, $srsService);

// Vendor Assessment templates offered by the "Send Assessment" action.
$vendorAssessmentTemplates = [];
try {
    $vendorAssessmentTemplates = $db->fetchAll(
        "SELECT id, name FROM assessment_templates WHERE category = 'vendor_assessment' AND is_active = 1 ORDER BY name ASC"
    );
} catch (Exception $e) {
    // assessment_templates may not exist on very old schemas
}

$error = '';
$success = '';

// Schema lives in migrations/add_cyber_todo_tables.sql now -- setup.php handles it.
// No more DDL on every page load. Your database thanks you.

// The POST handler mega-switch -- handles adding activities, updating statuses,
// marking annual reviews, closing/reopening todos, and reverting imports.
// Each branch delegates to CyberTodoService for the actual DB work.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('cyber-todo.invalid_request');
    } elseif ($isAuditor && !$isAdmin && !$isCyberTPRM) {
        // Auditor is a read-only role: it may VIEW the to-do list but must not
        // drive any state-changing action (add_activity, update_tier,
        // close/reopen, revert_import, ...). The UI hides these controls; this
        // is the matching server-side enforcement so a hand-crafted POST with a
        // scraped CSRF token cannot mutate or delete data.
        http_response_code(403);
        $error = t('cyber-todo.readonly_role');
    } elseif (isset($_POST['add_activity']) && in_array(trim($_POST['activity_title'] ?? ''), ['Send Assessment', 'Force Annual Review'], true)) {
        // Action Plan actions invoked immediately from the manual Create Todo modal.
        // These actually perform the action (send the assessment / force the review)
        // via the same executor the Vendor Remediation Schedule cron uses.
        $title = trim($_POST['activity_title'] ?? '');
        $referenceId = intval($_POST['reference_id'] ?? 0);
        $description = trim($_POST['activity_description'] ?? $_POST['description'] ?? '');
        $templateId = !empty($_POST['assessment_template_id']) ? intval($_POST['assessment_template_id']) : null;
        $actionKey = $title === 'Send Assessment' ? 'send_assessment' : 'force_annual_review';

        if ($referenceId <= 0) {
            $error = t('cyber-todo.select_valid_vendor');
        } elseif ($description === '') {
            $error = t('cyber-todo.enter_description');
        } elseif ($actionKey === 'send_assessment' && !$templateId) {
            $error = t('cyber-todo.select_valid_vendor');
        } else {
            try {
                $result = $todoService->executeScheduledAction([
                    'request_id' => $referenceId,
                    'action' => $actionKey,
                    'assessment_template_id' => $templateId,
                    'description' => $description,
                ], (int)$user['id']);
                if (empty($result['error'])) {
                    $auth->audit($user['id'], 'todo_action_plan_immediate', 'vendor_onboarding_requests', $referenceId, [
                        'new' => ['action' => $actionKey, 'assessment_template_id' => $templateId]
                    ]);
                    $success = t('cyber-todo.activity_added');
                } else {
                    $error = $result['error'];
                }
            } catch (Exception $e) {
                error_log('Immediate action plan error: ' . $e->getMessage());
                $error = t('cyber-todo.activity_add_failed');
            }
        }
    } elseif (isset($_POST['add_activity'])) {
        $todoType = $_POST['todo_type'] ?? '';
        $referenceType = $_POST['reference_type'] ?? '';
        $referenceId = intval($_POST['reference_id'] ?? 0);
        $activityType = $_POST['activity_type'] ?? 'note';
        $title = trim($_POST['activity_title'] ?? $_POST['title'] ?? '');
        $description = trim($_POST['activity_description'] ?? $_POST['description'] ?? '');
        $status = $_POST['activity_status'] ?? 'open';
        $dueDate = !empty($_POST['activity_due_date']) ? $_POST['activity_due_date'] : (!empty($_POST['due_date']) ? $_POST['due_date'] : null);
        $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
        $vendorName = trim($_POST['vendor_name'] ?? '');

        // For custom todos, validate that a valid vendor is selected
        if ($todoType === 'custom' && $referenceType === 'vendor_onboarding_requests') {
            if ($referenceId <= 0) {
                $error = t('cyber-todo.select_valid_vendor');
            } else {
                $vendor = $db->fetchOne("SELECT id FROM vendor_onboarding_requests WHERE id = :id AND status = 'approved'", [':id' => $referenceId]);
                if (!$vendor) {
                    $error = t('cyber-todo.select_approved_vendor');
                }
            }
        }

        // For notes on custom todos, prepend vendor name if available
        if ($todoType === 'custom' && $activityType === 'note' && !empty($vendorName)) {
            $description = '[Vendor: ' . $vendorName . '] ' . $description;
        }

        if (!empty($error)) {
            // Error already set above
        } elseif (empty($description) && empty($assignedTo)) {
            $error = t('cyber-todo.enter_description_or_assign');
        } else {
            // Auto-generate description for assignment-only activities
            if (empty($description) && !empty($assignedTo)) {
                $assigneeName = $db->fetchOne("SELECT full_name FROM users WHERE id = :id", [':id' => $assignedTo]);
                $description = 'Assigned to ' . ($assigneeName['full_name'] ?? 'user #' . $assignedTo);
            }
            try {
                $todoService->addActivity($todoType, $referenceType, $referenceId, $activityType, $title, $description, $status, $dueDate, (int)$user['id'], $assignedTo);
                $auth->audit($user['id'], 'todo_add_activity', 'cyber_todo_activities', $referenceId, [
                    'new' => ['todo_type' => $todoType, 'reference_type' => $referenceType, 'activity_type' => $activityType, 'title' => $title, 'status' => $status, 'assigned_to' => $assignedTo]
                ]);
                $success = t('cyber-todo.activity_added');
            } catch (Exception $e) {
                error_log('Add activity error: ' . $e->getMessage());
                $error = t('cyber-todo.activity_add_failed');
            }
        }
    } elseif (isset($_POST['update_activity_status'])) {
        $activityId = intval($_POST['activity_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';

        if ($activityId && in_array($newStatus, ['open', 'in_progress', 'closed', 'deferred'])) {
            try {
                $todoService->updateActivityStatus($activityId, $newStatus, (int)$user['id']);
                $auth->audit($user['id'], 'todo_update_status', 'cyber_todo_activities', $activityId, [
                    'new' => ['status' => $newStatus]
                ]);
                $success = t('cyber-todo.activity_status_updated');
            } catch (Exception $e) {
                $error = t('cyber-todo.activity_status_failed');
            }
        }
    } elseif (isset($_POST['edit_activity'])) {
        // Author-only note edit. CyberTodoService::editActivity() re-checks that
        // the requesting user owns the row and that it is an editable note type,
        // so a forged activity_id for someone else's note simply returns false.
        $activityId = intval($_POST['activity_id'] ?? 0);
        $newDescription = trim($_POST['activity_description'] ?? $_POST['description'] ?? '');

        if (!$activityId) {
            $error = t('cyber-todo.activity_edit_failed');
        } elseif ($newDescription === '') {
            $error = t('cyber-todo.enter_description');
        } else {
            try {
                if ($todoService->editActivity($activityId, $newDescription, (int)$user['id'])) {
                    $auth->audit($user['id'], 'todo_edit_activity', 'cyber_todo_activities', $activityId, [
                        'new' => ['description' => $newDescription]
                    ]);
                    $success = t('cyber-todo.activity_updated');
                } else {
                    $error = t('cyber-todo.activity_edit_denied');
                }
            } catch (Exception $e) {
                error_log('Edit activity error: ' . $e->getMessage());
                $error = t('cyber-todo.activity_edit_failed');
            }
        }
    } elseif (isset($_POST['delete_activity'])) {
        // Author-only note delete -- ownership/type enforced in the service.
        $activityId = intval($_POST['activity_id'] ?? 0);

        if (!$activityId) {
            $error = t('cyber-todo.activity_delete_failed');
        } else {
            try {
                if ($todoService->deleteActivity($activityId, (int)$user['id'])) {
                    $auth->audit($user['id'], 'todo_delete_activity', 'cyber_todo_activities', $activityId);
                    $success = t('cyber-todo.activity_deleted');
                } else {
                    $error = t('cyber-todo.activity_delete_denied');
                }
            } catch (Exception $e) {
                error_log('Delete activity error: ' . $e->getMessage());
                $error = t('cyber-todo.activity_delete_failed');
            }
        }
    } elseif (isset($_POST['mark_annual_review'])) {
        $vendorId = intval($_POST['vendor_id'] ?? 0);
        if ($vendorId) {
            try {
                $todoService->markAnnualReview($vendorId);
                $auth->audit($user['id'], 'todo_mark_annual_review', 'vendor_onboarding_requests', $vendorId);
                $success = t('cyber-todo.vendor_reviewed');
            } catch (Exception $e) {
                $error = t('cyber-todo.vendor_reviewed_failed');
            }
        }
    } elseif (isset($_POST['close_todo'])) {
        $todoType = $_POST['todo_type'] ?? '';
        $referenceType = $_POST['reference_type'] ?? '';
        $referenceId = intval($_POST['reference_id'] ?? 0);
        $closureNote = trim($_POST['closure_note'] ?? 'Marked as complete');

        if ($referenceId) {
            try {
                $todoService->closeTodo($todoType, $referenceType, $referenceId, $closureNote, (int)$user['id']);
                $auth->audit($user['id'], 'todo_close', 'cyber_todo_activities', $referenceId, [
                    'new' => ['todo_type' => $todoType, 'reference_type' => $referenceType, 'closure_note' => $closureNote]
                ]);
                $success = t('cyber-todo.todo_completed');
            } catch (Exception $e) {
                error_log('Close todo error: ' . $e->getMessage());
                $error = t('cyber-todo.todo_close_failed');
            }
        }
    } elseif (isset($_POST['reopen_todo'])) {
        $referenceType = $_POST['reference_type'] ?? '';
        $referenceId = intval($_POST['reference_id'] ?? 0);
        $todoType = $_POST['todo_type'] ?? '';

        if ($referenceId) {
            try {
                $todoService->reopenTodo($todoType, $referenceType, $referenceId, (int)$user['id']);
                $auth->audit($user['id'], 'todo_reopen', 'cyber_todo_activities', $referenceId, [
                    'new' => ['todo_type' => $todoType, 'reference_type' => $referenceType]
                ]);
                $success = t('cyber-todo.todo_reopened');
            } catch (Exception $e) {
                $error = t('cyber-todo.todo_reopen_failed');
            }
        }
    } elseif (isset($_POST['update_tier'])) {
        // Inline tier assignment -- so you can tier a vendor without leaving
        // the todo list. Because context-switching is the enemy of productivity.
        $vendorId = intval($_POST['tier_vendor_id'] ?? 0);
        $newTier = $_POST['new_tier'] ?? '';
        $justification = trim($_POST['tier_justification'] ?? '');

        if (!in_array($newTier, ['', '1', '2', '3'])) {
            $error = t('cyber-todo.invalid_tier');
        } elseif (empty($justification)) {
            $error = t('cyber-todo.justification_required');
        } elseif ($vendorId) {
            try {
                $vendor = $db->fetchOne('SELECT vendor_tier, additional_information FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId]);
                $oldTier = $vendor['vendor_tier'] ?? '';
                $userName = $user['full_name'] ?? $user['username'] ?? 'Unknown User';
                $tierMap = [
                    '' => 'Not Assigned',
                    '1' => 'Tier 1 - Critical (Monthly rescoring)',
                    '2' => 'Tier 2 - Standard (90-day rescoring)',
                    '3' => 'Tier 3 - Low Priority (Annual rescoring)'
                ];
                $logEntry = "\n\n--- Tier Change Log ---\nDate: " . date('m/d/Y, h:i A') . "\nUser: {$userName}\nChanged From: " . ($tierMap[$oldTier] ?? 'Unknown') . "\nChanged To: " . ($tierMap[$newTier] ?? 'Unknown') . "\nReason: {$justification}\n-----------------------";

                $db->update('vendor_onboarding_requests', [
                    'vendor_tier' => $newTier === '' ? null : $newTier,
                    'additional_information' => ($vendor['additional_information'] ?? '') . $logEntry
                ], 'id = :id', [':id' => $vendorId]);
                $auth->audit($user['id'], 'vendor_tier_update', 'vendor_onboarding_requests', $vendorId, [
                    'old' => ['vendor_tier' => $oldTier],
                    'new' => ['vendor_tier' => $newTier, 'justification' => $justification]
                ]);
                $success = t('cyber-todo.tier_updated');
            } catch (Exception $e) {
                error_log('Tier update error: ' . $e->getMessage());
                $error = t('cyber-todo.tier_update_failed');
            }
        }
    } elseif (isset($_POST['revert_import'])) {
        // The "oh no, I imported the wrong file" escape hatch.
        $activityId = intval($_POST['activity_id'] ?? 0);

        if ($activityId) {
            try {
                $result = $todoService->revertImport($activityId, (int)$user['id']);
                $auth->audit($user['id'], 'todo_revert_import', 'cyber_todo_activities', $activityId, [
                    'new' => ['reverted' => $result['reverted'], 'already_deleted' => $result['already_deleted']]
                ]);
                if ($result['reverted'] === 0 && $result['already_deleted'] > 0) {
                    $success = "All {$result['already_deleted']} records from this import were already deleted.";
                } else {
                    $success = "Import reverted successfully. {$result['reverted']} records deleted.";
                    if ($result['already_deleted'] > 0) {
                        $success .= " ({$result['already_deleted']} were already deleted)";
                    }
                }
                if (!empty($result['errors'])) {
                    $success .= " Some errors occurred: " . implode('; ', $result['errors']);
                }
            } catch (Exception $e) {
                error_log('Revert import error: ' . $e->getMessage());
                $error = $e->getMessage();
            }
        }
    } elseif (isset($_POST['snooze_todo'])) {
        $snoozeDays = intval($_POST['snooze_days'] ?? 7);
        if ($snoozeDays < 1) $snoozeDays = 7;
        if ($snoozeDays > 365) $snoozeDays = 365;
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO cyber_todo_snoozes (todo_type, reference_type, reference_id, snoozed_until, snoozed_by)
                 VALUES (:todo_type, :ref_type, :ref_id, DATE_ADD(NOW(), INTERVAL :days DAY), :user_id)
                 ON DUPLICATE KEY UPDATE snoozed_until = DATE_ADD(NOW(), INTERVAL :days2 DAY), snoozed_by = :user_id2",
                [
                    ':todo_type' => $_POST['todo_type'] ?? '',
                    ':ref_type' => $_POST['reference_type'] ?? '',
                    ':ref_id' => intval($_POST['reference_id'] ?? 0),
                    ':days' => $snoozeDays,
                    ':user_id' => (int)$user['id'],
                    ':days2' => $snoozeDays,
                    ':user_id2' => (int)$user['id'],
                ]
            );
            $success = 'Item snoozed for ' . $snoozeDays . ' day' . ($snoozeDays > 1 ? 's' : '') . '.';
        } catch (Exception $e) {
            error_log('Snooze error: ' . $e->getMessage());
            $error = t('cyber-todo.snooze_failed');
        }
    } elseif (isset($_POST['unsnooze_todo'])) {
        try {
            $db = Database::getInstance();
            $db->query(
                "DELETE FROM cyber_todo_snoozes WHERE todo_type = :todo_type AND reference_type = :ref_type AND reference_id = :ref_id",
                [
                    ':todo_type' => $_POST['todo_type'] ?? '',
                    ':ref_type' => $_POST['reference_type'] ?? '',
                    ':ref_id' => intval($_POST['reference_id'] ?? 0),
                ]
            );
            $success = t('cyber-todo.item_unsnoozed');
        } catch (Exception $e) {
            // Table may not exist yet
        }
    }
}

$csrfToken = $security->generateCSRFToken();

// Filter and display parameters -- the sidebar lets users narrow down by type,
// status, priority, and overdue items. Defaults to "open" because that's what
// you usually care about (nobody opens a todo list to admire completed work).
$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? 'open';
$priorityFilter = $_GET['priority'] ?? '';
$pastDueFilter = $_GET['past_due'] ?? '';
$assignedFilter = $_GET['assigned'] ?? '';
// SECURITY (IDOR): only administrators may filter the to-do list by an arbitrary
// assignee. Non-admins are clamped to their own user ID so they cannot enumerate
// another user's assigned cards by tampering with ?assigned=<other uid>. The only
// legitimate non-admin consumer is the "Assigned to Me" sidebar link, which always
// passes the current user's own ID.
if ($assignedFilter !== '' && !$isAdmin && (int)$assignedFilter !== (int)$user['id']) {
    $assignedFilter = (string)(int)$user['id'];
}
$showSnoozed = ($_GET['snoozed'] ?? '') === '1';

// Now for the main event: CyberTodoService gathers ALL the todo items from
// 7 different sources (certs, rescores, score drops, annual reviews, unapproved,
// untiered, and custom user todos), then enriches, filters, and sorts them.
$todoItems = [];

try {
    // One service call replaces ~500 lines of inline queries.
    // See CyberTodoService::gatherAllTodoItems() for all 7 sources.
    $todoItems = $todoService->gatherAllTodoItems();
} catch (Exception $e) {
    error_log('Cyber To-Do fetch error: ' . $e->getMessage());
    $error = t('cyber-todo.error_loading_items') . $e->getMessage();
}

// Import audit log data + activity enrichment + filtering + sorting.
// All the data assembly that used to take 500 lines now fits in a few calls.
$importActivities = [];
try {
    $importActivities = $todoService->getImportActivities();
} catch (Exception $e) {
    error_log('Import activities fetch error: ' . $e->getMessage());
}

// Enrich each item with its activity history (notes, status changes, etc.)
$todoService->enrichWithActivities($todoItems);

// Apply all the user's filters and sort by priority then date
$todoItems = $todoService->filterAndSort($todoItems, $typeFilter, $statusFilter, $priorityFilter, $pastDueFilter, $assignedFilter);

// Snooze filtering -- separate snoozed items from active ones
$snoozedItemCount = 0;
$snoozedLookup = [];
try {
    $dbSnooze = Database::getInstance();
    $snoozedRows = $dbSnooze->fetchAll(
        "SELECT todo_type, reference_type, reference_id, snoozed_until FROM cyber_todo_snoozes WHERE snoozed_until > NOW()"
    );
    foreach ($snoozedRows as $row) {
        $snoozedLookup[$row['todo_type'] . ':' . $row['reference_type'] . ':' . $row['reference_id']] = $row['snoozed_until'];
    }
} catch (Exception $e) {
    // Table may not exist yet
}
if (!empty($snoozedLookup)) {
    if ($showSnoozed) {
        // Show ONLY snoozed items
        $todoItems = array_values(array_filter($todoItems, function($item) use ($snoozedLookup) {
            $key = ($item['type'] ?? '') . ':' . ($item['reference_type'] ?? '') . ':' . ($item['reference_id'] ?? '');
            return isset($snoozedLookup[$key]);
        }));
        $snoozedItemCount = count($todoItems);
    } else {
        // Count snoozed items before filtering them out
        foreach ($todoItems as $item) {
            $key = ($item['type'] ?? '') . ':' . ($item['reference_type'] ?? '') . ':' . ($item['reference_id'] ?? '');
            if (isset($snoozedLookup[$key])) $snoozedItemCount++;
        }
        // Hide snoozed items from normal view
        $todoItems = array_values(array_filter($todoItems, function($item) use ($snoozedLookup) {
            $key = ($item['type'] ?? '') . ':' . ($item['reference_type'] ?? '') . ':' . ($item['reference_id'] ?? '');
            return !isset($snoozedLookup[$key]);
        }));
    }
}

// Count items by type for the sidebar filter badges (e.g. "SRS Rescore (12)")
$typeCounts = $todoService->countByType($todoItems);

// PHP-level pagination of the combined/filtered results
require_once __DIR__ . '/includes/classes/Pagination.php';
$todoPgParams = Pagination::getParams(['per_page' => 25, 'sort_column' => 'priority', 'sort_dir' => 'ASC']);
$todoPerPage = $todoPgParams['per_page'];
$todoCurrentPage = $todoPgParams['page'];
$todoTotalItems = count($todoItems);
$todoPg = Pagination::paginate($todoTotalItems, $todoPerPage, $todoCurrentPage);
$paginatedTodoItems = array_slice($todoItems, $todoPg['offset'], $todoPerPage);

// Fetch completed/closed actions for the "Completed Actions" tab at the bottom.
$searchQuery = trim($_GET['completed_search'] ?? '');
$searchingAllHistory = !empty($searchQuery);
$completedActions = [];
try {
    $completedActions = $todoService->getCompletedActions($searchQuery);
} catch (Exception $e) {
    // Table may not exist yet
}

// Final stats for the header badges: how many open vs closed items do we have?
$openCount = count(array_filter($todoItems, function($i) { return empty($i['activities']) || $i['has_open_activities']; }));
$closedCount = count(array_filter($todoItems, function($i) { return !empty($i['activities']) && !$i['has_open_activities']; }));

// Active users for assignment dropdowns
$activeUsers = [];
try {
    $activeUsers = $db->fetchAll("
        SELECT DISTINCT u.id, u.username, u.full_name
        FROM users u
        INNER JOIN user_acl_groups uag ON u.id = uag.user_id
        INNER JOIN acl_groups ag ON uag.group_id = ag.id
        WHERE u.is_active = 1 AND ag.group_name IN ('cyber_tprm', 'administrator')
        ORDER BY u.full_name ASC, u.username ASC
    ");
} catch (Exception $e) {
    // Users table may not exist yet during initial setup
}

// Count items assigned to the current user (for sidebar badge)
$myAssignedCount = 0;
$currentUserId = (int)$user['id'];
foreach ($todoItems as $ti) {
    if (!empty($ti['assigned_to']) && (int)$ti['assigned_to'] === $currentUserId) {
        $myAssignedCount++;
        continue;
    }
    if (!empty($ti['activities'])) {
        foreach ($ti['activities'] as $act) {
            if (!empty($act['assigned_to']) && (int)$act['assigned_to'] === $currentUserId) {
                $myAssignedCount++;
                break;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo e(t('cyber-todo.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
    <!-- style.css removed - causes layout conflicts with custom page styling -->
    <style>
        #completed-section summary::-webkit-details-marker { display: none; }
        #completed-section[open] .completed-arrow { transform: rotate(90deg); }
        #import-audit-section summary::-webkit-details-marker { display: none; }
        #import-audit-section[open] .import-audit-arrow { transform: rotate(90deg); }
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color']); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color']); ?>;
            --sidebar-width: <?php echo e($theme['nav_width']); ?>px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Roboto', sans-serif; background: #f9fafb; }

        .page { display: flex; flex-direction: column; min-height: 100vh; }

        .top-bar {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-shrink: 0;
        }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a {
            color: #333; text-decoration: none; padding: 8px 15px;
            border-radius: 4px; background: rgba(255,101,67,0.1);
            transition: background 0.2s; font-size: 14px;
        }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }

        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }

        /* Sidebar - Matching index.php style */
        .sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            max-width: var(--sidebar-width);
            background: var(--nav-fill-color);
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

        .main-content { flex: 1; padding: 25px; overflow-y: auto; }

        .alert { padding: 12px 16px; margin-bottom: 20px; border-radius: 6px; font-size: 14px; }
        .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

        .stats-row {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px; margin-bottom: 25px;
        }
        .stat-card {
            background: white; border: 1px solid #e5e7eb; border-radius: 8px;
            padding: 15px; text-align: center; cursor: pointer;
            transition: all 0.2s; text-decoration: none; display: block;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .stat-card .value { font-size: 24px; font-weight: 600; color: var(--theme-header-color); }
        .stat-card .label { font-size: 12px; color: #666; margin-top: 4px; }

        .todo-card {
            background: white; border: 1px solid #e5e7eb; border-radius: 10px;
            margin-bottom: 15px; overflow: hidden;
        }
        .todo-header {
            display: flex; align-items: center; gap: 12px; padding: 15px 20px;
            border-left: 4px solid #6b7280; cursor: pointer;
            transition: background 0.2s;
        }
        .todo-header:hover { background: #f9fafb; }
        .todo-header.expanded { background: #f9fafb; }
        .todo-header .title { font-weight: 500; color: #333; flex: 1; }
        .todo-header .badge {
            font-size: 10px; padding: 3px 10px; border-radius: 12px;
            font-weight: 500; white-space: nowrap;
        }
        .todo-header .meta { font-size: 12px; color: #666; }
        .todo-header .activity-indicator {
            font-size: 11px; color: #666; background: #f3f4f6;
            padding: 3px 8px; border-radius: 10px;
        }
        .todo-header .expand-icon { color: #999; transition: transform 0.2s; }
        .todo-header.expanded .expand-icon { transform: rotate(180deg); }

        .todo-body { display: none; padding: 0 20px 20px; border-top: 1px solid #e5e7eb; }
        .todo-body.show { display: block; }

        .todo-description {
            font-size: 13px; color: #666; padding: 15px 0;
            border-bottom: 1px solid #f3f4f6; margin-bottom: 15px;
        }

        .todo-actions { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .btn {
            padding: 8px 16px; border: none; border-radius: 4px;
            cursor: pointer; font-size: 13px; font-weight: 500;
            transition: all 0.2s; text-decoration: none; display: inline-block;
        }
        .btn-primary { background: var(--theme-button-color); color: white; }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }

        .activities-section h4 { font-size: 13px; color: #666; margin: 0 0 12px 0; font-weight: 500; }
        .activity-list { margin-bottom: 20px; }
        .activity-item {
            background: #f9fafb; border-radius: 6px; padding: 12px;
            margin-bottom: 8px; font-size: 13px;
        }
        .activity-item .activity-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 6px;
        }
        .activity-item .activity-meta { font-size: 11px; color: #999; }
        .activity-item .activity-text { color: #333; }
        .activity-item .status-badge {
            font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: 500;
        }
        .status-open { background: #fef3c7; color: #92400e; }
        .status-in_progress { background: #dbeafe; color: #1e40af; }
        .status-closed { background: #dcfce7; color: #166534; }
        .status-deferred { background: #f3f4f6; color: #6b7280; }

        .add-activity-form {
            background: #f9fafb; border-radius: 8px; padding: 15px;
        }
        .form-row { display: flex; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
        .form-group { flex: 1; min-width: 150px; }
        .form-group label { display: block; font-size: 12px; color: #666; margin-bottom: 4px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 8px 10px; border: 1px solid #ddd;
            border-radius: 4px; font-size: 13px;
        }
        .form-group textarea { min-height: 60px; resize: vertical; }

        /* Typeahead dropdown for Assign To */
        .assign-to-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            max-height: 180px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .assign-to-dropdown .assign-option {
            padding: 8px 10px;
            cursor: pointer;
            font-size: 13px;
            color: #333;
            border-bottom: 1px solid #f3f4f6;
        }
        .assign-to-dropdown .assign-option:last-child { border-bottom: none; }
        .assign-to-dropdown .assign-option:hover,
        .assign-to-dropdown .assign-option.highlighted {
            background: #f0f0ff;
            color: #4f46e5;
        }
        .assign-to-dropdown .assign-option .assign-username {
            font-size: 11px;
            color: #999;
            margin-left: 6px;
        }
        .assign-to-dropdown .no-results {
            padding: 8px 10px;
            font-size: 12px;
            color: #999;
            font-style: italic;
        }

        .empty-state { text-align: center; padding: 60px 20px; color: #666; }
        .empty-state .icon { font-size: 50px; margin-bottom: 15px; opacity: 0.5; }

        @media (max-width: 768px) {
            .main-layout { flex-direction: column; }
            .sidebar { width: 100%; border-right: none; border-bottom: 1px solid #e5e7eb; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }

        /* Footer styling */
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
            overflow: visible;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; white-space: nowrap; }
        .footer-modern .brand img { max-height: 45px; }
    </style>
</head>
<body>
    <div class="page">
        <?php renderImpersonationBanner(); ?>

        <div class="top-bar">
            <span style="color: #666; margin-right: 15px;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <?php if ($isAdmin): ?><a href="admin.php"><?php echo e(t('chrome.admin')); ?></a><?php endif; ?>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <?php
            $currentPage = empty($assignedFilter) ? 'cyber_todo' : 'cyber_todo_assigned';
            include __DIR__ . '/includes/sidebar_nav.php';
            ?>

        <main class="main-content">
            <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <!-- Page Header with Search -->
            <div class="cyber-todo-header" style="display: flex; align-items: center; gap: 30px; margin-bottom: 25px; flex-wrap: wrap;">
                <div class="cyber-todo-title-row" style="flex: 1; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;"><?php echo e(t('cyber-todo.heading_cyber_todo_items')); ?></h2>
                    <?php if ($isAdmin || $isCyberTPRM): ?>
                    <button type="button" id="createTodoBtn" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--theme-button-color); color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.2s;" class="hover-brightness">
                        <img src="app/icons/plus-square.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('cyber-todo.create_todo_btn')); ?>
                    </button>
                    <?php endif; ?>
                </div>
                <div class="vendor-search-container" style="position: relative; min-width: 300px;">
                    <input
                        type="text"
                        id="vendorSearchInput"
                        placeholder="<?php echo e(t('cyber-todo.search_vendors_placeholder')); ?>"
                        class="focus-ring"
                        style="width: 100%; padding: 10px 40px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                    >
                    <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">🔍</span>
                    <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
                </div>
            </div>

            <div class="stats-row">
                <a href="?status=all" class="stat-card">
                    <div class="value"><?php echo count($todoItems); ?></div>
                    <div class="label"><?php echo e(t('cyber-todo.total_items')); ?></div>
                </a>
                <a href="?status=open&priority=high" class="stat-card" style="border-left: 3px solid #dc2626;">
                    <div class="value" style="color: #dc2626;"><?php echo count(array_filter($todoItems, function($i) { return $i['priority'] <= 2; })); ?></div>
                    <div class="label"><?php echo e(t('cyber-todo.high_priority')); ?></div>
                </a>
                <a href="?status=open&past_due=1" class="stat-card" style="border-left: 3px solid #be123c;">
                    <div class="value" style="color: #be123c;"><?php echo count(array_filter($todoItems, function($i) {
                        // For custom todos, only count as past due if they have an explicit due_date that's past
                        if ($i['type'] === 'custom') {
                            return !empty($i['is_overdue']);
                        }
                        // For other types, check if date is in the past
                        return !empty($i['date']) && strtotime($i['date']) < strtotime('today');
                    })); ?></div>
                    <div class="label"><?php echo e(t('cyber-todo.past_due')); ?></div>
                </a>
                <a href="?status=open&type=cert_expiry" class="stat-card" style="border-left: 3px solid #f59e0b;">
                    <div class="value" style="color: #f59e0b;"><?php echo $typeCounts['cert_expiry']['count'] ?? 0; ?></div>
                    <div class="label"><?php echo e(t('cyber-todo.cert_expirations')); ?></div>
                </a>
                <a href="?status=open&type=annual_review" class="stat-card" style="border-left: 3px solid #8b5cf6;">
                    <div class="value" style="color: #8b5cf6;"><?php echo $typeCounts['annual_review']['count'] ?? 0; ?></div>
                    <div class="label"><?php echo e(t('cyber-todo.annual_reviews')); ?></div>
                </a>
                <a href="?status=open&type=custom" class="stat-card" style="border-left: 3px solid #10b981;">
                    <div class="value" style="color: #10b981;"><?php echo $typeCounts['custom']['count'] ?? 0; ?></div>
                    <div class="label"><?php echo e(t('cyber-todo.custom')); ?></div>
                </a>
                <?php if ($snoozedItemCount > 0 || $showSnoozed): ?>
                <a href="?snoozed=1" class="stat-card" style="border-left: 3px solid #6b7280;<?php echo $showSnoozed ? ' background: #f3f4f6; box-shadow: inset 0 0 0 2px #6b7280;' : ''; ?>">
                    <div class="value" style="color: #6b7280;"><?php echo $snoozedItemCount; ?></div>
                    <div class="label"><?php echo e(t('cyber-todo.snoozed')); ?></div>
                </a>
                <?php endif; ?>
            </div>

            <?php if (empty($todoItems)): ?>
            <div class="empty-state">
                <div class="icon"><img src="app/icons/check-square-broken.svg" alt="" width="32" height="32"></div>
                <?php if ($showSnoozed): ?>
                <h3><?php echo e(t('cyber-todo.no_snoozed_items_heading')); ?></h3>
                <p><?php echo e(t('cyber-todo.no_snoozed_items_body')); ?></p>
                <?php else: ?>
                <h3><?php echo e(t('cyber-todo.all_caught_up_heading')); ?></h3>
                <p><?php echo e(t('cyber-todo.no_pending_items_body')); ?></p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <?php foreach ($paginatedTodoItems as $index => $item): ?>
            <div class="todo-card">
                <div class="todo-header" style="border-left-color: <?php echo e($item['badge_color']); ?>;" data-action="toggleTodo" data-arg="<?php echo $index; ?>" id="header-<?php echo $index; ?>">
                    <div class="title"><?php echo e($item['title']); ?></div>
                    <span class="badge" style="background: <?php echo e($item['badge_color']); ?>20; color: <?php echo e($item['badge_color']); ?>;">
                        <?php echo e($item['badge']); ?>
                    </span>
                    <span class="meta"><?php echo e($item['type_label']); ?></span>
                    <?php if (!empty($item['assigned_to_name'])): ?>
                    <span class="meta" style="color: #6366f1;">&#128100; <?php echo e($item['assigned_to_name']); ?></span>
                    <?php endif; ?>
                    <?php if ($item['activity_count'] > 0): ?>
                    <span class="activity-indicator">
                        <?php echo e($item['open_activity_count']); ?>/<?php echo e($item['activity_count']); ?> <?php echo e(t('cyber-todo.open_count_suffix')); ?>
                    </span>
                    <?php endif; ?>
                    <?php
                    $headerSnoozeKey = ($item['type'] ?? '') . ':' . ($item['reference_type'] ?? '') . ':' . ($item['reference_id'] ?? '');
                    if ($showSnoozed && isset($snoozedLookup[$headerSnoozeKey])):
                    ?>
                    <span style="font-size: 11px; color: #6b7280; margin-left: auto; margin-right: 8px; align-self: center;"><?php echo e(t('cyber-todo.snoozed_until_prefix')); ?> <?php echo date('M j, Y', strtotime($snoozedLookup[$headerSnoozeKey])); ?></span>
                    <form method="POST" style="display: inline; margin-right: 8px;" onclick="event.stopPropagation();">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="todo_type" value="<?php echo e($item['type']); ?>">
                        <input type="hidden" name="reference_type" value="<?php echo e($item['reference_type']); ?>">
                        <input type="hidden" name="reference_id" value="<?php echo e($item['reference_id']); ?>">
                        <button type="submit" name="unsnooze_todo" style="padding: 3px 10px; background: #6b7280; color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;"><?php echo e(t('cyber-todo.unsnooze_btn')); ?></button>
                    </form>
                    <?php endif; ?>
                    <span class="expand-icon">&#9660;</span>
                </div>
                <div class="todo-body" id="body-<?php echo $index; ?>">
                    <div class="todo-description"><?php echo e($item['description']); ?></div>

                    <div class="todo-actions">
                        <a href="<?php echo e($item['link']); ?>" class="btn btn-primary" target="_blank"><?php echo e(t('cyber-todo.view_btn')); ?></a>
                        <?php if (!$isAuditor): ?>
                        <button type="button" class="btn btn-secondary" data-toggle="comment-form-<?php echo $index; ?>"><?php echo e(t('cyber-todo.add_comment_btn')); ?></button>
                        <button type="button" class="btn" style="background: #6366f1; color: white;" data-toggle="assign-form-<?php echo $index; ?>">&#128100; <?php echo e(t('cyber-todo.assign_btn')); ?></button>
                        <?php if ($item['has_open_activities'] || empty($item['activities'])): ?>
                        <button type="button" class="btn btn-success" data-toggle="close-form-<?php echo $index; ?>"><?php echo e(t('cyber-todo.close_btn')); ?></button>
                        <?php else: ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="todo_type" value="<?php echo e($item['type']); ?>">
                            <input type="hidden" name="reference_type" value="<?php echo e($item['reference_type']); ?>">
                            <input type="hidden" name="reference_id" value="<?php echo e($item['reference_id']); ?>">
                            <button type="submit" name="reopen_todo" class="btn" style="background: #f59e0b; color: white;"><?php echo e(t('cyber-todo.reopen_btn')); ?></button>
                        </form>
                        <?php endif; ?>
                        <button type="button" class="btn" style="background: #6b7280; color: white;" data-toggle="snooze-form-<?php echo $index; ?>"><?php echo e(t('cyber-todo.snooze_btn')); ?></button>
                        <?php if ($item['type'] === 'not_tiered' && !empty($item['reference_id'])): ?>
                        <button type="button" class="btn" style="background: #dc2626; color: white;" data-action="openTierModal" data-args="[<?php echo (int)$item['reference_id']; ?>, <?php echo e(json_encode($item['title'])); ?>]"><?php echo e(t('cyber-todo.tier_btn')); ?></button>
                        <?php endif; ?>
                        <?php if (!empty($item['can_mark_reviewed'])): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="vendor_id" value="<?php echo e($item['reference_id']); ?>">
                            <button type="submit" name="mark_annual_review" class="btn" style="background: #8b5cf6; color: white;" data-confirm="<?php echo e(t('cyber-todo.mark_reviewed_confirm')); ?>">
                                <?php echo e(t('cyber-todo.mark_reviewed_btn')); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php
                        $snoozeKey = ($item['type'] ?? '') . ':' . ($item['reference_type'] ?? '') . ':' . ($item['reference_id'] ?? '');
                        if ($showSnoozed && isset($snoozedLookup[$snoozeKey])):
                        ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="todo_type" value="<?php echo e($item['type']); ?>">
                            <input type="hidden" name="reference_type" value="<?php echo e($item['reference_type']); ?>">
                            <input type="hidden" name="reference_id" value="<?php echo e($item['reference_id']); ?>">
                            <button type="submit" name="unsnooze_todo" class="btn" style="background: #6b7280; color: white;"><?php echo e(t('cyber-todo.unsnooze_btn')); ?></button>
                        </form>
                        <span style="font-size: 12px; color: #6b7280; align-self: center;"><?php echo e(t('cyber-todo.snoozed_until_prefix')); ?> <?php echo date('M j, Y', strtotime($snoozedLookup[$snoozeKey])); ?></span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Comment Form (hidden by default) -->
                    <div id="comment-form-<?php echo $index; ?>" style="display: none; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                        <?php if (!empty($item['vendor_name'])): ?>
                        <div style="margin-bottom: 10px; padding: 8px 12px; background: #e0f2fe; border-radius: 4px; font-size: 13px; color: #0369a1;">
                            <strong><?php echo e(t('cyber-todo.vendor_label')); ?></strong> <?php echo e($item['vendor_name']); ?>
                        </div>
                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="todo_type" value="<?php echo e($item['type']); ?>">
                            <input type="hidden" name="reference_type" value="<?php echo e($item['reference_type']); ?>">
                            <input type="hidden" name="reference_id" value="<?php echo e($item['reference_id']); ?>">
                            <?php if (!empty($item['vendor_id'])): ?>
                            <input type="hidden" name="vendor_id" value="<?php echo e($item['vendor_id']); ?>">
                            <input type="hidden" name="vendor_name" value="<?php echo e($item['vendor_name']); ?>">
                            <?php endif; ?>
                            <input type="hidden" name="activity_type" value="note">
                            <input type="hidden" name="activity_status" value="open">
                            <div style="margin-bottom: 10px;">
                                <textarea name="description" placeholder="<?php echo e(t('cyber-todo.comment_placeholder')); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; min-height: 60px;"></textarea>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" name="add_activity" class="btn btn-primary btn-sm"><?php echo e(t('cyber-todo.save_comment_btn')); ?></button>
                                <button type="button" class="btn btn-sm" style="background: #e5e7eb; color: #333;" data-close="comment-form-<?php echo $index; ?>"><?php echo e(t('cyber-todo.cancel_btn')); ?></button>
                            </div>
                        </form>
                    </div>

                    <!-- Close Form (hidden by default) -->
                    <div id="close-form-<?php echo $index; ?>" style="display: none; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="todo_type" value="<?php echo e($item['type']); ?>">
                            <input type="hidden" name="reference_type" value="<?php echo e($item['reference_type']); ?>">
                            <input type="hidden" name="reference_id" value="<?php echo e($item['reference_id']); ?>">
                            <div style="margin-bottom: 10px;">
                                <label style="font-size: 12px; color: #666; display: block; margin-bottom: 4px;"><?php echo e(t('cyber-todo.closure_note_label')); ?></label>
                                <textarea name="closure_note" placeholder="<?php echo e(t('cyber-todo.closure_note_placeholder')); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; min-height: 60px;"></textarea>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" name="close_todo" class="btn btn-success btn-sm"><?php echo e(t('cyber-todo.close_todo_btn')); ?></button>
                                <button type="button" class="btn btn-sm" style="background: #e5e7eb; color: #333;" data-close="close-form-<?php echo $index; ?>"><?php echo e(t('cyber-todo.cancel_btn')); ?></button>
                            </div>
                        </form>
                    </div>

                    <!-- Quick Assign Form (hidden by default) -->
                    <div id="assign-form-<?php echo $index; ?>" style="display: none; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="todo_type" value="<?php echo e($item['type']); ?>">
                            <input type="hidden" name="reference_type" value="<?php echo e($item['reference_type']); ?>">
                            <input type="hidden" name="reference_id" value="<?php echo e($item['reference_id']); ?>">
                            <input type="hidden" name="activity_type" value="note">
                            <input type="hidden" name="activity_status" value="open">
                            <div class="form-group" style="position: relative; margin-bottom: 10px;">
                                <label style="font-size: 12px; color: #4338ca; display: block; margin-bottom: 4px; font-weight: 500;"><?php echo e(t('cyber-todo.assign_to_label')); ?></label>
                                <input type="hidden" name="assigned_to" class="assign-to-id" value="">
                                <input type="text" class="assign-to-search" value="" autocomplete="off" placeholder="<?php echo e(t('cyber-todo.type_name_placeholder')); ?>" style="width: 100%; padding: 8px; border: 1px solid #c7d2fe; border-radius: 4px; font-size: 13px;">
                                <div class="assign-to-dropdown" style="display:none;"></div>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" name="add_activity" class="btn btn-sm" style="background: #6366f1; color: white;"><?php echo e(t('cyber-todo.save_assignment_btn')); ?></button>
                                <button type="button" class="btn btn-sm" style="background: #e5e7eb; color: #333;" data-close="assign-form-<?php echo $index; ?>"><?php echo e(t('cyber-todo.cancel_btn')); ?></button>
                            </div>
                        </form>
                    </div>

                    <!-- Snooze Form (hidden by default) -->
                    <div id="snooze-form-<?php echo $index; ?>" style="display: none; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="todo_type" value="<?php echo e($item['type']); ?>">
                            <input type="hidden" name="reference_type" value="<?php echo e($item['reference_type']); ?>">
                            <input type="hidden" name="reference_id" value="<?php echo e($item['reference_id']); ?>">
                            <div style="margin-bottom: 10px;">
                                <label style="font-size: 12px; color: #374151; display: block; margin-bottom: 4px; font-weight: 500;"><?php echo e(t('cyber-todo.snooze_for_label')); ?></label>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <label style="cursor: pointer;"><input type="radio" name="snooze_days" value="1"> <?php echo e(t('cyber-todo.snooze_1_day')); ?></label>
                                    <label style="cursor: pointer;"><input type="radio" name="snooze_days" value="7" checked> <?php echo e(t('cyber-todo.snooze_7_days')); ?></label>
                                    <label style="cursor: pointer;"><input type="radio" name="snooze_days" value="14"> <?php echo e(t('cyber-todo.snooze_14_days')); ?></label>
                                    <label style="cursor: pointer;"><input type="radio" name="snooze_days" value="30"> <?php echo e(t('cyber-todo.snooze_30_days')); ?></label>
                                    <label style="cursor: pointer;"><input type="radio" name="snooze_days" value="90"> <?php echo e(t('cyber-todo.snooze_90_days')); ?></label>
                                </div>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" name="snooze_todo" class="btn btn-sm" style="background: #6b7280; color: white;"><?php echo e(t('cyber-todo.snooze_btn')); ?></button>
                                <button type="button" class="btn btn-sm" style="background: #e5e7eb; color: #333;" data-close="snooze-form-<?php echo $index; ?>"><?php echo e(t('cyber-todo.cancel_btn')); ?></button>
                            </div>
                        </form>
                    </div>

                    <?php if (!empty($item['activities'])): ?>
                    <div class="activities-section">
                        <h4><?php echo e(t('cyber-todo.activity_log_heading')); ?> (<?php echo count($item['activities']); ?>)</h4>
                        <div class="activity-list">
                            <?php foreach ($item['activities'] as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-header">
                                    <span class="activity-meta">
                                        <?php echo e($activity['created_by_name']); ?>
                                        <?php if (!empty($activity['assigned_to_name'])): ?>
                                        &rarr; <?php echo e($activity['assigned_to_name']); ?>
                                        <?php endif; ?>
                                        &bull; <?php echo formatLocalTime($activity['created_at']); ?>
                                    </span>
                                    <span class="status-badge status-<?php echo e($activity['status']); ?>">
                                        <?php echo e(ucfirst(str_replace('_', ' ', $activity['status']))); ?>
                                    </span>
                                </div>
                                <div class="activity-text"><?php echo nl2br(e($activity['description'])); ?></div>
                                <?php
                                // Author-only note controls. The server (CyberTodoService)
                                // re-enforces both checks; this just hides controls the
                                // current user has no business seeing.
                                $isNoteAuthor = isset($activity['created_by']) && (int)$activity['created_by'] === $currentUserId;
                                $isEditableNote = in_array(($activity['activity_type'] ?? ''), ['note', 'action', 'reminder'], true);
                                ?>
                                <div style="margin-top: 8px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <?php if ($activity['status'] !== 'closed'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="activity_id" value="<?php echo e($activity['id']); ?>">
                                        <select name="new_status" class="btn btn-sm" style="padding: 4px 8px; font-size: 11px;" data-submit-form>
                                            <option value=""><?php echo e(t('cyber-todo.change_status_option')); ?></option>
                                            <option value="in_progress"><?php echo e(t('cyber-todo.status_in_progress')); ?></option>
                                            <option value="closed"><?php echo e(t('cyber-todo.status_closed')); ?></option>
                                            <option value="deferred"><?php echo e(t('cyber-todo.status_deferred')); ?></option>
                                        </select>
                                        <input type="hidden" name="update_activity_status" value="1">
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!$isAuditor && $isNoteAuthor && $isEditableNote): ?>
                                    <?php if ($activity['status'] !== 'closed'): ?>
                                    <button type="button" class="btn btn-sm" style="background: #e5e7eb; color: #333;" data-toggle="edit-activity-<?php echo (int)$activity['id']; ?>"><?php echo e(t('cyber-todo.edit_btn')); ?></button>
                                    <?php endif; ?>
                                    <form method="POST" style="display: inline;" data-confirm="<?php echo e(t('cyber-todo.delete_note_confirm')); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="activity_id" value="<?php echo e($activity['id']); ?>">
                                        <button type="submit" name="delete_activity" class="btn btn-sm" style="background: #fee2e2; color: #991b1b;"><?php echo e(t('cyber-todo.delete_btn')); ?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$isAuditor && $isNoteAuthor && $isEditableNote && $activity['status'] !== 'closed'): ?>
                                <div id="edit-activity-<?php echo (int)$activity['id']; ?>" style="display: none; margin-top: 8px;">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="activity_id" value="<?php echo e($activity['id']); ?>">
                                        <textarea name="activity_description" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; min-height: 60px;"><?php echo e($activity['description']); ?></textarea>
                                        <div style="display: flex; gap: 8px; margin-top: 6px;">
                                            <button type="submit" name="edit_activity" class="btn btn-primary btn-sm"><?php echo e(t('cyber-todo.save_btn')); ?></button>
                                            <button type="button" class="btn btn-sm" style="background: #e5e7eb; color: #333;" data-close="edit-activity-<?php echo (int)$activity['id']; ?>"><?php echo e(t('cyber-todo.cancel_btn')); ?></button>
                                        </div>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="add-activity-form">
                        <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #666;"><?php echo e(t('cyber-todo.add_activity')); ?></h4>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="todo_type" value="<?php echo e($item['type']); ?>">
                            <input type="hidden" name="reference_type" value="<?php echo e($item['reference_type']); ?>">
                            <input type="hidden" name="reference_id" value="<?php echo e($item['reference_id']); ?>">

                            <div class="form-row">
                                <div class="form-group" style="flex: 2;">
                                    <label><?php echo e(t('cyber-todo.activity_type_label')); ?></label>
                                    <select name="activity_type">
                                        <option value="note"><?php echo e(t('cyber-todo.option_note')); ?></option>
                                        <option value="action"><?php echo e(t('cyber-todo.option_action_taken')); ?></option>
                                        <option value="reminder"><?php echo e(t('cyber-todo.option_reminder')); ?></option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 2;">
                                    <label><?php echo e(t('cyber-todo.status_label')); ?></label>
                                    <select name="activity_status">
                                        <option value="open"><?php echo e(t('cyber-todo.status_open_option')); ?></option>
                                        <option value="in_progress"><?php echo e(t('cyber-todo.status_in_progress')); ?></option>
                                        <option value="closed"><?php echo e(t('cyber-todo.status_closed')); ?></option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 2;">
                                    <label><?php echo e(t('cyber-todo.due_date_optional_label')); ?></label>
                                    <input type="date" name="due_date">
                                </div>
                                <div class="form-group" style="flex: 2; position: relative;">
                                    <label><?php echo e(t('cyber-todo.assign_to_label')); ?></label>
                                    <input type="hidden" name="assigned_to" class="assign-to-id" value="<?php echo $currentUserId; ?>">
                                    <input type="text" class="assign-to-search" value="<?php echo e($user['full_name'] ?? $user['username']); ?>" autocomplete="off" placeholder="<?php echo e(t('cyber-todo.type_to_search_placeholder')); ?>">
                                    <div class="assign-to-dropdown" style="display:none;"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><?php echo e(t('cyber-todo.description_label')); ?> *</label>
                                <textarea name="description" placeholder="<?php echo e(t('cyber-todo.describe_activity_placeholder')); ?>"></textarea>
                            </div>
                            <button type="submit" name="add_activity" class="btn btn-primary"><?php echo e(t('cyber-todo.add_activity')); ?></button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Todo Pagination -->
            <?php if ($todoTotalItems > 0): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding: 15px 0; border-top: 1px solid #e5e7eb; flex-wrap: wrap; gap: 10px;">
                <div style="font-size: 13px; color: #666;">
                    <?php echo e(t('cyber-todo.showing_label')); ?> <?php echo $todoPg['start_row']; ?>-<?php echo $todoPg['end_row']; ?> <?php echo e(t('cyber-todo.of_label')); ?> <?php echo $todoPg['total_rows']; ?> <?php echo e(t('cyber-todo.items_label')); ?>
                </div>
                <?php if ($todoPg['total_pages'] > 1): ?>
                <div style="display: flex; gap: 4px;">
                    <?php if ($todoPg['has_prev']): ?>
                    <a href="<?php echo Pagination::buildPageUrl(1); ?>" style="padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;">&laquo;</a>
                    <a href="<?php echo Pagination::buildPageUrl($todoPg['current_page'] - 1); ?>" style="padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;">&lsaquo;</a>
                    <?php endif; ?>
                    <?php
                    $startP = max(1, $todoPg['current_page'] - 2);
                    $endP = min($todoPg['total_pages'], $todoPg['current_page'] + 2);
                    for ($i = $startP; $i <= $endP; $i++): ?>
                    <?php if ($i === $todoPg['current_page']): ?>
                    <span style="padding: 6px 12px; border: 1px solid var(--theme-header-color); border-radius: 4px; background: var(--theme-header-color); color: white; font-size: 13px;"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="<?php echo Pagination::buildPageUrl($i); ?>" style="padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;"><?php echo $i; ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($todoPg['has_next']): ?>
                    <a href="<?php echo Pagination::buildPageUrl($todoPg['current_page'] + 1); ?>" style="padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;">&rsaquo;</a>
                    <a href="<?php echo Pagination::buildPageUrl($todoPg['total_pages']); ?>" style="padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;">&raquo;</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <label style="font-size: 12px; color: #666;"><?php echo e(t('cyber-todo.show_label')); ?></label>
                    <select data-action="changePerPage" style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                        <option value="25" <?php echo $todoPerPage == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $todoPerPage == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $todoPerPage == 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                    <label style="font-size: 12px; color: #666;"><?php echo e(t('cyber-todo.per_page_label')); ?></label>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>

            <!-- Completed Actions Section -->
            <?php if (!empty($completedActions) || $searchingAllHistory): ?>
            <details id="completed-section" style="margin-top: 40px; scroll-margin-top: 20px;"<?php echo $searchingAllHistory ? ' open' : ''; ?>>
                <summary style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding-bottom: 10px; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; list-style: none;">
                    <span style="color: #999; font-size: 12px; transition: transform 0.2s; display: inline-block;" class="completed-arrow">&#9654;</span>
                    <h2 style="font-size: 18px; color: #333; margin: 0;">
                        <?php if ($searchingAllHistory): ?>
                            <?php echo e(t('cyber-todo.all_completed_activities_heading')); ?>
                            <span style="font-size: 12px; font-weight: 400; color: #666; margin-left: 8px;"><?php echo e(t('cyber-todo.full_history_label')); ?></span>
                        <?php else: ?>
                            <?php echo e(t('cyber-todo.recently_completed_heading')); ?>
                            <span style="font-size: 12px; font-weight: 400; color: #666; margin-left: 8px;">(<?php echo count($completedActions); ?>)</span>
                        <?php endif; ?>
                    </h2>
                </summary>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div>
                        <h2 style="font-size: 0; margin: 0;"></h2>
                        <?php if ($searchingAllHistory): ?>
                        <div style="margin-top: 5px; font-size: 12px; color: #666;">
                            <?php echo e(t('cyber-todo.searching_for_label')); ?> <strong>"<?php echo e($searchQuery); ?>"</strong>
                            <span style="color: #999; margin-left: 8px;">•</span>
                            <span style="margin-left: 8px;"><?php echo count($completedActions); ?> result<?php echo count($completedActions) != 1 ? 's' : ''; ?> found</span>
                            <a href="<?php echo e($_SERVER['SCRIPT_NAME']); ?>#completed-section" style="margin-left: 10px; color: var(--theme-header-color); text-decoration: none; font-weight: 500;">× <?php echo e(t('cyber-todo.clear_search_link')); ?></a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="position: relative;">
                        <form method="GET" action="<?php echo e($_SERVER['SCRIPT_NAME']); ?>#completed-section" id="completedSearchForm">
                            <?php
                            // Preserve other GET parameters
                            foreach ($_GET as $key => $value) {
                                if ($key !== 'completed_search' && is_string($value)) {
                                    echo '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
                                }
                            }
                            ?>
                            <input
                                type="text"
                                name="completed_search"
                                id="completedActionsSearch"
                                value="<?php echo e($searchQuery); ?>"
                                placeholder="<?php echo e(t('cyber-todo.filter_placeholder')); ?>"
                                autocomplete="off"
                                style="width: 450px; padding: 8px 145px 8px 12px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 13px; transition: all 0.2s;"
                                class="focus-ring"
                            >
                            <div style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); display: flex; gap: 5px;">
                                <?php if ($searchingAllHistory): ?>
                                <a href="<?php echo e($_SERVER['SCRIPT_NAME']); ?>#completed-section" style="background: #6b7280; color: white; border: none; padding: 6px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-block; line-height: 1.5;">
                                    <?php echo e(t('cyber-todo.clear_btn')); ?>
                                </a>
                                <?php endif; ?>
                                <button type="submit" class="hover-opacity" style="background: var(--theme-header-color); color: white; border: none; padding: 6px 14px; border-radius: 4px; font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.2s;">
                                    <?php echo e(t('cyber-todo.search_all_btn')); ?>
                                </button>
                            </div>
                        </form>
                        <div id="completedSearchHint" style="display: none; position: absolute; top: 100%; left: 0; margin-top: 5px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 8px 12px; font-size: 12px; color: #0c4a6e; z-index: 10;">
                            <span id="completedFilterCount">0</span> <?php echo t('cyber-todo.filter_hint_suffix'); ?>
                        </div>
                    </div>
                </div>
                <?php if ($searchingAllHistory): ?>
                    <?php if (empty($completedActions)): ?>
                    <div style="background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                        <div style="font-size: 14px; color: #92400e; text-align: center;">
                            <?php echo e(t('cyber-todo.no_completed_matching')); ?> "<strong><?php echo e($searchQuery); ?></strong>"
                        </div>
                        <div style="font-size: 13px; color: #b45309; margin-top: 8px; text-align: center;">
                            <?php echo e(t('cyber-todo.try_different_term')); ?> <a href="<?php echo e($_SERVER['SCRIPT_NAME']); ?>#completed-section" style="color: var(--theme-header-color); text-decoration: underline;"><?php echo e(t('cyber-todo.view_last_30_days')); ?></a>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="completed-actions-list" style="background: white; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden;">
                    <table id="completedActionsTable" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th style="padding: 12px 15px; text-align: left; font-weight: 500; border-bottom: 2px solid #e5e7eb; width: 25%;"><?php echo e(t('cyber-todo.th_vendor')); ?></th>
                                <th style="padding: 12px 15px; text-align: left; font-weight: 500; border-bottom: 2px solid #e5e7eb;"><?php echo e(t('cyber-todo.description_label')); ?></th>
                                <th style="padding: 12px 15px; text-align: left; font-weight: 500; border-bottom: 2px solid #e5e7eb; width: 120px;"><?php echo e(t('cyber-todo.th_closed_by')); ?></th>
                                <th style="padding: 12px 15px; text-align: left; font-weight: 500; border-bottom: 2px solid #e5e7eb; width: 140px;"><?php echo e(t('cyber-todo.th_closed_date')); ?></th>
                                <th style="padding: 12px 15px; text-align: left; font-weight: 500; border-bottom: 2px solid #e5e7eb; width: 120px;"><?php echo e(t('cyber-todo.th_type')); ?></th>
                                <th style="padding: 12px 15px; text-align: center; font-weight: 500; border-bottom: 2px solid #e5e7eb; width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completedActions as $actionIndex => $action): ?>
                            <tr style="border-bottom: 1px solid #f3f4f6; cursor: pointer; transition: background 0.2s;"
                                data-vendor="<?php echo e($action['vendor_name'] ?? ''); ?>"
                                data-domain="<?php echo e($action['vendor_domain'] ?? ''); ?>"
                                data-stakeholder="<?php echo e($action['stakeholder_name'] ?? ''); ?>"
                                data-stakeholder-email="<?php echo e($action['stakeholder_email'] ?? ''); ?>"
                                data-action="toggleCompletedAction" data-arg="<?php echo $actionIndex; ?>"
                                id="completed-row-<?php echo $actionIndex; ?>">
                                <td style="padding: 12px 15px;" data-stop-propagation>
                                    <?php if (!empty($action['vendor_id']) && !empty($action['vendor_name'])): ?>
                                        <a href="vendor-onboarding.php?id=<?php echo $action['vendor_id']; ?>" class="hover-underline" style="color: var(--theme-header-color); text-decoration: none; font-weight: 500;">
                                            <?php echo e($action['vendor_name']); ?>
                                        </a>
                                        <?php if (!empty($action['vendor_domain'])): ?>
                                        <div style="font-size: 11px; color: #6b7280; margin-top: 2px;"><?php echo e($action['vendor_domain']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($action['stakeholder_name'])): ?>
                                        <div style="font-size: 11px; color: #9ca3af; margin-top: 2px;">
                                            <?php echo e(t('cyber-todo.stakeholder_label')); ?> <?php echo e($action['stakeholder_name']); ?>
                                            <?php if (!empty($action['stakeholder_email'])): ?>
                                            <span style="color: #d1d5db;">(<?php echo e($action['stakeholder_email']); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #9ca3af; font-style: italic;"><?php echo e(t('cyber-todo.na_label')); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 15px;">
                                    <div style="color: #333; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 300px;">
                                        <?php echo e($action['description'] ?: t('cyber-todo.marked_as_complete')); ?>
                                    </div>
                                    <?php if (!empty($action['related_activities'])): ?>
                                    <div style="font-size: 11px; color: #9ca3af; margin-top: 3px;">
                                        <?php echo count($action['related_activities']); ?> related note<?php echo count($action['related_activities']) != 1 ? 's' : ''; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="mobile-closed-date" style="display: none; font-size: 11px; color: #6b7280; margin-top: 4px;">
                                        <?php echo e(t('cyber-todo.closed_prefix')); ?> <?php echo formatLocalTime($action['closed_at']); ?>
                                    </div>
                                </td>
                                <td style="padding: 12px 15px;">
                                    <span style="font-weight: 500; color: #333;"><?php echo e($action['closed_by_name'] ?? $action['created_by_name'] ?? t('cyber-todo.unknown')); ?></span>
                                </td>
                                <td style="padding: 12px 15px; color: #666;">
                                    <?php echo formatLocalTime($action['closed_at']); ?>
                                </td>
                                <td style="padding: 12px 15px;">
                                    <?php
                                    $typeLabels = [
                                        'cert_expiry' => [t('cyber-todo.type_cert_expiry'), '#f59e0b'],
                                        'srs_rescore' => [t('cyber-todo.type_srs_rescore'), '#f59e0b'],
                                        'score_drop' => [t('cyber-todo.type_score_drop'), '#dc2626'],
                                        'annual_review' => [t('cyber-todo.type_annual_review'), '#8b5cf6'],
                                        'not_approved' => [t('cyber-todo.type_not_approved'), '#3b82f6'],
                                        'not_tiered' => [t('cyber-todo.type_not_tiered'), '#dc2626'],
                                        'custom' => [t('cyber-todo.type_custom'), '#10b981']
                                    ];
                                    $typeInfo = $typeLabels[$action['todo_type']] ?? [t('cyber-todo.type_other'), '#6b7280'];
                                    ?>
                                    <span style="font-size: 10px; padding: 3px 8px; border-radius: 10px; background: <?php echo $typeInfo[1]; ?>20; color: <?php echo $typeInfo[1]; ?>; font-weight: 500;">
                                        <?php echo $typeInfo[0]; ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 15px; text-align: center;">
                                    <span class="expand-icon" style="color: #999; transition: transform 0.2s; display: inline-block;">&#9660;</span>
                                </td>
                            </tr>
                            <!-- Expandable Details Row -->
                            <tr id="completed-details-<?php echo $actionIndex; ?>" style="display: none; background: #f9fafb; border-bottom: 1px solid #e5e7eb;"
                                data-vendor="<?php echo e($action['vendor_name'] ?? ''); ?>"
                                data-domain="<?php echo e($action['vendor_domain'] ?? ''); ?>"
                                data-stakeholder="<?php echo e($action['stakeholder_name'] ?? ''); ?>"
                                data-stakeholder-email="<?php echo e($action['stakeholder_email'] ?? ''); ?>">
                                <td colspan="6" style="padding: 20px;">
                                    <div style="max-width: 900px;">
                                        <!-- Action Buttons -->
                                        <div style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                            <?php if (!empty($action['vendor_id'])): ?>
                                            <a href="vendor-srs-details.php?id=<?php echo $action['vendor_id']; ?>" target="_blank" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                                                <span><?php echo e(t('cyber-todo.view_srs_details_btn')); ?></span>
                                                <span style="font-size: 11px;">↗</span>
                                            </a>
                                            <a href="vendor-onboarding.php?id=<?php echo $action['vendor_id']; ?>" target="_blank" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                                                <span><?php echo e(t('cyber-todo.view_onboarding_btn')); ?></span>
                                                <span style="font-size: 11px;">↗</span>
                                            </a>
                                            <?php endif; ?>
                                            <?php if (!$isAuditor): ?>
                                            <!-- Reopen Button -->
                                            <form method="POST" style="display: inline-block;" data-confirm="<?php echo e(t('cyber-todo.reopen_confirm')); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="todo_type" value="<?php echo e($action['todo_type']); ?>">
                                                <input type="hidden" name="reference_type" value="<?php echo e($action['reference_type']); ?>">
                                                <input type="hidden" name="reference_id" value="<?php echo e($action['reference_id']); ?>">
                                                <button type="submit" name="reopen_todo" class="btn" style="background: #f59e0b; color: white; display: inline-flex; align-items: center; gap: 6px;">
                                                    <span>↻</span>
                                                    <span><?php echo e(t('cyber-todo.reopen_item_btn')); ?></span>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Status Before Closure Banner -->
                                        <?php
                                        $statusLabels = [
                                            'cert_expiry' => [t('cyber-todo.type_cert_expiry'), '#f59e0b', t('cyber-todo.status_was_resolved')],
                                            'srs_rescore' => [t('cyber-todo.status_srs_rescore_needed'), '#f59e0b', t('cyber-todo.status_was_completed')],
                                            'score_drop' => [t('cyber-todo.type_score_drop'), '#dc2626', t('cyber-todo.status_was_addressed')],
                                            'annual_review' => [t('cyber-todo.status_annual_review_due'), '#8b5cf6', t('cyber-todo.status_was_completed')],
                                            'not_approved' => [t('cyber-todo.status_pending_approval'), '#3b82f6', t('cyber-todo.status_was_processed')],
                                            'not_tiered' => [t('cyber-todo.type_not_tiered'), '#dc2626', t('cyber-todo.status_was_completed')]
                                        ];
                                        $statusInfo = $statusLabels[$action['todo_type']] ?? [t('cyber-todo.status_todo_item'), '#6b7280', t('cyber-todo.status_was_closed')];
                                        ?>
                                        <div style="background: <?php echo $statusInfo[1]; ?>15; border-left: 4px solid <?php echo $statusInfo[1]; ?>; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                                <span style="font-size: 20px;">✓</span>
                                                <div style="flex: 1;">
                                                    <div style="font-weight: 600; color: #333; font-size: 14px; margin-bottom: 4px;">
                                                        <?php echo e($statusInfo[0]); ?> <?php echo $statusInfo[2]; ?>
                                                    </div>
                                                    <div style="color: #666; font-size: 13px;">
                                                        <strong><?php echo e($action['vendor_name'] ?? t('cyber-todo.unknown_vendor')); ?></strong>
                                                        <?php if (!empty($action['vendor_domain'])): ?>
                                                        <span style="color: #999;"> • <?php echo e($action['vendor_domain']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <span style="font-size: 10px; padding: 4px 10px; border-radius: 12px; background: <?php echo $statusInfo[1]; ?>; color: white; font-weight: 500;">
                                                    <?php echo e(t('cyber-todo.closed_badge')); ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Full Description -->
                                        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                                            <h4 style="font-size: 13px; color: #666; margin: 0 0 10px 0; font-weight: 500;"><?php echo e(t('cyber-todo.closure_note_heading')); ?></h4>
                                            <div style="color: #333; font-size: 13px; line-height: 1.6; white-space: pre-wrap; word-wrap: break-word;">
                                                <?php echo e($action['description'] ?: t('cyber-todo.marked_as_complete')); ?>
                                            </div>
                                            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #f3f4f6; font-size: 11px; color: #999;">
                                                <?php echo e(t('cyber-todo.closed_by_prefix')); ?> <?php echo e($action['closed_by_name'] ?? $action['created_by_name'] ?? t('cyber-todo.unknown')); ?> <?php echo e(t('cyber-todo.on_label')); ?> <?php echo $action['closed_at'] ? formatLocalTime($action['closed_at']) : t('cyber-todo.unknown_date'); ?>
                                            </div>
                                        </div>

                                        <!-- Related Activities/Notes -->
                                        <?php if (!empty($action['related_activities'])): ?>
                                        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px;">
                                            <h4 style="font-size: 13px; color: #666; margin: 0 0 15px 0; font-weight: 500;">
                                                <?php echo e(t('cyber-todo.related_notes_heading')); ?> (<?php echo count($action['related_activities']); ?>):
                                            </h4>
                                            <?php foreach ($action['related_activities'] as $relActivity): ?>
                                            <div style="background: #f9fafb; border-left: 3px solid #3b82f6; border-radius: 4px; padding: 12px; margin-bottom: 10px;">
                                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                                    <div>
                                                        <span style="font-weight: 500; color: #333; font-size: 12px;">
                                                            <?php echo e($relActivity['created_by_name'] ?? t('cyber-todo.unknown')); ?>
                                                        </span>
                                                        <span style="color: #999; font-size: 11px; margin-left: 8px;">
                                                            <?php echo formatLocalTime($relActivity['created_at']); ?>
                                                        </span>
                                                    </div>
                                                    <span style="font-size: 10px; padding: 2px 8px; border-radius: 10px; background: #dbeafe; color: #1e40af; font-weight: 500;">
                                                        <?php echo ucfirst(str_replace('_', ' ', $relActivity['activity_type'])); ?>
                                                    </span>
                                                </div>
                                                <div style="color: #333; font-size: 13px; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word;">
                                                    <?php echo e($relActivity['description']); ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; text-align: center; color: #999; font-size: 13px;">
                                            <?php echo e(t('cyber-todo.no_additional_notes')); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="noResultsMessage" style="display: none; padding: 40px; text-align: center; color: #999; font-size: 14px;">
                        <?php echo e(t('cyber-todo.no_completed_match')); ?>
                    </div>
                </div>
            </details>
            <?php endif; ?>

            <!-- Import Audit Log Section -->
            <?php if (!empty($importActivities)): ?>
            <details class="card" style="margin-top: 30px;" id="import-audit-section">
                <summary style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 20px; list-style: none;">
                    <span style="color: #999; font-size: 12px; transition: transform 0.2s; display: inline-block;" class="import-audit-arrow">&#9654;</span>
                    <h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;">
                        <span style="margin-right: 8px;">📥</span><?php echo e(t('cyber-todo.import_audit_heading')); ?>
                    </h2>
                    <span style="font-size: 13px; color: #666;">(<?php echo count($importActivities); ?>)</span>
                </summary>

                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8f9fa;">
                            <th style="padding: 12px; text-align: left; font-size: 12px; color: #666; font-weight: 600; border-bottom: 2px solid #e5e7eb;"><?php echo e(t('cyber-todo.th_type')); ?></th>
                            <th style="padding: 12px; text-align: left; font-size: 12px; color: #666; font-weight: 600; border-bottom: 2px solid #e5e7eb;"><?php echo e(t('cyber-todo.th_details')); ?></th>
                            <th style="padding: 12px; text-align: left; font-size: 12px; color: #666; font-weight: 600; border-bottom: 2px solid #e5e7eb;"><?php echo e(t('cyber-todo.th_imported_by')); ?></th>
                            <th style="padding: 12px; text-align: left; font-size: 12px; color: #666; font-weight: 600; border-bottom: 2px solid #e5e7eb;"><?php echo e(t('cyber-todo.th_date')); ?></th>
                            <th style="padding: 12px; text-align: left; font-size: 12px; color: #666; font-weight: 600; border-bottom: 2px solid #e5e7eb;"><?php echo e(t('cyber-todo.status_label')); ?></th>
                            <th style="padding: 12px; text-align: center; font-size: 12px; color: #666; font-weight: 600; border-bottom: 2px solid #e5e7eb;"><?php echo e(t('cyber-todo.th_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($importActivities as $import):
                            $metadata = json_decode($import['metadata'] ?? '{}', true);
                            $isUserImport = $import['todo_type'] === 'user_import';
                            $isReverted = $import['status'] === 'reverted';
                            $itemCount = $isUserImport ? count($metadata['user_ids'] ?? []) : count($metadata['vendor_ids'] ?? []);
                        ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px;">
                                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 15px; font-size: 11px; font-weight: 600; background: <?php echo $isUserImport ? '#dbeafe' : '#dcfce7'; ?>; color: <?php echo $isUserImport ? '#1e40af' : '#166534'; ?>;">
                                    <?php echo $isUserImport ? '👤 ' . e(t('cyber-todo.user_import_label')) : '🏢 ' . e(t('cyber-todo.vendor_import_label')); ?>
                                </span>
                            </td>
                            <td style="padding: 12px;">
                                <div style="font-weight: 500; color: #333; font-size: 13px;"><?php echo e($import['title']); ?></div>
                                <div style="font-size: 11px; color: #666; margin-top: 4px;">
                                    <?php echo e(t('cyber-todo.file_prefix')); ?> <?php echo e($metadata['file_name'] ?? t('cyber-todo.unknown')); ?>
                                    <?php if (!empty($metadata['error_count'])): ?>
                                    <span style="color: #dc2626;"> • <?php echo $metadata['error_count']; ?> <?php echo e(t('cyber-todo.errors_suffix')); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="padding: 12px; font-size: 13px; color: #333;">
                                <?php echo e($import['created_by_name'] ?? t('cyber-todo.unknown')); ?>
                            </td>
                            <td style="padding: 12px; font-size: 12px; color: #666;">
                                <?php echo formatLocalTime($import['created_at']); ?>
                            </td>
                            <td style="padding: 12px;">
                                <?php if ($isReverted): ?>
                                <span style="display: inline-block; padding: 4px 10px; border-radius: 15px; font-size: 11px; font-weight: 600; background: #fef2f2; color: #991b1b;">
                                    <?php echo e(t('cyber-todo.status_reverted')); ?>
                                </span>
                                <?php else: ?>
                                <span style="display: inline-block; padding: 4px 10px; border-radius: 15px; font-size: 11px; font-weight: 600; background: #dcfce7; color: #166534;">
                                    <?php echo e(t('cyber-todo.status_active')); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php if (!$isReverted && $itemCount > 0): ?>
                                <form method="POST" style="display: inline;" data-confirm="Are you sure you want to revert this import? This will permanently delete <?php echo $itemCount; ?> <?php echo $isUserImport ? 'user(s)' : 'vendor(s)'; ?>. This action cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <input type="hidden" name="activity_id" value="<?php echo $import['id']; ?>">
                                    <button type="submit" name="revert_import" class="btn btn-sm" style="background: #dc2626; color: white; padding: 6px 12px; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;">
                                        ↩ <?php echo e(t('cyber-todo.revert_btn')); ?>
                                    </button>
                                </form>
                                <?php elseif ($isReverted): ?>
                                <span style="font-size: 11px; color: #999;">—</span>
                                <?php else: ?>
                                <span style="font-size: 11px; color: #999;"><?php echo e(t('cyber-todo.no_data_label')); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <!-- Expandable details row -->
                        <tr>
                            <td colspan="6" style="padding: 0;">
                                <details style="background: #f9fafb; border-top: 1px dashed #e5e7eb;">
                                    <summary style="padding: 10px 12px; cursor: pointer; font-size: 12px; color: #666;">
                                        <?php echo e(t('cyber-todo.view_details_prefix')); ?> (<?php echo $itemCount; ?> <?php echo $isUserImport ? e(t('cyber-todo.users_label')) : e(t('cyber-todo.vendors_label')); ?>)
                                    </summary>
                                    <div style="padding: 12px; font-size: 12px; color: #333; line-height: 1.6; white-space: pre-wrap; background: white; border-top: 1px solid #e5e7eb;">
<?php echo e($import['description']); ?>
                                    </div>
                                </details>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endif; ?>
        </main>
        </div>

        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($theme['footer_logo_url'])): ?>
                            <a class="brand" href="index.php">
                                <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('cyber-todo.footer_logo_alt')); ?>" style="max-height: 45px;">
                            </a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date('Y'); ?></span>
                        <span>.&nbsp;</span>
                        <span><?php echo e(t('cyber-todo.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <script nonce="<?php echo cspNonce(); ?>">
        // Bind onblur for completed search hint
        (function() {
            var el = document.getElementById('completedActionsSearch');
            if (el) el.addEventListener('blur', function() { setTimeout(function() { document.getElementById('completedSearchHint').style.display = 'none'; }, 200); });
        })();

        function toggleTodo(index) {
            const header = document.getElementById('header-' + index);
            const body = document.getElementById('body-' + index);

            header.classList.toggle('expanded');
            body.classList.toggle('show');
        }

        // Toggle Completed Action Details
        function toggleCompletedAction(index) {
            const row = document.getElementById('completed-row-' + index);
            const detailsRow = document.getElementById('completed-details-' + index);
            const expandIcon = row.querySelector('.expand-icon');

            // Check if currently expanded by looking at the class
            if (row.classList.contains('expanded')) {
                // Collapse
                detailsRow.style.display = 'none';
                row.classList.remove('expanded');
                row.style.background = '';
                if (expandIcon) expandIcon.style.transform = '';
            } else {
                // Expand
                detailsRow.style.display = 'table-row';
                row.classList.add('expanded');
                row.style.background = '#f9fafb';
                if (expandIcon) expandIcon.style.transform = 'rotate(180deg)';
            }
        }

        // Scroll to completed section if hash is present
        if (window.location.hash === '#completed-section') {
            setTimeout(function() {
                document.getElementById('completed-section')?.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }

        // Type-ahead filtering for completed actions (only active when viewing last 30 days)
        const isSearchingAllHistory = <?php echo $searchingAllHistory ? 'true' : 'false'; ?>;

        function filterCompletedActions() {
            // Don't do client-side filtering if we're already viewing all-history server results
            if (isSearchingAllHistory) {
                return;
            }

            const searchInput = document.getElementById('completedActionsSearch');
            const table = document.getElementById('completedActionsTable');
            const hint = document.getElementById('completedSearchHint');
            const filterCount = document.getElementById('completedFilterCount');
            const clearBtn = document.getElementById('clearCompletedSearch');

            if (!searchInput || !table) return;

            const query = searchInput.value.toLowerCase().trim();
            const rows = table.querySelectorAll('tbody tr');
            let visibleCount = 0;

            rows.forEach(function(row) {
                // Skip detail rows
                if (row.id && row.id.startsWith('completed-details-')) {
                    return;
                }

                const vendorName = (row.getAttribute('data-vendor') || '').toLowerCase();
                const domain = (row.getAttribute('data-domain') || '').toLowerCase();
                const stakeholderName = (row.getAttribute('data-stakeholder') || '').toLowerCase();
                const stakeholderEmail = (row.getAttribute('data-stakeholder-email') || '').toLowerCase();

                const matches = query === '' ||
                              vendorName.includes(query) ||
                              domain.includes(query) ||
                              stakeholderName.includes(query) ||
                              stakeholderEmail.includes(query);

                if (matches) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                    // Also hide the corresponding details row
                    const rowId = row.id;
                    if (rowId) {
                        const index = rowId.replace('completed-row-', '');
                        const detailsRow = document.getElementById('completed-details-' + index);
                        if (detailsRow) {
                            detailsRow.style.display = 'none';
                        }
                    }
                }
            });

            // Show/hide hint and update count
            if (query !== '') {
                hint.style.display = 'block';
                filterCount.textContent = visibleCount;
            } else {
                hint.style.display = 'none';
            }

            // Show/hide clear button (only when not searching all history)
            if (clearBtn) {
                clearBtn.style.display = 'none'; // Clear button only shows when searching all history
            }

            // Show/hide no results message
            const noResultsMsg = document.getElementById('noResultsMessage');
            if (noResultsMsg) {
                noResultsMsg.style.display = (visibleCount === 0 && query !== '') ? 'block' : 'none';
            }
        }

        // Attach event listener only when viewing last 30 days
        const completedSearchInput = document.getElementById('completedActionsSearch');
        if (completedSearchInput && !isSearchingAllHistory) {
            completedSearchInput.addEventListener('input', filterCompletedActions);
        }

    </script>

    <?php if ($isAdmin || $isCyberTPRM): ?>
    <!-- Create Todo Modal -->
    <div id="createTodoModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,0.3);">
            <div style="padding: 20px 25px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between;">
                <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;"><?php echo e(t('cyber-todo.create_custom_todo_heading')); ?></h3>
                <button type="button" id="closeTodoModal" style="background: none; border: none; font-size: 24px; color: #666; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
            </div>
            <form id="createTodoForm" method="POST" action="cyber-todo.php" style="padding: 25px;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="add_activity" value="1">
                <input type="hidden" name="todo_type" value="custom">
                <input type="hidden" name="activity_type" value="action">
                <input type="hidden" id="hiddenReferenceType" name="reference_type" value="vendor_onboarding_requests">
                <input type="hidden" id="hiddenReferenceId" name="reference_id" value="0">

                <div style="margin-bottom: 20px; position: relative;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('cyber-todo.related_vendor_label')); ?> <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="vendorTypeahead" autocomplete="off" style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;" placeholder="<?php echo e(t('cyber-todo.type_search_vendors_placeholder')); ?>">
                    <div id="vendorTypeaheadResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-top: none; border-radius: 0 0 6px 6px; max-height: 200px; overflow-y: auto; z-index: 10000; box-shadow: 0 4px 12px rgba(0,0,0,0.15);"></div>
                    <div id="selectedVendorDisplay" style="display: none; margin-top: 8px; padding: 8px 12px; background: #e7f5ff; border: 1px solid #339af0; border-radius: 6px; font-size: 13px; color: #1864ab;">
                        <span id="selectedVendorName"></span>
                        <button type="button" id="clearVendorBtn" style="float: right; background: none; border: none; color: #1864ab; cursor: pointer; font-size: 16px; line-height: 1;">&times;</button>
                    </div>
                    <div id="vendorError" style="display: none; font-size: 12px; color: #dc2626; margin-top: 4px;"><?php echo e(t('cyber-todo.please_select_vendor')); ?></div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('cyber-todo.title_label')); ?> <span style="color: #dc2626;">*</span></label>
                    <select name="activity_title" id="todoTitleSelect" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; background: white;">
                        <option value="">-- <?php echo e(t('cyber-todo.select_title_option')); ?> --</option>
                        <option value="Contact Vendor"><?php echo e(t('cyber-todo.opt_contact_vendor')); ?></option>
                        <option value="Contact Stakeholder"><?php echo e(t('cyber-todo.opt_contact_stakeholder')); ?></option>
                        <option value="Send Assessment"><?php echo e(t('cyber-todo.opt_send_assessment')); ?></option>
                        <option value="Force Annual Review"><?php echo e(t('cyber-todo.opt_force_annual_review')); ?></option>
                    </select>
                </div>

                <div style="margin-bottom: 20px; display: none;" id="todoAssessmentGroup">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('cyber-todo.vendor_assessment_label')); ?> <span style="color: #dc2626;">*</span></label>
                    <select name="assessment_template_id" id="todoAssessmentSelect" style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; background: white;">
                        <option value="">-- <?php echo e(t('cyber-todo.select_assessment_option')); ?> --</option>
                        <?php foreach ($vendorAssessmentTemplates as $tpl): ?>
                        <option value="<?php echo (int)$tpl['id']; ?>"><?php echo e($tpl['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('cyber-todo.description_label')); ?> <span style="color: #dc2626;">*</span></label>
                    <textarea name="activity_description" required rows="4" style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; resize: vertical;" placeholder="<?php echo e(t('cyber-todo.describe_todo_placeholder')); ?>"></textarea>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('cyber-todo.due_date_optional_caps_label')); ?></label>
                    <input type="date" name="activity_due_date" style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                    <button type="button" id="cancelTodoBtn" style="padding: 10px 20px; background: #6b7280; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer;"><?php echo e(t('cyber-todo.cancel_btn')); ?></button>
                    <button type="submit" style="padding: 10px 20px; background: var(--theme-button-color); color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer;"><?php echo e(t('cyber-todo.create_todo_btn')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        // Show the Vendor Assessment picker only for the "Send Assessment" title.
        document.addEventListener('DOMContentLoaded', function() {
            var titleSelect = document.getElementById('todoTitleSelect');
            var assessmentGroup = document.getElementById('todoAssessmentGroup');
            var assessmentSelect = document.getElementById('todoAssessmentSelect');
            if (titleSelect && assessmentGroup && assessmentSelect) {
                titleSelect.addEventListener('change', function() {
                    var isSend = titleSelect.value === 'Send Assessment';
                    assessmentGroup.style.display = isSend ? 'block' : 'none';
                    assessmentSelect.required = isSend;
                    if (!isSend) assessmentSelect.value = '';
                });
            }
        });
    </script>

    <script nonce="<?php echo cspNonce(); ?>">
        // AJAX vendor typeahead (uses api/search-vendors.php instead of inline JSON)
        let vendorSearchTimeout = null;

        document.addEventListener('DOMContentLoaded', function() {
            const createTodoBtn = document.getElementById('createTodoBtn');
            const createTodoModal = document.getElementById('createTodoModal');
            const closeTodoModal = document.getElementById('closeTodoModal');
            const cancelTodoBtn = document.getElementById('cancelTodoBtn');
            const hiddenReferenceType = document.getElementById('hiddenReferenceType');
            const hiddenReferenceId = document.getElementById('hiddenReferenceId');
            const vendorTypeahead = document.getElementById('vendorTypeahead');
            const vendorResults = document.getElementById('vendorTypeaheadResults');
            const selectedVendorDisplay = document.getElementById('selectedVendorDisplay');
            const selectedVendorName = document.getElementById('selectedVendorName');
            const clearVendorBtn = document.getElementById('clearVendorBtn');

            if (createTodoBtn && createTodoModal) {
                // Open modal
                createTodoBtn.addEventListener('click', function() {
                    createTodoModal.style.display = 'flex';
                });

                // Close modal
                closeTodoModal.addEventListener('click', function() {
                    createTodoModal.style.display = 'none';
                    resetVendorSelection();
                });

                cancelTodoBtn.addEventListener('click', function() {
                    createTodoModal.style.display = 'none';
                    resetVendorSelection();
                });

                createTodoModal.addEventListener('click', function(e) {
                    if (e.target === createTodoModal) {
                        createTodoModal.style.display = 'none';
                        resetVendorSelection();
                    }
                });

                // AJAX vendor typeahead with 300ms debounce
                vendorTypeahead.addEventListener('input', function() {
                    const query = this.value.trim();
                    if (query.length < 2) {
                        vendorResults.style.display = 'none';
                        return;
                    }

                    clearTimeout(vendorSearchTimeout);
                    vendorSearchTimeout = setTimeout(function() {
                        fetch('api/search-vendors.php?q=' + encodeURIComponent(query))
                            .then(r => r.json())
                            .then(data => {
                                const matches = data.vendors || data.results || [];
                                if (matches.length === 0) {
                                    vendorResults.innerHTML = '<div style="padding: 10px 12px; color: #666; font-size: 13px;">' + <?php echo json_encode(t('cyber-todo.no_vendors_found')); ?> + '</div>';
                                } else {
                                    vendorResults.innerHTML = matches.map(v =>
                                        `<div class="vendor-option" data-id="${v.id}" data-name="${escapeHtml(v.vendor_name || v.name)}" style="padding: 10px 12px; cursor: pointer; font-size: 14px; border-bottom: 1px solid #f0f0f0;">${escapeHtml(v.vendor_name || v.name)}</div>`
                                    ).join('');

                                    vendorResults.querySelectorAll('.vendor-option').forEach(opt => {
                                        opt.addEventListener('mouseenter', function() { this.style.background = '#f5f5f5'; });
                                        opt.addEventListener('mouseleave', function() { this.style.background = 'white'; });
                                        opt.addEventListener('click', function() {
                                            selectVendor(this.dataset.id, this.dataset.name);
                                        });
                                    });
                                }
                                vendorResults.style.display = 'block';
                            })
                            .catch(() => {
                                vendorResults.innerHTML = '<div style="padding: 10px 12px; color: #dc2626; font-size: 13px;">' + <?php echo json_encode(t('cyber-todo.search_error')); ?> + '</div>';
                                vendorResults.style.display = 'block';
                            });
                    }, 300);
                });

                // Hide results when clicking outside
                document.addEventListener('click', function(e) {
                    if (!vendorTypeahead.contains(e.target) && !vendorResults.contains(e.target)) {
                        vendorResults.style.display = 'none';
                    }
                });

                // Clear vendor selection
                clearVendorBtn.addEventListener('click', function() {
                    resetVendorSelection();
                });

                function selectVendor(id, name) {
                    hiddenReferenceType.value = 'vendor_onboarding_requests';
                    hiddenReferenceId.value = id;
                    vendorTypeahead.value = '';
                    vendorTypeahead.style.display = 'none';
                    vendorResults.style.display = 'none';
                    selectedVendorName.textContent = name;
                    selectedVendorDisplay.style.display = 'block';
                    // Hide error message when vendor is selected
                    document.getElementById('vendorError').style.display = 'none';
                }

                function resetVendorSelection() {
                    hiddenReferenceType.value = 'custom';
                    hiddenReferenceId.value = '0';
                    vendorTypeahead.value = '';
                    vendorTypeahead.style.display = 'block';
                    vendorResults.style.display = 'none';
                    selectedVendorDisplay.style.display = 'none';
                }

                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                // Form validation - require vendor selection
                const todoForm = document.getElementById('createTodoForm');
                if (todoForm) {
                    todoForm.addEventListener('submit', function(e) {
                        const vendorError = document.getElementById('vendorError');
                        // Check if a vendor has been selected (reference_id > 0)
                        if (hiddenReferenceId.value === '0' || hiddenReferenceId.value === '') {
                            e.preventDefault();
                            vendorError.style.display = 'block';
                            vendorTypeahead.focus();
                            return false;
                        }
                        vendorError.style.display = 'none';
                        return true;
                    });
                }
            }
        });
    </script>
    <?php endif; ?>

    <!-- Tier Change Modal -- same behavior as vendor-srs-details.php but
         without having to leave the todo list. Assign a tier, provide a
         justification, and get on with your day. -->
    <div id="tierChangeModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 8px 0; color: #333; font-size: 20px;"><?php echo e(t('cyber-todo.assign_vendor_tier_heading')); ?></h3>
            <div id="tierVendorName" style="margin: 0 0 20px 0; color: #666; font-size: 14px;"></div>

            <form method="POST" id="tierChangeForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="update_tier" value="1">
                <input type="hidden" name="tier_vendor_id" id="tierVendorId" value="">

                <div style="margin-bottom: 20px;">
                    <label for="new_tier" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;"><?php echo e(t('cyber-todo.tier_field_label')); ?></label>
                    <select id="new_tier" name="new_tier" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value=""><?php echo e(t('cyber-todo.tier_not_assigned')); ?></option>
                        <option value="1"><?php echo e(t('cyber-todo.tier_1_option')); ?></option>
                        <option value="2"><?php echo e(t('cyber-todo.tier_2_option')); ?></option>
                        <option value="3"><?php echo e(t('cyber-todo.tier_3_option')); ?></option>
                    </select>
                </div>

                <div style="margin-bottom: 20px;">
                    <label for="tier_justification" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;"><?php echo e(t('cyber-todo.justification_label')); ?> <span style="color: #dc2626;">*</span></label>
                    <textarea id="tier_justification" name="tier_justification" rows="4" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-family: inherit;" placeholder="<?php echo e(t('cyber-todo.justification_placeholder')); ?>" required></textarea>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" data-action="closeTierModal" style="padding: 10px 20px; border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 14px;"><?php echo e(t('cyber-todo.cancel_btn')); ?></button>
                    <button type="submit" style="padding: 10px 20px; border: none; background: #dc2626; color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500;"><?php echo e(t('cyber-todo.assign_tier_btn')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        function openTierModal(vendorId, vendorName) {
            document.getElementById('tierVendorId').value = vendorId;
            document.getElementById('tierVendorName').textContent = vendorName;
            document.getElementById('tier_justification').value = '';
            document.getElementById('new_tier').value = '';
            document.getElementById('tierChangeModal').style.display = 'flex';
            document.getElementById('new_tier').focus();
        }

        function closeTierModal() {
            document.getElementById('tierChangeModal').style.display = 'none';
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeTierModal();
        });

        document.getElementById('tierChangeForm').addEventListener('submit', function(e) {
            if (!document.getElementById('tier_justification').value.trim()) {
                e.preventDefault();
                alert(<?php echo json_encode(t('cyber-todo.justification_alert')); ?>);
                document.getElementById('tier_justification').focus();
            }
        });
    </script>

    <!-- Assign To typeahead -->
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        var users = <?php echo json_encode(array_map(function($u) {
            return ['id' => (int)$u['id'], 'name' => $u['full_name'] ?: $u['username'], 'username' => $u['username']];
        }, $activeUsers)); ?>;

        var highlighted = -1;

        document.addEventListener('input', function(e) {
            if (!e.target.classList.contains('assign-to-search')) return;
            var wrapper = e.target.closest('.form-group');
            var dropdown = wrapper.querySelector('.assign-to-dropdown');
            var hiddenInput = wrapper.querySelector('.assign-to-id');
            var query = e.target.value.toLowerCase().trim();

            // Clear selection when user types
            hiddenInput.value = '';
            highlighted = -1;

            if (!query) {
                dropdown.style.display = 'none';
                return;
            }

            var matches = users.filter(function(u) {
                return u.name.toLowerCase().indexOf(query) !== -1 || u.username.toLowerCase().indexOf(query) !== -1;
            });

            if (matches.length === 0) {
                dropdown.innerHTML = '<div class="no-results">' + <?php echo json_encode(t('cyber-todo.no_users_found')); ?> + '</div>';
            } else {
                dropdown.innerHTML = matches.map(function(u, i) {
                    return '<div class="assign-option" data-id="' + u.id + '" data-name="' + u.name.replace(/"/g, '&quot;') + '">'
                        + u.name + '<span class="assign-username">@' + u.username + '</span></div>';
                }).join('');
            }
            dropdown.style.display = 'block';
        });

        // Click on an option
        document.addEventListener('click', function(e) {
            var option = e.target.closest('.assign-option');
            if (option) {
                var wrapper = option.closest('.form-group');
                wrapper.querySelector('.assign-to-id').value = option.dataset.id;
                wrapper.querySelector('.assign-to-search').value = option.dataset.name;
                wrapper.querySelector('.assign-to-dropdown').style.display = 'none';
                return;
            }
            // Click outside closes all dropdowns
            document.querySelectorAll('.assign-to-dropdown').forEach(function(dd) {
                dd.style.display = 'none';
            });
        });

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (!e.target.classList.contains('assign-to-search')) return;
            var wrapper = e.target.closest('.form-group');
            var dropdown = wrapper.querySelector('.assign-to-dropdown');
            var options = dropdown.querySelectorAll('.assign-option');
            if (!options.length || dropdown.style.display === 'none') return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlighted = Math.min(highlighted + 1, options.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlighted = Math.max(highlighted - 1, 0);
            } else if (e.key === 'Enter' && highlighted >= 0) {
                e.preventDefault();
                options[highlighted].click();
                highlighted = -1;
                return;
            } else if (e.key === 'Escape') {
                dropdown.style.display = 'none';
                highlighted = -1;
                return;
            } else {
                return;
            }
            options.forEach(function(o, i) {
                o.classList.toggle('highlighted', i === highlighted);
            });
            options[highlighted].scrollIntoView({block: 'nearest'});
        });

        // Focus shows full list
        document.addEventListener('focusin', function(e) {
            if (!e.target.classList.contains('assign-to-search')) return;
            var wrapper = e.target.closest('.form-group');
            var dropdown = wrapper.querySelector('.assign-to-dropdown');
            var hiddenInput = wrapper.querySelector('.assign-to-id');
            // If already has a selection, don't re-show
            if (hiddenInput.value) return;
            var query = e.target.value.toLowerCase().trim();
            if (query) {
                e.target.dispatchEvent(new Event('input', {bubbles: true}));
            }
        });
    })();
    </script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
