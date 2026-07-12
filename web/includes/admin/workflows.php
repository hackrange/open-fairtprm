<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Assessment Workflow Rules
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Centralized management of assessment workflow rules. Each rule defines:
 * "When [source template] is completed and [question] equals [value],
 * automatically create [target template] for that vendor."
 *
 * Rules fire on assessment completion. The vendor gets an email with the
 * new assessment link. Duplicate checks prevent re-triggering the same rule.
 */

require_once __DIR__ . '/../classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();
$assessmentService->initializeTables();

// ============================================================
// POST HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_workflows.error_invalid_request');

    } elseif (isset($_POST['create_rule'])) {
        $name = trim($_POST['rule_name'] ?? '');
        $sourceTemplateId = intval($_POST['source_template_id'] ?? 0);
        $questionId = intval($_POST['question_id'] ?? 0);
        $conditionValue = trim($_POST['condition_value'] ?? '');
        $targetTemplateId = intval($_POST['target_template_id'] ?? 0);
        $description = trim($_POST['rule_description'] ?? '');
        $assignTo = $_POST['assign_to'] ?? 'vendor';
        if (!in_array($assignTo, ['vendor', 'stakeholder', 'creator'])) $assignTo = 'vendor';

        if (empty($name) || !$sourceTemplateId || !$questionId || $conditionValue === '' || !$targetTemplateId) {
            $error = t('admin_workflows.error_all_fields_required');
        } elseif ($sourceTemplateId === $targetTemplateId) {
            $error = t('admin_workflows.error_templates_must_differ');
        } else {
            try {
                $db->insert('assessment_workflow_rules', [
                    'name' => $name,
                    'description' => $description ?: null,
                    'source_template_id' => $sourceTemplateId,
                    'question_id' => $questionId,
                    'condition_operator' => 'equals',
                    'condition_value' => $conditionValue,
                    'target_template_id' => $targetTemplateId,
                    'assign_to' => $assignTo,
                    'is_active' => 1,
                    'created_by' => $user['id'],
                ]);
                $auth->audit($user['id'], 'workflow_rule_create', 'assessment_workflow_rules', null, [
                    'new' => ['name' => $name, 'source_template_id' => $sourceTemplateId, 'target_template_id' => $targetTemplateId]
                ]);
                $success = t('admin_workflows.success_rule_created');
            } catch (Exception $e) {
                error_log('Create workflow rule error: ' . $e->getMessage());
                $error = t('admin_workflows.error_create_failed');
            }
        }

    } elseif (isset($_POST['update_rule'])) {
        $ruleId = intval($_POST['rule_id'] ?? 0);
        $name = trim($_POST['rule_name'] ?? '');
        $sourceTemplateId = intval($_POST['source_template_id'] ?? 0);
        $questionId = intval($_POST['question_id'] ?? 0);
        $conditionValue = trim($_POST['condition_value'] ?? '');
        $targetTemplateId = intval($_POST['target_template_id'] ?? 0);
        $description = trim($_POST['rule_description'] ?? '');
        $assignTo = $_POST['assign_to'] ?? 'vendor';
        if (!in_array($assignTo, ['vendor', 'stakeholder', 'creator'])) $assignTo = 'vendor';

        if (!$ruleId || empty($name) || !$sourceTemplateId || !$questionId || $conditionValue === '' || !$targetTemplateId) {
            $error = t('admin_workflows.error_all_fields_required');
        } elseif ($sourceTemplateId === $targetTemplateId) {
            $error = t('admin_workflows.error_templates_must_differ');
        } else {
            try {
                $db->update('assessment_workflow_rules', [
                    'name' => $name,
                    'description' => $description ?: null,
                    'source_template_id' => $sourceTemplateId,
                    'question_id' => $questionId,
                    'condition_operator' => 'equals',
                    'condition_value' => $conditionValue,
                    'target_template_id' => $targetTemplateId,
                    'assign_to' => $assignTo,
                ], 'id = :id', [':id' => $ruleId]);
                $auth->audit($user['id'], 'workflow_rule_update', 'assessment_workflow_rules', $ruleId, [
                    'new' => ['name' => $name, 'source_template_id' => $sourceTemplateId, 'target_template_id' => $targetTemplateId]
                ]);
                $success = t('admin_workflows.success_rule_updated');
            } catch (Exception $e) {
                error_log('Update workflow rule error: ' . $e->getMessage());
                $error = t('admin_workflows.error_update_failed');
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

$categoryLabels = [
    'vendor_assessment' => t('admin_workflows.category_vendor_assessment'),
    'procurement' => t('admin_workflows.category_procurement'),
    'onboarding' => t('admin_workflows.category_onboarding'),
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
                    <th><?php echo e(t('admin_workflows.th_when_template_completed')); ?></th>
                    <th><?php echo e(t('admin_workflows.th_if_question')); ?></th>
                    <th><?php echo e(t('admin_workflows.th_equals')); ?></th>
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
                    <td>
                        <?php echo e($rule['source_template_name'] ?? t('admin_workflows.deleted_template')); ?>
                        <br><span class="badge badge-blue" style="font-size:10px;"><?php echo e($categoryLabels[$rule['source_template_category'] ?? ''] ?? $rule['source_template_category'] ?? ''); ?></span>
                    </td>
                    <td style="max-width:200px;">
                        <span style="font-size:13px;"><?php echo e(mb_strimwidth($rule['question_text'] ?? t('admin_workflows.deleted_question'), 0, 60, '...')); ?></span>
                    </td>
                    <td>
                        <code style="background:#f3f4f6; padding:2px 6px; border-radius:3px; font-size:12px;"><?php echo e($rule['condition_value']); ?></code>
                    </td>
                    <td>
                        <?php echo e($rule['target_template_name'] ?? t('admin_workflows.deleted_template')); ?>
                        <br><span class="badge badge-blue" style="font-size:10px;"><?php echo e($categoryLabels[$rule['target_template_category'] ?? ''] ?? $rule['target_template_category'] ?? ''); ?></span>
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
                            'source_template_id' => $rule['source_template_id'],
                            'question_id' => $rule['question_id'],
                            'condition_value' => $rule['condition_value'],
                            'target_template_id' => $rule['target_template_id'],
                            'assign_to' => $rule['assign_to'] ?? 'vendor',
                        ])); ?>"><?php echo e(t('admin_workflows.action_edit')); ?></button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="rule_id" value="<?php echo $rule['id']; ?>">
                            <button type="submit" name="toggle_rule" class="btn btn-sm" style="background:<?php echo $rule['is_active'] ? '#6b7280' : '#22c55e'; ?>; color:white;">
                                <?php echo e($rule['is_active'] ? t('admin_workflows.action_disable') : t('admin_workflows.action_enable')); ?>
                            </button>
                        </form>
                        <form method="POST" style="display:inline;" data-confirm="<?php echo e(t('admin_workflows.confirm_delete_rule')); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="rule_id" value="<?php echo $rule['id']; ?>">
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
<div id="ruleModal" class="modal">
    <div class="modal-content" style="max-width:600px;">
        <span class="close" data-close="ruleModal">&times;</span>
        <h3 id="ruleModalTitle" style="margin-top:0; color:var(--theme-header-color);"><?php echo e(t('admin_workflows.modal_title_create')); ?></h3>

        <form method="POST" action="admin.php?section=workflows" id="ruleForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="rule_id" id="ruleId" value="">

            <div class="form-group">
                <label for="ruleName"><?php echo e(t('admin_workflows.label_rule_name')); ?> <span style="color:#ef4444;">*</span></label>
                <input type="text" id="ruleName" name="rule_name" class="form-control" placeholder="<?php echo e(t('admin_workflows.placeholder_rule_name')); ?>" required>
            </div>

            <div class="form-group">
                <label for="ruleDescription"><?php echo e(t('admin_workflows.label_description')); ?></label>
                <textarea id="ruleDescription" name="rule_description" class="form-control" rows="2" placeholder="<?php echo e(t('admin_workflows.placeholder_description')); ?>"></textarea>
            </div>

            <div style="border:1px solid #e5e7eb; border-radius:8px; padding:15px; margin:15px 0; background:#f9fafb;">
                <label style="font-weight:600; font-size:13px; margin-bottom:10px; display:block;"><?php echo e(t('admin_workflows.section_condition')); ?></label>

                <div class="form-group">
                    <label for="ruleSourceTemplate"><?php echo e(t('admin_workflows.label_source_template')); ?></label>
                    <select id="ruleSourceTemplate" name="source_template_id" class="form-control" data-action="onSourceTemplateChange" required>
                        <option value=""><?php echo e(t('admin_workflows.opt_select_template')); ?></option>
                        <?php foreach ($allTemplates as $tpl): ?>
                        <option value="<?php echo $tpl['id']; ?>"><?php echo e($tpl['name']); ?> (<?php echo e($categoryLabels[$tpl['category'] ?? 'vendor_assessment'] ?? $tpl['category']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="ruleQuestion"><?php echo e(t('admin_workflows.label_question')); ?></label>
                        <select id="ruleQuestion" name="question_id" class="form-control" data-action="onQuestionChange" required>
                            <option value=""><?php echo e(t('admin_workflows.opt_select_template_first')); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ruleConditionValue"><?php echo e(t('admin_workflows.label_condition_value')); ?></label>
                        <select id="ruleConditionValue" name="condition_value" class="form-control" required>
                            <option value=""><?php echo e(t('admin_workflows.opt_select_question_first')); ?></option>
                        </select>
                        <input type="text" id="ruleConditionValueText" name="condition_value_text" class="form-control" placeholder="<?php echo e(t('admin_workflows.placeholder_enter_value')); ?>" style="display:none;">
                    </div>
                </div>
            </div>

            <div style="border:1px solid #e5e7eb; border-radius:8px; padding:15px; margin:15px 0; background:#f0fdf4;">
                <label style="font-weight:600; font-size:13px; margin-bottom:10px; display:block;"><?php echo e(t('admin_workflows.section_action')); ?></label>

                <div class="form-group">
                    <label for="ruleTargetTemplate"><?php echo e(t('admin_workflows.label_target_template')); ?></label>
                    <select id="ruleTargetTemplate" name="target_template_id" class="form-control" required>
                        <option value=""><?php echo e(t('admin_workflows.opt_select_target_template')); ?></option>
                        <?php foreach ($allTemplates as $tpl): ?>
                        <option value="<?php echo $tpl['id']; ?>"><?php echo e($tpl['name']); ?> (<?php echo e($categoryLabels[$tpl['category'] ?? 'vendor_assessment'] ?? $tpl['category']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
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

            <div style="display:flex; gap:10px; margin-top:20px;">
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

function openCreateRule() {
    resetRuleModal();
    document.getElementById('ruleModal').style.display = 'block';
}

function resetRuleModal() {
    document.getElementById('ruleModalTitle').textContent = <?php echo json_encode(t('admin_workflows.modal_title_create')); ?>;
    document.getElementById('ruleId').value = '';
    document.getElementById('ruleName').value = '';
    document.getElementById('ruleDescription').value = '';
    document.getElementById('ruleSourceTemplate').value = '';
    document.getElementById('ruleQuestion').innerHTML = '<option value="">' + <?php echo json_encode(t('admin_workflows.opt_select_template_first')); ?> + '</option>';
    document.getElementById('ruleConditionValue').innerHTML = '<option value="">' + <?php echo json_encode(t('admin_workflows.opt_select_question_first')); ?> + '</option>';
    document.getElementById('ruleConditionValue').style.display = '';
    document.getElementById('ruleConditionValue').setAttribute('name', 'condition_value');
    document.getElementById('ruleConditionValueText').style.display = 'none';
    document.getElementById('ruleConditionValueText').removeAttribute('name');
    document.getElementById('ruleConditionValueText').value = '';
    document.getElementById('ruleTargetTemplate').value = '';
    document.getElementById('ruleAssignTo').value = 'vendor';

    var btn = document.getElementById('ruleSubmitBtn');
    btn.name = 'create_rule';
    btn.textContent = <?php echo json_encode(t('admin_workflows.create_rule_submit')); ?>;
}

function editRule(dataStr) {
    var data = typeof dataStr === 'string' ? JSON.parse(dataStr) : dataStr;
    document.getElementById('ruleModalTitle').textContent = <?php echo json_encode(t('admin_workflows.modal_title_edit')); ?>;
    document.getElementById('ruleId').value = data.id;
    document.getElementById('ruleName').value = data.name;
    document.getElementById('ruleDescription').value = data.description || '';

    // Set source template and trigger question population
    document.getElementById('ruleSourceTemplate').value = data.source_template_id;
    onSourceTemplateChange(data.source_template_id);

    // Set question after population
    document.getElementById('ruleQuestion').value = data.question_id;
    onQuestionChange(data.question_id);

    // Set condition value — try dropdown first, fall back to text input
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

    document.getElementById('ruleTargetTemplate').value = data.target_template_id;
    document.getElementById('ruleAssignTo').value = data.assign_to || 'vendor';

    var btn = document.getElementById('ruleSubmitBtn');
    btn.name = 'update_rule';
    btn.textContent = <?php echo json_encode(t('admin_workflows.update_rule_submit')); ?>;

    // Show modal
    document.getElementById('ruleModal').style.display = 'block';
}

// Fix form submission: ensure condition_value name is set on the right input
document.getElementById('ruleForm').addEventListener('submit', function() {
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
