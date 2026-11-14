<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Assessment Workflow Rules
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Centralized management of assessment workflow rules. Each rule defines:
 * "When [source template] is completed (always, or when [question] equals
 * [value]), automatically create [target templates] for that vendor", or
 * "When a vendor is approved, automatically create [target templates]".
 *
 * Rules fire on assessment completion or vendor approval. The recipient gets
 * an email with each new assessment link. Assessments a rule creates run the
 * rules again when they are completed, so rules chain. Duplicate checks keep a
 * rule from re-firing, and a template already in the chain is never re-created.
 */

require_once __DIR__ . '/../classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();
$assessmentService->initializeTables();

$ruleTriggerEvents = [VendorAssessmentService::RULE_EVENT_ASSESSMENT_COMPLETED, VendorAssessmentService::RULE_EVENT_VENDOR_APPROVED];
$ruleConditionOperators = ['equals', 'always'];
$ruleMaxTargets = 20;

/**
 * Validate a create/update POST into an assessment_workflow_rules row. Every
 * value is re-checked server-side: the trigger and condition are allowlisted,
 * every template must be an active template, the question must belong to the
 * source template, and the source can never be one of its own targets.
 * Returns ['row' => [...], 'targets' => [...]] or ['error' => message].
 */
$parseRulePost = function (array $post) use ($assessmentService, $ruleTriggerEvents, $ruleConditionOperators, $ruleMaxTargets) {
    $name = trim((string)($post['rule_name'] ?? ''));
    $description = trim((string)($post['rule_description'] ?? ''));
    $event = (string)($post['trigger_event'] ?? VendorAssessmentService::RULE_EVENT_ASSESSMENT_COMPLETED);
    if (!in_array($event, $ruleTriggerEvents, true)) $event = VendorAssessmentService::RULE_EVENT_ASSESSMENT_COMPLETED;
    $operator = (string)($post['condition_operator'] ?? 'equals');
    if (!in_array($operator, $ruleConditionOperators, true)) $operator = 'equals';
    $assignTo = (string)($post['assign_to'] ?? 'vendor');
    if (!in_array($assignTo, ['vendor', 'stakeholder', 'creator'], true)) $assignTo = 'vendor';

    $activeIds = [];
    foreach ($assessmentService->getTemplates(true) as $tpl) $activeIds[(int)$tpl['id']] = true;

    $targets = [];
    $rawTargets = $post['target_template_ids'] ?? [];
    if (!is_array($rawTargets)) $rawTargets = [$rawTargets];
    foreach ($rawTargets as $v) {
        $v = (int)$v;
        if ($v > 0 && isset($activeIds[$v])) $targets[$v] = $v;
    }
    $targets = array_values($targets);

    if ($name === '' || mb_strlen($name) > 255) return ['error' => t('admin_workflows.error_all_fields_required')];
    if (empty($targets)) return ['error' => t('admin_workflows.error_target_required')];
    if (count($targets) > $ruleMaxTargets) return ['error' => sprintf(t('admin_workflows.error_too_many_targets'), $ruleMaxTargets)];

    $sourceTemplateId = null;
    $questionId = null;
    $conditionValue = null;
    if ($event === VendorAssessmentService::RULE_EVENT_ASSESSMENT_COMPLETED) {
        $sourceTemplateId = (int)($post['source_template_id'] ?? 0);
        if (!$sourceTemplateId || !isset($activeIds[$sourceTemplateId])) return ['error' => t('admin_workflows.error_all_fields_required')];
        if (in_array($sourceTemplateId, $targets, true)) return ['error' => t('admin_workflows.error_templates_must_differ')];
        if ($operator === 'equals') {
            $questionId = (int)($post['question_id'] ?? 0);
            $conditionValue = trim((string)($post['condition_value'] ?? ''));
            if (!$questionId || $conditionValue === '' || mb_strlen($conditionValue) > 255) return ['error' => t('admin_workflows.error_all_fields_required')];
            if (!$assessmentService->questionBelongsToTemplate($questionId, $sourceTemplateId)) return ['error' => t('admin_workflows.error_question_not_in_template')];
        }
    } else {
        // Approval has no source template and no question.
        $operator = 'always';
    }

    return [
        'targets' => $targets,
        'row' => [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'trigger_event' => $event,
            'source_template_id' => $sourceTemplateId,
            'question_id' => $questionId,
            'condition_operator' => $operator,
            'condition_value' => $conditionValue,
            'target_template_id' => $targets[0],
            'target_template_ids' => json_encode($targets),
            'assign_to' => $assignTo,
        ],
    ];
};

// ============================================================
// POST HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_workflows.error_invalid_request');

    } elseif (isset($_POST['create_rule']) || isset($_POST['update_rule'])) {
        $isUpdate = isset($_POST['update_rule']);
        $ruleId = $isUpdate ? intval($_POST['rule_id'] ?? 0) : 0;
        $parsed = $parseRulePost($_POST);

        if ($isUpdate && (!$ruleId || !$db->fetchOne('SELECT id FROM assessment_workflow_rules WHERE id = ?', [$ruleId]))) {
            $error = t('admin_workflows.error_all_fields_required');
        } elseif (isset($parsed['error'])) {
            $error = $parsed['error'];
        } else {
            $row = $parsed['row'];
            try {
                if ($isUpdate) {
                    $db->update('assessment_workflow_rules', $row, 'id = :id', [':id' => $ruleId]);
                } else {
                    $row['is_active'] = 1;
                    $row['created_by'] = $user['id'];
                    $ruleId = $db->insert('assessment_workflow_rules', $row);
                }
                $auth->audit($user['id'], $isUpdate ? 'workflow_rule_update' : 'workflow_rule_create', 'assessment_workflow_rules', $isUpdate ? $ruleId : null, [
                    'new' => [
                        'name' => $row['name'],
                        'trigger_event' => $row['trigger_event'],
                        'source_template_id' => $row['source_template_id'],
                        'condition_operator' => $row['condition_operator'],
                        'target_template_ids' => $parsed['targets'],
                    ]
                ]);
                $success = $isUpdate ? t('admin_workflows.success_rule_updated') : t('admin_workflows.success_rule_created');
            } catch (Exception $e) {
                error_log(($isUpdate ? 'Update' : 'Create') . ' workflow rule error: ' . $e->getMessage());
                $error = $isUpdate ? t('admin_workflows.error_update_failed') : t('admin_workflows.error_create_failed');
            }
        }

    } elseif (isset($_POST['toggle_rule'])) {
        $ruleId = intval($_POST['rule_id'] ?? 0);
        if ($ruleId) {
            try {
                $rule = $db->fetchOne('SELECT is_active FROM assessment_workflow_rules WHERE id = ?', [$ruleId]);
                if ($rule) {
                    $newStatus = $rule['is_active'] ? 0 : 1;
                    $db->update('assessment_workflow_rules', ['is_active' => $newStatus], 'id = :id', [':id' => $ruleId]);
                    $auth->audit($user['id'], 'workflow_rule_toggle', 'assessment_workflow_rules', $ruleId, [
                        'new' => ['is_active' => $newStatus]
                    ]);
                    $success = $newStatus ? t('admin_workflows.success_rule_activated') : t('admin_workflows.success_rule_deactivated');
                }
            } catch (Exception $e) {
                error_log('Toggle workflow rule error: ' . $e->getMessage());
                $error = t('admin_workflows.error_toggle_failed');
            }
        }

    } elseif (isset($_POST['delete_rule'])) {
        $ruleId = intval($_POST['rule_id'] ?? 0);
        if ($ruleId) {
            try {
                $db->query('DELETE FROM assessment_workflow_rules WHERE id = ?', [$ruleId]);
                $auth->audit($user['id'], 'workflow_rule_delete', 'assessment_workflow_rules', $ruleId, []);
                $success = t('admin_workflows.success_rule_deleted');
            } catch (Exception $e) {
                error_log('Delete workflow rule error: ' . $e->getMessage());
                $error = t('admin_workflows.error_delete_failed');
            }
        }
    }
}

// ============================================================
// LOAD DATA
// ============================================================

// Load all rules with template and question names
$rules = [];
try {
    $rules = $db->fetchAll(
        "SELECT r.*,
                st.name AS source_template_name,
                st.category AS source_template_category,
                tt.name AS target_template_name,
                tt.category AS target_template_category,
                q.question_text,
                q.question_type
         FROM assessment_workflow_rules r
         LEFT JOIN assessment_templates st ON r.source_template_id = st.id
         LEFT JOIN assessment_templates tt ON r.target_template_id = tt.id
         LEFT JOIN assessment_questions q ON r.question_id = q.id
         ORDER BY r.created_at DESC"
    );
} catch (Exception $e) {
    // Table might not exist yet
}

// Load only active templates for dropdowns
$allTemplates = $assessmentService->getTemplates(true);

// Every template's name (active or not) so a rule's targets can be listed.
$templateNames = [];
try {
    foreach ($db->fetchAll('SELECT id, name, category FROM assessment_templates') as $tplRow) {
        $templateNames[(int)$tplRow['id']] = $tplRow;
    }
} catch (Exception $e) {
    // Table might not exist yet
}

$categoryLabels = [
    'vendor_assessment' => t('admin_workflows.category_vendor_assessment'),
    'procurement' => t('admin_workflows.category_procurement'),
    'onboarding' => t('admin_workflows.category_onboarding'),
    'user_request' => t('admin_assessment-templates.cat_user_request'),
];

// Build template→questions JSON for dynamic dropdowns
$templateQuestionData = [];
foreach ($allTemplates as $tpl) {
    $questions = [];
    try {
        $sections = $assessmentService->getSections($tpl['id']);
        foreach ($sections as $sec) {
            $sectionQuestions = $assessmentService->getQuestions($sec['id']);
            foreach ($sectionQuestions as $q) {
                $options = [];
                if (!empty($q['options'])) {
                    if (is_string($q['options'])) {
                        $decoded = json_decode($q['options'], true);
                        $options = is_array($decoded) ? $decoded : [];
                    } elseif (is_array($q['options'])) {
                        $options = $q['options'];
                    }
                }
                $questions[] = [
                    'id' => (int)$q['id'],
                    'text' => $q['question_text'],
                    'type' => $q['question_type'],
                    'options' => $options,
                    'section' => $sec['name'],
                ];
            }
        }
    } catch (Exception $e) {
        // Skip templates with issues
    }
    $templateQuestionData[$tpl['id']] = $questions;
}
?>

<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_workflows.page_title')); ?></h1>
    <p><?php echo e(t('admin_workflows.page_subtitle')); ?></p>
</div>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3 style="margin:0;"><?php echo e(t('admin_workflows.rules_heading')); ?></h3>
        <button class="btn btn-primary" data-action="openCreateRule"><?php echo e(t('admin_workflows.create_rule_button')); ?></button>
    </div>

    <?php if (empty($rules)): ?>
    <div style="text-align:center; padding:40px; color:#666;">
        <h3><?php echo e(t('admin_workflows.empty_heading')); ?></h3>
        <p><?php echo e(t('admin_workflows.empty_text')); ?></p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th><?php echo e(t('admin_workflows.th_rule_name')); ?></th>
                    <th><?php echo e(t('admin_workflows.th_when')); ?></th>
                    <th><?php echo e(t('admin_workflows.th_condition')); ?></th>
                    <th><?php echo e(t('admin_workflows.th_then_create')); ?></th>
                    <th><?php echo e(t('admin_workflows.th_assign_to')); ?></th>
                    <th><?php echo e(t('admin_workflows.th_status')); ?></th>
                    <th><?php echo e(t('admin_workflows.th_actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rule): ?>
                <tr>
                    <td>
                        <strong><?php echo e($rule['name']); ?></strong>
                        <?php if ($rule['description']): ?>
                        <br><span style="font-size:12px; color:#666;"><?php echo e(mb_strimwidth($rule['description'], 0, 60, '...')); ?></span>
                        <?php endif; ?>
                    </td>
                    <?php
                    $ruleEvent = $rule['trigger_event'] ?? 'assessment_completed';
                    $ruleOperator = $rule['condition_operator'] ?? 'equals';
                    $ruleTargets = VendorAssessmentService::ruleTargetTemplateIds($rule);
                    ?>
                    <td>
                        <?php if ($ruleEvent === 'vendor_approved'): ?>
                        <?php echo e(t('admin_workflows.event_vendor_approved_short')); ?>
                        <?php else: ?>
                        <?php echo e($rule['source_template_name'] ?? t('admin_workflows.deleted_template')); ?>
                        <br><span class="badge badge-blue" style="font-size:10px;"><?php echo e($categoryLabels[$rule['source_template_category'] ?? ''] ?? $rule['source_template_category'] ?? ''); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:240px;">
                        <?php if ($ruleOperator === 'always' || $ruleEvent === 'vendor_approved'): ?>
                        <span class="badge badge-purple" style="font-size:11px;"><?php echo e(t('admin_workflows.condition_always_short')); ?></span>
                        <?php else: ?>
                        <span style="font-size:13px;"><?php echo e(mb_strimwidth($rule['question_text'] ?? t('admin_workflows.deleted_question'), 0, 60, '...')); ?></span>
                        <br><span style="font-size:12px; color:#666;"><?php echo e(t('admin_workflows.th_equals')); ?></span>
                        <code style="background:#f3f4f6; padding:2px 6px; border-radius:3px; font-size:12px;"><?php echo e($rule['condition_value']); ?></code>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php foreach ($ruleTargets as $i => $tid): $tplRow = $templateNames[$tid] ?? null; ?>
                        <?php if ($i > 0): ?><br><?php endif; ?>
                        <?php echo e($tplRow['name'] ?? t('admin_workflows.deleted_template')); ?>
                        <?php if ($tplRow): ?><span class="badge badge-blue" style="font-size:10px;"><?php echo e($categoryLabels[$tplRow['category'] ?? ''] ?? $tplRow['category'] ?? ''); ?></span><?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php
                        $assignLabels = ['vendor' => t('admin_workflows.assign_vendor'), 'stakeholder' => t('admin_workflows.assign_stakeholder'), 'creator' => t('admin_workflows.assign_creator')];
                        echo e($assignLabels[$rule['assign_to'] ?? 'vendor'] ?? t('admin_workflows.assign_vendor'));
                        ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $rule['is_active'] ? 'badge-success' : ''; ?>" style="<?php echo !$rule['is_active'] ? 'background:#e5e7eb; color:#6b7280;' : ''; ?>">
                            <?php echo e($rule['is_active'] ? t('admin_workflows.status_active') : t('admin_workflows.status_inactive')); ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm btn-primary" data-action="editRule" data-arg="<?php echo e(json_encode([
                            'id' => $rule['id'],
                            'name' => $rule['name'],
                            'description' => $rule['description'] ?? '',
                            'trigger_event' => $ruleEvent,
                            'condition_operator' => $ruleOperator,
                            'source_template_id' => $rule['source_template_id'] !== null ? (int)$rule['source_template_id'] : null,
                            'question_id' => $rule['question_id'] !== null ? (int)$rule['question_id'] : null,
                            'condition_value' => $rule['condition_value'] ?? '',
                            'target_template_ids' => $ruleTargets,
                            'assign_to' => $rule['assign_to'] ?? 'vendor',
                        ])); ?>"><?php echo e(t('admin_workflows.action_edit')); ?></button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="rule_id" value="<?php echo (int)$rule['id']; ?>">
                            <button type="submit" name="toggle_rule" class="btn btn-sm" style="background:<?php echo $rule['is_active'] ? '#6b7280' : '#22c55e'; ?>; color:white;">
                                <?php echo e($rule['is_active'] ? t('admin_workflows.action_disable') : t('admin_workflows.action_enable')); ?>
                            </button>
                        </form>
                        <form method="POST" style="display:inline;" data-confirm="<?php echo e(t('admin_workflows.confirm_delete_rule')); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="rule_id" value="<?php echo (int)$rule['id']; ?>">
                            <button type="submit" name="delete_rule" class="btn btn-sm" style="background:#ef4444; color:white;"><?php echo e(t('admin_workflows.action_delete')); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- How It Works info card -->
<div class="card">
    <h3><?php echo e(t('admin_workflows.how_heading')); ?></h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-top:15px;">
        <div style="text-align:center; padding:15px;">
            <div style="font-size:28px; margin-bottom:8px;">1.</div>
            <strong><?php echo e(t('admin_workflows.step1_heading')); ?></strong>
            <p style="font-size:13px; color:#666; margin-top:5px;"><?php echo e(t('admin_workflows.step1_text')); ?></p>
        </div>
        <div style="text-align:center; padding:15px;">
            <div style="font-size:28px; margin-bottom:8px;">2.</div>
            <strong><?php echo e(t('admin_workflows.step2_heading')); ?></strong>
            <p style="font-size:13px; color:#666; margin-top:5px;"><?php echo e(t('admin_workflows.step2_text')); ?></p>
        </div>
        <div style="text-align:center; padding:15px;">
            <div style="font-size:28px; margin-bottom:8px;">3.</div>
            <strong><?php echo e(t('admin_workflows.step3_heading')); ?></strong>
            <p style="font-size:13px; color:#666; margin-top:5px;"><?php echo e(t('admin_workflows.step3_text')); ?></p>
        </div>
    </div>
</div>

<!-- Create/Edit Rule Modal -->
<style>
/* The modal fits the viewport on any screen size / zoom: the form body
   scrolls and the Create/Update + Cancel buttons stay pinned in view. */
#ruleModal .modal-content.rule-modal-content {
    max-width: 640px; margin: 3vh auto; padding: 0;
    max-height: 94vh; display: flex; flex-direction: column; overflow: hidden;
}
#ruleModal .rule-modal-header { padding: 20px 25px 10px; flex: 0 0 auto; }
#ruleModal #ruleForm { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; margin: 0; }
#ruleModal .rule-modal-body { padding: 0 25px; overflow-y: auto; flex: 1 1 auto; min-height: 0; }
#ruleModal .rule-modal-footer {
    display: flex; gap: 10px; padding: 14px 25px; flex: 0 0 auto;
    border-top: 1px solid #e5e7eb; background: #fff; border-radius: 0 0 8px 8px;
}
#ruleModal .rule-target-list {
    max-height: 190px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 6px;
    background: #fff; padding: 6px 10px;
}
#ruleModal .rule-target-list label { display: flex; align-items: flex-start; gap: 8px; font-weight: normal; margin: 4px 0; cursor: pointer; }
#ruleModal .rule-target-list input { margin-top: 3px; }
#ruleModal .rule-target-list label.is-source { opacity: .45; cursor: not-allowed; }
@media (max-height: 520px) {
    #ruleModal .modal-content.rule-modal-content { margin: 1vh auto; max-height: 98vh; }
}
</style>
<div id="ruleModal" class="modal">
    <div class="modal-content rule-modal-content">
        <div class="rule-modal-header">
            <span class="close" data-close="ruleModal">&times;</span>
            <h3 id="ruleModalTitle" style="margin:0; color:var(--theme-header-color);"><?php echo e(t('admin_workflows.modal_title_create')); ?></h3>
        </div>

        <form method="POST" action="admin.php?section=workflows" id="ruleForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="rule_id" id="ruleId" value="">

            <div class="rule-modal-body">
            <div class="form-group">
                <label for="ruleName"><?php echo e(t('admin_workflows.label_rule_name')); ?> <span style="color:#ef4444;">*</span></label>
                <input type="text" id="ruleName" name="rule_name" class="form-control" maxlength="255" placeholder="<?php echo e(t('admin_workflows.placeholder_rule_name')); ?>" required>
            </div>

            <div class="form-group">
                <label for="ruleDescription"><?php echo e(t('admin_workflows.label_description')); ?></label>
                <textarea id="ruleDescription" name="rule_description" class="form-control" rows="2" placeholder="<?php echo e(t('admin_workflows.placeholder_description')); ?>"></textarea>
            </div>

            <div style="border:1px solid #e5e7eb; border-radius:8px; padding:15px; margin:15px 0; background:#f9fafb;">
                <label style="font-weight:600; font-size:13px; margin-bottom:10px; display:block;"><?php echo e(t('admin_workflows.section_condition')); ?></label>

                <div class="form-group">
                    <label for="ruleTriggerEvent"><?php echo e(t('admin_workflows.label_trigger_event')); ?></label>
                    <select id="ruleTriggerEvent" name="trigger_event" class="form-control">
                        <option value="assessment_completed"><?php echo e(t('admin_workflows.event_assessment_completed')); ?></option>
                        <option value="vendor_approved"><?php echo e(t('admin_workflows.event_vendor_approved')); ?></option>
                    </select>
                    <div class="form-help" id="ruleApprovedHelp" style="font-size:11px; color:#888; margin-top:4px; display:none;"><?php echo e(t('admin_workflows.event_vendor_approved_help')); ?></div>
                </div>

                <div id="ruleCompletedFields">
                <div class="form-group">
                    <label for="ruleSourceTemplate"><?php echo e(t('admin_workflows.label_source_template')); ?></label>
                    <select id="ruleSourceTemplate" name="source_template_id" class="form-control" data-action="onSourceTemplateChange" required>
                        <option value=""><?php echo e(t('admin_workflows.opt_select_template')); ?></option>
                        <?php foreach ($allTemplates as $tpl): ?>
                        <option value="<?php echo (int)$tpl['id']; ?>"><?php echo e($tpl['name']); ?> (<?php echo e($categoryLabels[$tpl['category'] ?? 'vendor_assessment'] ?? $tpl['category']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="ruleConditionOperator"><?php echo e(t('admin_workflows.label_condition_operator')); ?></label>
                    <select id="ruleConditionOperator" name="condition_operator" class="form-control">
                        <option value="always"><?php echo e(t('admin_workflows.condition_always')); ?></option>
                        <option value="equals"><?php echo e(t('admin_workflows.condition_equals')); ?></option>
                    </select>
                </div>

                <div class="form-row" id="ruleQuestionFields">
                    <div class="form-group">
                        <label for="ruleQuestion"><?php echo e(t('admin_workflows.label_question')); ?></label>
                        <select id="ruleQuestion" name="question_id" class="form-control" data-action="onQuestionChange">
                            <option value=""><?php echo e(t('admin_workflows.opt_select_template_first')); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ruleConditionValue"><?php echo e(t('admin_workflows.label_condition_value')); ?></label>
                        <select id="ruleConditionValue" name="condition_value" class="form-control">
                            <option value=""><?php echo e(t('admin_workflows.opt_select_question_first')); ?></option>
                        </select>
                        <input type="text" id="ruleConditionValueText" name="condition_value_text" class="form-control" maxlength="255" placeholder="<?php echo e(t('admin_workflows.placeholder_enter_value')); ?>" style="display:none;">
                    </div>
                </div>
                </div>
            </div>

            <div style="border:1px solid #e5e7eb; border-radius:8px; padding:15px; margin:15px 0; background:#f0fdf4;">
                <label style="font-weight:600; font-size:13px; margin-bottom:10px; display:block;"><?php echo e(t('admin_workflows.section_action')); ?></label>

                <div class="form-group">
                    <label id="ruleTargetLabel"><?php echo e(t('admin_workflows.label_target_templates')); ?></label>
                    <div class="rule-target-list" id="ruleTargetList" role="group" aria-labelledby="ruleTargetLabel">
                        <?php foreach ($allTemplates as $tpl): ?>
                        <label data-target-id="<?php echo (int)$tpl['id']; ?>">
                            <input type="checkbox" name="target_template_ids[]" value="<?php echo (int)$tpl['id']; ?>">
                            <span><?php echo e($tpl['name']); ?> <span style="color:#888; font-size:12px;">(<?php echo e($categoryLabels[$tpl['category'] ?? 'vendor_assessment'] ?? $tpl['category']); ?>)</span></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-help" style="font-size:11px; color:#888; margin-top:4px;"><?php echo e(t('admin_workflows.target_templates_help')); ?></div>
                </div>

                <div class="form-group">
                    <label for="ruleAssignTo"><?php echo e(t('admin_workflows.label_assign_to')); ?></label>
                    <select id="ruleAssignTo" name="assign_to" class="form-control">
                        <option value="vendor"><?php echo e(t('admin_workflows.assign_option_vendor')); ?></option>
                        <option value="stakeholder"><?php echo e(t('admin_workflows.assign_option_stakeholder')); ?></option>
                        <option value="creator"><?php echo e(t('admin_workflows.assign_option_creator')); ?></option>
                    </select>
                    <div class="form-help" style="font-size:11px; color:#888; margin-top:4px;"><?php echo e(t('admin_workflows.assign_help')); ?></div>
                </div>
            </div>
            </div>

            <div class="rule-modal-footer">
                <button type="submit" id="ruleSubmitBtn" name="create_rule" class="btn btn-primary"><?php echo e(t('admin_workflows.create_rule_submit')); ?></button>
                <button type="button" class="btn btn-secondary" data-close="ruleModal"><?php echo e(t('admin_workflows.cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?php echo cspNonce(); ?>">
// Pre-loaded template question data for dynamic dropdowns
var templateQuestionData = <?php echo json_encode($templateQuestionData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

// Track current question data for value population
var currentQuestions = [];

function onSourceTemplateChange(templateId) {
    var qSelect = document.getElementById('ruleQuestion');
    var vSelect = document.getElementById('ruleConditionValue');
    var vText = document.getElementById('ruleConditionValueText');

    // Reset downstream dropdowns
    qSelect.innerHTML = '<option value="">' + <?php echo json_encode(t('admin_workflows.opt_select_question')); ?> + '</option>';
    vSelect.innerHTML = '<option value="">' + <?php echo json_encode(t('admin_workflows.opt_select_question_first')); ?> + '</option>';
    vSelect.style.display = '';
    vText.style.display = 'none';
    vText.value = '';

    if (!templateId || !templateQuestionData[templateId]) {
        currentQuestions = [];
        return;
    }

    currentQuestions = templateQuestionData[templateId];
    var currentSection = '';
    currentQuestions.forEach(function(q) {
        var opt = document.createElement('option');
        opt.value = q.id;
        var prefix = q.section ? '[' + q.section + '] ' : '';
        var text = q.text.length > 60 ? q.text.substring(0, 57) + '...' : q.text;
        opt.textContent = prefix + text;
        opt.setAttribute('data-type', q.type);
        qSelect.appendChild(opt);
    });
}

function onQuestionChange(questionId) {
    var vSelect = document.getElementById('ruleConditionValue');
    var vText = document.getElementById('ruleConditionValueText');

    vSelect.innerHTML = '<option value="">--</option>';
    vSelect.style.display = '';
    vText.style.display = 'none';
    vText.value = '';

    if (!questionId) return;

    // Find the question in current data
    var question = null;
    for (var i = 0; i < currentQuestions.length; i++) {
        if (currentQuestions[i].id == questionId) {
            question = currentQuestions[i];
            break;
        }
    }

    if (!question) return;

    // For types with predefined options, show dropdown
    var hasOptions = ['select', 'radio', 'checkbox', 'button_group', 'button_group_multi'].indexOf(question.type) !== -1;
    if (hasOptions && question.options && question.options.length > 0) {
        question.options.forEach(function(opt) {
            var el = document.createElement('option');
            el.value = opt;
            el.textContent = opt;
            vSelect.appendChild(el);
        });
    } else {
        // For text/textarea/number/email types, show a free-text input instead
        vSelect.style.display = 'none';
        vSelect.removeAttribute('name');
        vText.style.display = '';
        vText.setAttribute('name', 'condition_value');
    }
}

// Show the fields that apply to the chosen trigger and condition, and keep
// `required` only on visible inputs (a hidden required field blocks submit).
function syncRuleFormVisibility() {
    var event = document.getElementById('ruleTriggerEvent').value;
    var completed = event === 'assessment_completed';
    var operator = document.getElementById('ruleConditionOperator').value;
    var needsQuestion = completed && operator === 'equals';

    document.getElementById('ruleCompletedFields').style.display = completed ? '' : 'none';
    document.getElementById('ruleApprovedHelp').style.display = completed ? 'none' : '';
    document.getElementById('ruleQuestionFields').style.display = needsQuestion ? '' : 'none';

    var src = document.getElementById('ruleSourceTemplate');
    var q = document.getElementById('ruleQuestion');
    var vSelect = document.getElementById('ruleConditionValue');
    var vText = document.getElementById('ruleConditionValueText');
    src.required = completed;
    q.required = needsQuestion;
    vSelect.required = needsQuestion && vSelect.style.display !== 'none';
    vText.required = needsQuestion && vText.style.display !== 'none';
    syncSourceTargetLock();
}

// A template can't trigger itself: disable (and untick) the source in the target list.
function syncSourceTargetLock() {
    var completed = document.getElementById('ruleTriggerEvent').value === 'assessment_completed';
    var srcId = completed ? document.getElementById('ruleSourceTemplate').value : '';
    document.querySelectorAll('#ruleTargetList label[data-target-id]').forEach(function(label) {
        var box = label.querySelector('input[type="checkbox"]');
        var isSource = srcId !== '' && label.getAttribute('data-target-id') === srcId;
        label.classList.toggle('is-source', isSource);
        box.disabled = isSource;
        if (isSource) box.checked = false;
    });
}

function setRuleTargets(ids) {
    var wanted = {};
    (ids || []).forEach(function(id) { wanted[String(id)] = true; });
    document.querySelectorAll('#ruleTargetList input[type="checkbox"]').forEach(function(box) {
        box.checked = !!wanted[box.value];
    });
}

function openCreateRule() {
    resetRuleModal();
    openRuleModal();
}

function openRuleModal() {
    var modal = document.getElementById('ruleModal');
    modal.style.display = 'block';
    var body = modal.querySelector('.rule-modal-body');
    if (body) body.scrollTop = 0;
}

function resetRuleModal() {
    document.getElementById('ruleModalTitle').textContent = <?php echo json_encode(t('admin_workflows.modal_title_create')); ?>;
    document.getElementById('ruleId').value = '';
    document.getElementById('ruleName').value = '';
    document.getElementById('ruleDescription').value = '';
    document.getElementById('ruleTriggerEvent').value = 'assessment_completed';
    document.getElementById('ruleConditionOperator').value = 'always';
    document.getElementById('ruleSourceTemplate').value = '';
    document.getElementById('ruleQuestion').innerHTML = '<option value="">' + <?php echo json_encode(t('admin_workflows.opt_select_template_first')); ?> + '</option>';
    document.getElementById('ruleConditionValue').innerHTML = '<option value="">' + <?php echo json_encode(t('admin_workflows.opt_select_question_first')); ?> + '</option>';
    document.getElementById('ruleConditionValue').style.display = '';
    document.getElementById('ruleConditionValue').setAttribute('name', 'condition_value');
    document.getElementById('ruleConditionValueText').style.display = 'none';
    document.getElementById('ruleConditionValueText').removeAttribute('name');
    document.getElementById('ruleConditionValueText').value = '';
    setRuleTargets([]);
    document.getElementById('ruleAssignTo').value = 'vendor';

    var btn = document.getElementById('ruleSubmitBtn');
    btn.name = 'create_rule';
    btn.textContent = <?php echo json_encode(t('admin_workflows.create_rule_submit')); ?>;
    syncRuleFormVisibility();
}

function editRule(dataStr) {
    var data = typeof dataStr === 'string' ? JSON.parse(dataStr) : dataStr;
    resetRuleModal();
    document.getElementById('ruleModalTitle').textContent = <?php echo json_encode(t('admin_workflows.modal_title_edit')); ?>;
    document.getElementById('ruleId').value = data.id;
    document.getElementById('ruleName').value = data.name;
    document.getElementById('ruleDescription').value = data.description || '';
    document.getElementById('ruleTriggerEvent').value = data.trigger_event === 'vendor_approved' ? 'vendor_approved' : 'assessment_completed';
    document.getElementById('ruleConditionOperator').value = data.condition_operator === 'always' ? 'always' : 'equals';

    if (data.source_template_id) {
        // Set source template and trigger question population
        document.getElementById('ruleSourceTemplate').value = data.source_template_id;
        onSourceTemplateChange(String(data.source_template_id));
    }

    if (data.condition_operator !== 'always' && data.question_id) {
        // Set question after population
        document.getElementById('ruleQuestion').value = data.question_id;
        onQuestionChange(String(data.question_id));

        // Set condition value: try dropdown first, fall back to text input
        var vSelect = document.getElementById('ruleConditionValue');
        var vText = document.getElementById('ruleConditionValueText');
        if (vSelect.style.display !== 'none') {
            vSelect.value = data.condition_value;
            // If value not found in options, switch to text
            if (vSelect.value !== data.condition_value) {
                vSelect.style.display = 'none';
                vSelect.removeAttribute('name');
                vText.style.display = '';
                vText.setAttribute('name', 'condition_value');
                vText.value = data.condition_value;
            }
        } else {
            vText.value = data.condition_value;
        }
    }

    setRuleTargets(data.target_template_ids || []);
    document.getElementById('ruleAssignTo').value = data.assign_to || 'vendor';

    var btn = document.getElementById('ruleSubmitBtn');
    btn.name = 'update_rule';
    btn.textContent = <?php echo json_encode(t('admin_workflows.update_rule_submit')); ?>;

    syncRuleFormVisibility();
    openRuleModal();
}

document.getElementById('ruleTriggerEvent').addEventListener('change', syncRuleFormVisibility);
document.getElementById('ruleConditionOperator').addEventListener('change', syncRuleFormVisibility);
// Runs after the delegated onSourceTemplateChange handler on document.body.
document.getElementById('ruleSourceTemplate').addEventListener('change', function() {
    setTimeout(syncRuleFormVisibility, 0);
});
document.getElementById('ruleQuestion').addEventListener('change', function() {
    setTimeout(syncRuleFormVisibility, 0);
});

// Fix form submission: ensure condition_value name is set on the right input,
// and require at least one target template.
document.getElementById('ruleForm').addEventListener('submit', function(e) {
    var anyTarget = document.querySelector('#ruleTargetList input[type="checkbox"]:checked');
    if (!anyTarget) {
        e.preventDefault();
        alert(<?php echo json_encode(t('admin_workflows.error_target_required')); ?>);
        return;
    }
    var vSelect = document.getElementById('ruleConditionValue');
    var vText = document.getElementById('ruleConditionValueText');
    if (vText.style.display !== 'none') {
        vSelect.removeAttribute('name');
        vText.setAttribute('name', 'condition_value');
    } else {
        vSelect.setAttribute('name', 'condition_value');
        vText.removeAttribute('name');
    }
});
</script>
