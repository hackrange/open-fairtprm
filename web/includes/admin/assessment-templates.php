<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Assessment Template Builder
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Manages assessment templates: list, create, edit (builder UI), duplicate,
 * toggle active/inactive, delete. The edit view is a full form builder with
 * AJAX-based section/question management.
 */

require_once __DIR__ . '/../classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();
$assessmentService->initializeTables();

$action = $_GET['action'] ?? 'list';
$templateId = isset($_GET['template_id']) ? intval($_GET['template_id']) : 0;

// ============================================================
// POST HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_assessment-templates.invalid_request');
    } elseif (isset($_POST['create_template'])) {
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            $error = t('admin_assessment-templates.name_required');
        } else {
            try {
                $newId = $assessmentService->createTemplate([
                    'name' => $name,
                    'description' => trim($_POST['description'] ?? ''),
                    'category' => $_POST['category'] ?? 'vendor_assessment',
                    'certificate_upload_mode' => $_POST['certificate_upload_mode'] ?? 'none',
                    'certificate_upload_prompt' => trim($_POST['certificate_upload_prompt'] ?? ''),
                ]);
                $success = t('admin_assessment-templates.template_created') . ' <a href="?section=assessment-templates&action=edit&template_id=' . $newId . '">' . t('admin_assessment-templates.edit_it_now') . '</a>';
            } catch (Exception $e) {
                error_log('Create template error: ' . $e->getMessage());
                $error = t('admin_assessment-templates.create_failed');
            }
        }
    } elseif (isset($_POST['update_template'])) {
        $tid = intval($_POST['template_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$tid || empty($name)) {
            $error = t('admin_assessment-templates.id_name_required');
        } else {
            try {
                $assessmentService->updateTemplate($tid, [
                    'name' => $name,
                    'description' => trim($_POST['description'] ?? ''),
                    'category' => $_POST['category'] ?? 'vendor_assessment',
                    'certificate_upload_mode' => $_POST['certificate_upload_mode'] ?? 'none',
                    'certificate_upload_prompt' => trim($_POST['certificate_upload_prompt'] ?? ''),
                ]);
                // Persist the minimal-set question selection whenever the picker was
                // part of the submitted form (it is always present in the edit view,
                // just hidden unless minimal mode is selected).
                if (!empty($_POST['minimal_questions_submitted'])) {
                    $minimalIds = (isset($_POST['minimal_questions']) && is_array($_POST['minimal_questions']))
                        ? $_POST['minimal_questions'] : [];
                    $assessmentService->setMinimalQuestions($tid, $minimalIds);
                }
                $success = t('admin_assessment-templates.updated');
                $templateId = $tid;
                $action = 'edit';
            } catch (Exception $e) {
                error_log('Update template error: ' . $e->getMessage());
                $error = t('admin_assessment-templates.update_failed');
                $templateId = $tid;
                $action = 'edit';
            }
        }
    } elseif (isset($_POST['toggle_template'])) {
        $tid = intval($_POST['template_id'] ?? 0);
        if ($tid) {
            $assessmentService->toggleTemplate($tid);
            $success = t('admin_assessment-templates.status_toggled');
        }
    } elseif (isset($_POST['delete_template'])) {
        $tid = intval($_POST['template_id'] ?? 0);
        if ($tid) {
            if (!$assessmentService->canDeleteTemplate($tid)) {
                $error = t('admin_assessment-templates.cannot_delete_linked');
            } else {
                $assessmentService->deleteTemplate($tid);
                $success = t('admin_assessment-templates.deleted');
            }
        }
    } elseif (isset($_POST['duplicate_template'])) {
        $tid = intval($_POST['template_id'] ?? 0);
        if ($tid) {
            try {
                $newId = $assessmentService->duplicateTemplate($tid);
                $success = t('admin_assessment-templates.template_duplicated') . ' <a href="?section=assessment-templates&action=edit&template_id=' . $newId . '">' . t('admin_assessment-templates.edit_the_copy') . '</a>';
            } catch (Exception $e) {
                error_log('Duplicate template error: ' . $e->getMessage());
                $error = t('admin_assessment-templates.duplicate_failed');
            }
        }
    }
}

// ============================================================
// LOAD DATA
// ============================================================
$allTemplates = $assessmentService->getTemplates(false);
$editTemplate = null;
if ($action === 'edit' && $templateId) {
    $editTemplate = $assessmentService->getTemplateWithSectionsAndQuestions($templateId);
    if (!$editTemplate) {
        $error = t('admin_assessment-templates.not_found');
        $action = 'list';
    }
}

$categoryLabels = [
    'vendor_assessment' => t('admin_assessment-templates.cat_vendor_assessment'),
    'procurement' => t('admin_assessment-templates.cat_procurement'),
    'onboarding' => t('admin_assessment-templates.cat_onboarding'),
];
?>

<?php if ($action === 'list'): ?>
<!-- ============================================================ -->
<!-- LIST VIEW -->
<!-- ============================================================ -->
<div class="page-header-bar">
    <h1><?php echo e(t('admin_assessment-templates.builder_title')); ?></h1>
    <p><?php echo e(t('admin_assessment-templates.builder_desc')); ?></p>
</div>

<?php $inactiveTemplateCount = 0; foreach ($allTemplates as $__t) { if (empty($__t['is_active'])) { $inactiveTemplateCount++; } } ?>
<div style="margin-bottom: 20px; display: flex; gap: 10px;">
    <button class="btn btn-primary" data-toggle="createTemplateModal"><?php echo e(t('admin_assessment-templates.btn_new_template')); ?></button>
    <a href="?section=field-reference" class="btn btn-secondary"><?php echo e(t('admin_assessment-templates.field_reference')); ?></a>
    <?php if ($inactiveTemplateCount > 0): ?>
    <button type="button" id="toggleDeactivatedBtn" class="btn btn-secondary"
            data-show-label="<?php echo e(t('admin_assessment-templates.btn_show_deactivated') . ' (' . $inactiveTemplateCount . ')'); ?>"
            data-hide-label="<?php echo e(t('admin_assessment-templates.btn_hide_deactivated') . ' (' . $inactiveTemplateCount . ')'); ?>">
        <?php echo e(t('admin_assessment-templates.btn_show_deactivated') . ' (' . $inactiveTemplateCount . ')'); ?>
    </button>
    <?php endif; ?>
</div>

<div class="card">
    <?php if (empty($allTemplates)): ?>
    <div style="text-align:center; padding:40px; color:#666;">
        <div style="font-size:40px; margin-bottom:10px;">&#128221;</div>
        <h3><?php echo e(t('admin_assessment-templates.no_templates')); ?></h3>
        <p><?php echo e(t('admin_assessment-templates.no_templates_desc')); ?></p>
    </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th><?php echo e(t('admin_assessment-templates.col_template')); ?></th>
                <th><?php echo e(t('admin_assessment-templates.col_category')); ?></th>
                <th><?php echo e(t('admin_assessment-templates.col_sections')); ?></th>
                <th><?php echo e(t('admin_assessment-templates.col_questions')); ?></th>
                <th><?php echo e(t('admin_assessment-templates.col_status')); ?></th>
                <th><?php echo e(t('admin_assessment-templates.col_actions')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allTemplates as $t): ?>
            <tr class="tpl-row<?php echo empty($t['is_active']) ? ' tpl-inactive' : ''; ?>"<?php echo empty($t['is_active']) ? ' style="display:none;"' : ''; ?>>
                <td>
                    <strong><?php echo e($t['name']); ?></strong>
                    <?php if ($t['description']): ?>
                    <br><small style="color:#666;"><?php echo e(mb_strimwidth($t['description'], 0, 80, '...')); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-blue"><?php echo e($categoryLabels[$t['category'] ?? 'vendor_assessment'] ?? $t['category']); ?></span>
                </td>
                <td><?php echo (int)($t['section_count'] ?? 0); ?></td>
                <td><?php echo (int)($t['question_count'] ?? 0); ?></td>
                <td>
                    <?php if ($t['is_active']): ?>
                    <span class="badge badge-success"><?php echo e(t('admin_assessment-templates.status_active')); ?></span>
                    <?php else: ?>
                    <span class="badge badge-danger"><?php echo e(t('admin_assessment-templates.status_inactive')); ?></span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <a href="?section=assessment-templates&action=edit&template_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary"><?php echo e(t('admin_assessment-templates.action_edit')); ?></a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="template_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" name="toggle_template" class="btn btn-sm btn-secondary">
                            <?php echo $t['is_active'] ? e(t('admin_assessment-templates.action_deactivate')) : e(t('admin_assessment-templates.action_activate')); ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="template_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" name="duplicate_template" class="btn btn-sm" style="background:#8b5cf6;color:white;"><?php echo e(t('admin_assessment-templates.action_duplicate')); ?></button>
                    </form>
                    <?php $builtinSlugs = ['iso-27001-2022','tier-2-vendor','vendor-onboarding','ai-usage'];
                    if (!in_array($t['slug'], $builtinSlugs) && $assessmentService->canDeleteTemplate($t['id'])): ?>
                    <form method="POST" style="display:inline;" data-confirm="<?php echo e(t('admin_assessment-templates.confirm_delete')); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="template_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" name="delete_template" class="btn btn-sm" style="background:#ef4444;color:white;"><?php echo e(t('admin_assessment-templates.action_delete')); ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Create Template Modal -->
<div id="createTemplateModal" class="modal">
    <div class="modal-content">
        <span class="close" data-close="createTemplateModal">&times;</span>
        <h3 style="margin-top:0; color:var(--theme-header-color);"><?php echo e(t('admin_assessment-templates.create_modal_title')); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <div class="form-group">
                <label><?php echo e(t('admin_assessment-templates.label_template_name')); ?> <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" class="form-control" required placeholder="<?php echo e(t('admin_assessment-templates.placeholder_template_name')); ?>">
            </div>
            <div class="form-group">
                <label><?php echo e(t('admin_assessment-templates.label_description')); ?></label>
                <textarea name="description" class="form-control" rows="3" placeholder="<?php echo e(t('admin_assessment-templates.placeholder_description')); ?>"></textarea>
            </div>
            <div class="form-group">
                <label><?php echo e(t('admin_assessment-templates.label_category')); ?></label>
                <select name="category" class="form-control">
                    <option value="vendor_assessment"><?php echo e(t('admin_assessment-templates.cat_vendor_assessment')); ?></option>
                    <option value="procurement"><?php echo e(t('admin_assessment-templates.cat_procurement')); ?></option>
                    <option value="onboarding"><?php echo e(t('admin_assessment-templates.cat_onboarding')); ?></option>
                </select>
            </div>
            <div id="certUploadGroup">
                <div class="form-group">
                    <label><?php echo e(t('admin_assessment-templates.cert_mode_label')); ?></label>
                    <select name="certificate_upload_mode" class="form-control" id="createCertMode">
                        <option value="none"><?php echo e(t('admin_assessment-templates.cert_mode_none')); ?></option>
                        <option value="skip"><?php echo e(t('admin_assessment-templates.cert_mode_skip')); ?></option>
                        <option value="minimal"><?php echo e(t('admin_assessment-templates.cert_mode_minimal')); ?></option>
                    </select>
                    <small style="color:#666; display:block; margin-top:4px;"><?php echo e(t('admin_assessment-templates.cert_mode_help')); ?></small>
                </div>
                <div class="form-group" id="certPromptGroup" style="display:none;">
                    <label><?php echo e(t('admin_assessment-templates.label_cert_instructions')); ?></label>
                    <textarea name="certificate_upload_prompt" class="form-control" rows="3" placeholder="<?php echo e(t('admin_assessment-templates.placeholder_cert_instructions')); ?>"></textarea>
                </div>
                <p id="createMinimalHint" style="color:#666; font-size:12px; margin:4px 0 0 0; display:none;"><em><?php echo e(t('admin_assessment-templates.minimal_no_questions')); ?></em></p>
            </div>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="submit" name="create_template" class="btn btn-primary"><?php echo e(t('admin_assessment-templates.btn_create_template')); ?></button>
                <button type="button" class="btn btn-secondary" data-close="createTemplateModal"><?php echo e(t('admin_assessment-templates.btn_cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var modeSel = document.getElementById('createCertMode');
    function syncCreateMode() {
        var mode = modeSel ? modeSel.value : 'none';
        var prompt = document.getElementById('certPromptGroup');
        var hint = document.getElementById('createMinimalHint');
        if (prompt) prompt.style.display = (mode === 'skip' || mode === 'minimal') ? 'block' : 'none';
        // Minimal questions are chosen on the edit screen (the template has no
        // questions until it exists), so just hint the admin when minimal is picked.
        if (hint) hint.style.display = (mode === 'minimal') ? 'block' : 'none';
    }
    modeSel?.addEventListener('change', syncCreateMode);
    syncCreateMode();
    document.querySelector('#createTemplateModal [name="category"]')?.addEventListener('change', function() {
        document.getElementById('certUploadGroup').style.display = this.value === 'vendor_assessment' ? '' : 'none';
    });

    // Deactivated templates are hidden by default; this button reveals/hides them.
    var deactBtn = document.getElementById('toggleDeactivatedBtn');
    if (deactBtn) {
        var deactShown = false;
        deactBtn.addEventListener('click', function() {
            deactShown = !deactShown;
            document.querySelectorAll('tr.tpl-inactive').forEach(function(row) {
                row.style.display = deactShown ? '' : 'none';
            });
            deactBtn.textContent = deactShown
                ? deactBtn.getAttribute('data-hide-label')
                : deactBtn.getAttribute('data-show-label');
        });
    }
})();
</script>

<?php elseif ($action === 'edit' && $editTemplate): ?>
<!-- ============================================================ -->
<!-- EDIT / BUILDER VIEW -->
<!-- ============================================================ -->
<?php
$assessmentCount = $assessmentService->getAssessmentCountForTemplate($editTemplate['id']);
// Build flat list of all questions for conditional logic dropdowns
$allQuestionsFlat = [];
foreach ($editTemplate['sections'] as $sec) {
    foreach ($sec['questions'] as $q) {
        $allQuestionsFlat[] = [
            'id' => $q['id'],
            'text' => $q['question_text'],
            'section_name' => $sec['name'],
            'type' => $q['question_type'],
            'options' => $q['options'],
        ];
    }
}

// Load field references for the field_name dropdown
// Filter by template category + 'all', so only relevant fields show
$templateCategory = $editTemplate['category'] ?? 'vendor_assessment';
// Custom (added) section/question id maps — role controls in the builder only apply
// to these; standard/default onboarding questions never show the role controls.
$builderCustomMaps = $assessmentService->getOnboardingCustomMaps($editTemplate['id']);
try {
    $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS field_references (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        field_name VARCHAR(100) NOT NULL,
        column_type VARCHAR(100) NOT NULL DEFAULT 'VARCHAR(500)',
        section VARCHAR(100) NOT NULL DEFAULT '',
        category VARCHAR(50) NOT NULL DEFAULT 'all',
        description TEXT,
        is_builtin TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_field_name (field_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}
// Vendor assessment templates can also map to onboarding-category fields,
// since fields like vendor_use_ai live on vendor_onboarding_requests and the
// linked request is updated when the assessment is saved.
if ($templateCategory === 'vendor_assessment') {
    $fieldReferences = $db->fetchAll(
        "SELECT field_name, section, category, description FROM field_references
         WHERE category IN ('vendor_assessment', 'onboarding', 'all') ORDER BY section, field_name"
    );
} else {
    $fieldReferences = $db->fetchAll(
        "SELECT field_name, section, category, description FROM field_references
         WHERE category IN (?, 'all') ORDER BY section, field_name",
        [$templateCategory]
    );
}
// Build set of field_names already used in this template (for duplicate warning)
$usedFieldNames = [];
foreach ($editTemplate['sections'] as $sec) {
    foreach ($sec['questions'] as $q) {
        if (!empty($q['field_name'])) {
            $usedFieldNames[$q['field_name']] = $q['id'];
        }
    }
}
?>

<div class="page-header-bar">
    <h1>
        <a href="?section=assessment-templates" style="color:#666; text-decoration:none;">&larr;</a>
        <?php echo e(t('admin_assessment-templates.edit_prefix')); ?> <?php echo e($editTemplate['name']); ?>
    </h1>
    <p>
        <?php echo e($categoryLabels[$editTemplate['category'] ?? 'vendor_assessment'] ?? ''); ?> &middot;
        <?php echo $editTemplate['is_active'] ? '<span style="color:#166534;">' . t('admin_assessment-templates.status_active') . '</span>' : '<span style="color:#991b1b;">' . t('admin_assessment-templates.status_inactive') . '</span>'; ?>
        <?php if ($assessmentCount > 0): ?>
        &middot; <span style="color:#92400e;"><?php echo $assessmentCount; ?> <?php echo e(t('admin_assessment-templates.linked_assessments_suffix')); ?></span>
        <?php endif; ?>
    </p>
</div>

<?php if ($assessmentCount > 0): ?>
<div class="alert" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;">
    <?php echo e(t('admin_assessment-templates.linked_warning_prefix')); ?> <?php echo $assessmentCount; ?> <?php echo e(t('admin_assessment-templates.linked_warning_suffix')); ?>
</div>
<?php endif; ?>

<!-- Template Metadata Card -->
<div class="card">
    <h3><?php echo e(t('admin_assessment-templates.template_settings')); ?></h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="template_id" value="<?php echo $editTemplate['id']; ?>">
        <div class="form-row">
            <div class="form-group">
                <label><?php echo e(t('admin_assessment-templates.label_template_name')); ?> <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" class="form-control" required value="<?php echo e($editTemplate['name']); ?>">
            </div>
            <div class="form-group">
                <label><?php echo e(t('admin_assessment-templates.label_category')); ?></label>
                <select name="category" class="form-control">
                    <option value="vendor_assessment" <?php echo ($editTemplate['category'] ?? '') === 'vendor_assessment' ? 'selected' : ''; ?>><?php echo e(t('admin_assessment-templates.cat_vendor_assessment')); ?></option>
                    <option value="procurement" <?php echo ($editTemplate['category'] ?? '') === 'procurement' ? 'selected' : ''; ?>><?php echo e(t('admin_assessment-templates.cat_procurement')); ?></option>
                    <option value="onboarding" <?php echo ($editTemplate['category'] ?? '') === 'onboarding' ? 'selected' : ''; ?>><?php echo e(t('admin_assessment-templates.cat_onboarding')); ?></option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_assessment-templates.label_description')); ?></label>
            <textarea name="description" class="form-control" rows="2"><?php echo e($editTemplate['description'] ?? ''); ?></textarea>
        </div>
<?php
        $editCertMode = $assessmentService->normalizeCertificateMode(
            $editTemplate['certificate_upload_mode'] ?? null,
            $editTemplate['allow_certificate_upload'] ?? null
        );
        $minimalSelectedCount = 0;
        foreach ($editTemplate['sections'] as $sec) {
            foreach ($sec['questions'] as $q) {
                if (!empty($q['include_in_minimal'])) $minimalSelectedCount++;
            }
        }
        $totalQuestionCount = count($allQuestionsFlat);
?>
        <div id="editCertUploadGroup" style="<?php echo ($editTemplate['category'] ?? '') === 'vendor_assessment' ? '' : 'display:none;'; ?>">
            <!-- Marker so the update handler knows the minimal-set picker was submitted -->
            <input type="hidden" name="minimal_questions_submitted" value="1">
            <div class="form-group">
                <label><?php echo e(t('admin_assessment-templates.cert_mode_label')); ?></label>
                <select name="certificate_upload_mode" class="form-control" id="editCertMode">
                    <option value="none" <?php echo $editCertMode === 'none' ? 'selected' : ''; ?>><?php echo e(t('admin_assessment-templates.cert_mode_none')); ?></option>
                    <option value="skip" <?php echo $editCertMode === 'skip' ? 'selected' : ''; ?>><?php echo e(t('admin_assessment-templates.cert_mode_skip')); ?></option>
                    <option value="minimal" <?php echo $editCertMode === 'minimal' ? 'selected' : ''; ?>><?php echo e(t('admin_assessment-templates.cert_mode_minimal')); ?></option>
                </select>
                <small style="color:#666; display:block; margin-top:4px;"><?php echo e(t('admin_assessment-templates.cert_mode_help')); ?></small>
            </div>
            <div class="form-group" id="editCertPromptGroup" style="<?php echo $editCertMode !== 'none' ? '' : 'display:none;'; ?>">
                <label><?php echo e(t('admin_assessment-templates.label_cert_instructions')); ?></label>
                <textarea name="certificate_upload_prompt" class="form-control" rows="3" placeholder="<?php echo e(t('admin_assessment-templates.placeholder_cert_instructions')); ?>"><?php echo e($editTemplate['certificate_upload_prompt'] ?? ''); ?></textarea>
            </div>
            <div class="form-group" id="editMinimalGroup" style="<?php echo $editCertMode === 'minimal' ? '' : 'display:none;'; ?>">
                <button type="button" class="btn btn-secondary" data-toggle="minimalQuestionsModal"><?php echo e(t('admin_assessment-templates.minimal_pick_btn')); ?></button>
                <span id="minimalCountLabel" style="margin-left:10px; color:#666;"
                      data-count-template="<?php echo e(t('admin_assessment-templates.minimal_count')); ?>"
                      data-none="<?php echo e(t('admin_assessment-templates.minimal_none_selected')); ?>"></span>
            </div>

            <!-- Minimal-set picker. Rendered INSIDE the settings form so its checkboxes
                 submit; position:fixed (.modal) keeps it out of the layout flow. -->
            <div id="minimalQuestionsModal" class="modal">
                <div class="modal-content" style="max-width:640px;">
                    <span class="close" data-close="minimalQuestionsModal">&times;</span>
                    <h3 style="margin-top:0; color:var(--theme-header-color);"><?php echo e(t('admin_assessment-templates.minimal_modal_title')); ?></h3>
                    <p style="color:#666; font-size:13px;"><?php echo e(t('admin_assessment-templates.minimal_modal_desc')); ?></p>
                    <?php if ($totalQuestionCount === 0): ?>
                    <p><em><?php echo e(t('admin_assessment-templates.minimal_no_questions')); ?></em></p>
                    <?php else: ?>
                    <div style="margin-bottom:10px; display:flex; gap:10px;">
                        <button type="button" class="btn btn-sm btn-secondary" id="minimalSelectAll"><?php echo e(t('admin_assessment-templates.minimal_select_all')); ?></button>
                        <button type="button" class="btn btn-sm btn-secondary" id="minimalClearAll"><?php echo e(t('admin_assessment-templates.minimal_clear_all')); ?></button>
                    </div>
                    <div style="max-height:360px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:6px; padding:10px;">
                        <?php foreach ($editTemplate['sections'] as $sec): ?>
                            <?php if (empty($sec['questions'])) continue; ?>
                            <div style="font-weight:600; color:#374151; margin:8px 0 4px;"><?php echo e($sec['name']); ?></div>
                            <?php foreach ($sec['questions'] as $q): ?>
                            <label style="display:flex; align-items:flex-start; gap:8px; padding:4px 0; font-size:14px;">
                                <input type="checkbox" class="minimal-q-checkbox" name="minimal_questions[]" value="<?php echo (int)$q['id']; ?>" <?php echo !empty($q['include_in_minimal']) ? 'checked' : ''; ?> style="margin-top:3px;">
                                <span>
                                    <span class="q-type-badge" style="font-size:10px;"><?php echo e($q['question_type']); ?></span>
                                    <?php echo e(mb_strimwidth($q['question_text'], 0, 90, '...')); ?>
                                    <?php if ($q['is_required']): ?><span class="badge badge-danger" style="font-size:9px;"><?php echo e(t('admin_assessment-templates.badge_required')); ?></span><?php endif; ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex; gap:10px; margin-top:20px;">
                        <button type="button" class="btn btn-primary" data-close="minimalQuestionsModal"><?php echo e(t('admin_assessment-templates.minimal_done')); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <button type="submit" name="update_template" class="btn btn-primary"><?php echo e(t('admin_assessment-templates.btn_save_settings')); ?></button>
    </form>
</div>

<!-- Sections & Questions Builder -->
<div class="card" id="builderCard">
    <h3><?php echo e(t('admin_assessment-templates.sections_questions')); ?></h3>
    <div id="sectionsContainer">
        <?php foreach ($editTemplate['sections'] as $sIdx => $sec): ?>
        <div class="builder-section" data-section-id="<?php echo $sec['id']; ?>">
            <div class="section-header">
                <div class="section-header-left">
                    <strong class="section-title-text"><?php echo e($sec['name']); ?></strong>
                    <small style="color:#666; margin-left:10px;"><?php echo count($sec['questions']); ?> <?php echo e(t('admin_assessment-templates.questions_suffix')); ?></small>
                </div>
                <div class="section-header-right">
                    <?php if ($sIdx > 0): ?>
                    <button type="button" class="btn btn-sm btn-secondary" data-action="reorderSection" data-args="<?php echo e(json_encode([$sec['id'], 'up'])); ?>" title="<?php echo e(t('admin_assessment-templates.title_move_up')); ?>">&#9650;</button>
                    <?php endif; ?>
                    <?php if ($sIdx < count($editTemplate['sections']) - 1): ?>
                    <button type="button" class="btn btn-sm btn-secondary" data-action="reorderSection" data-args="<?php echo e(json_encode([$sec['id'], 'down'])); ?>" title="<?php echo e(t('admin_assessment-templates.title_move_down')); ?>">&#9660;</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-primary" data-action="editSection" data-args="<?php echo e(json_encode([$sec['id'], $sec['name'], $sec['description'] ?? '', $sec['visible_roles'] ?? '', $sec['editable_roles'] ?? ''])); ?>"><?php echo e(t('admin_assessment-templates.action_edit')); ?></button>
                    <button type="button" class="btn btn-sm" style="background:#ef4444;color:white;" data-action="deleteSection" data-arg="<?php echo $sec['id']; ?>"><?php echo e(t('admin_assessment-templates.action_delete')); ?></button>
                </div>
            </div>
            <?php if ($sec['description']): ?>
            <p style="color:#666; font-size:12px; margin:5px 0 10px 0;"><?php echo e($sec['description']); ?></p>
            <?php endif; ?>

            <div class="questions-list" data-section-id="<?php echo $sec['id']; ?>">
                <?php foreach ($sec['questions'] as $qIdx => $q): ?>
                <div class="question-row" data-question-id="<?php echo $q['id']; ?>">
                    <div class="question-row-left">
                        <span class="q-type-badge"><?php echo e($q['question_type']); ?></span>
                        <span class="q-text"><?php echo e(mb_strimwidth($q['question_text'], 0, 70, '...')); ?></span>
                        <?php if ($q['is_required']): ?>
                        <span class="badge badge-danger" style="font-size:10px;"><?php echo e(t('admin_assessment-templates.badge_required')); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($q['field_name'])): ?>
                        <span class="badge" style="font-size:10px; background:#dbeafe; color:#1e40af;" title="<?php echo e(t('admin_assessment-templates.maps_to_field')); ?> <?php echo e($q['field_name']); ?>"><?php echo e($q['field_name']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($q['depends_on_question_id'])): ?>
                        <span class="badge badge-purple" style="font-size:10px;" title="<?php echo e(t('admin_assessment-templates.badge_conditional')); ?>"><?php echo e(t('admin_assessment-templates.badge_conditional')); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="question-row-right">
                        <?php if ($qIdx > 0): ?>
                        <button type="button" class="btn btn-sm btn-secondary" data-action="reorderQuestion" data-args="<?php echo e(json_encode([$q['id'], $sec['id'], 'up'])); ?>" title="<?php echo e(t('admin_assessment-templates.title_move_up')); ?>">&#9650;</button>
                        <?php endif; ?>
                        <?php if ($qIdx < count($sec['questions']) - 1): ?>
                        <button type="button" class="btn btn-sm btn-secondary" data-action="reorderQuestion" data-args="<?php echo e(json_encode([$q['id'], $sec['id'], 'down'])); ?>" title="<?php echo e(t('admin_assessment-templates.title_move_down')); ?>">&#9660;</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-primary" data-action="editQuestion" data-arg="<?php echo $q['id']; ?>"><?php echo e(t('admin_assessment-templates.action_edit')); ?></button>
                        <button type="button" class="btn btn-sm" style="background:#ef4444;color:white;" data-action="deleteQuestion" data-args="<?php echo e(json_encode([$q['id'], $sec['id']])); ?>"><?php echo e(t('admin_assessment-templates.action_delete')); ?></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm" style="background:#10b981;color:white; margin-top:10px;" data-action="addQuestion" data-arg="<?php echo $sec['id']; ?>"><?php echo e(t('admin_assessment-templates.btn_add_question')); ?></button>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:20px; padding-top:15px; border-top:1px solid #e5e7eb;">
        <button type="button" class="btn btn-primary" data-action="addSection"><?php echo e(t('admin_assessment-templates.btn_add_section')); ?></button>
    </div>
</div>

<?php
// Role visibility options for onboarding-template sections/questions. Keys are the
// role slugs persisted in visible_roles (matches VendorAssessmentService::VISIBILITY_ROLES).
// 'super_admin' is the is_super_admin flag, not an ACL group. Empty selection = visible to all.
$visibilityRoles = [
    'stakeholder'   => t('admin_assessment-templates.role_stakeholder'),
    'cyber_tprm'    => t('admin_assessment-templates.role_cyber_tprm'),
    'administrator' => t('admin_assessment-templates.role_administrator'),
    'auditor'       => t('admin_assessment-templates.role_auditor'),
    'procurement'   => t('admin_assessment-templates.role_procurement'),
    'super_admin'   => t('admin_assessment-templates.role_super_admin'),
];
?>
<!-- Section Modal -->
<div id="sectionModal" class="modal">
    <div class="modal-content">
        <span class="close" data-close="sectionModal">&times;</span>
        <h3 style="margin-top:0; color:var(--theme-header-color);" id="sectionModalTitle"><?php echo e(t('admin_assessment-templates.add_section_title')); ?></h3>
        <input type="hidden" id="sectionModalId" value="">
        <div class="form-group">
            <label><?php echo e(t('admin_assessment-templates.label_section_name')); ?> <span style="color:#ef4444;">*</span></label>
            <input type="text" id="sectionModalName" class="form-control" required>
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_assessment-templates.label_description')); ?></label>
            <textarea id="sectionModalDesc" class="form-control" rows="2"></textarea>
        </div>
        <?php if ($templateCategory === 'onboarding'): ?>
        <!-- Role controls apply only to custom (added) sections; JS hides them for standard sections. -->
        <div id="sectionModalRoleControls" style="display:none;">
        <div class="form-group" style="border-top:1px solid #e5e7eb; padding-top:12px;">
            <label style="font-weight:600; font-size:13px; display:block; margin-bottom:4px;"><?php echo e(t('admin_assessment-templates.label_visible_roles')); ?></label>
            <small style="color:#666; display:block; margin-bottom:8px;"><?php echo e(t('admin_assessment-templates.visible_roles_hint')); ?></small>
            <div id="sectionModalVisibleRoles" style="display:flex; flex-wrap:wrap; gap:14px;">
                <?php foreach ($visibilityRoles as $slug => $label): ?>
                <label style="display:flex; align-items:center; gap:5px; font-weight:normal; font-size:13px; cursor:pointer;">
                    <input type="checkbox" class="sec-visible-role" value="<?php echo e($slug); ?>"> <?php echo e($label); ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-group">
            <label style="font-weight:600; font-size:13px; display:block; margin-bottom:4px;"><?php echo e(t('admin_assessment-templates.label_editable_roles')); ?></label>
            <small style="color:#666; display:block; margin-bottom:8px;"><?php echo e(t('admin_assessment-templates.editable_roles_hint')); ?></small>
            <div id="sectionModalEditableRoles" style="display:flex; flex-wrap:wrap; gap:14px;">
                <?php foreach ($visibilityRoles as $slug => $label): ?>
                <label style="display:flex; align-items:center; gap:5px; font-weight:normal; font-size:13px; cursor:pointer;">
                    <input type="checkbox" class="sec-editable-role" value="<?php echo e($slug); ?>"> <?php echo e($label); ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        </div><!-- /sectionModalRoleControls -->
        <?php endif; ?>
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" class="btn btn-primary" data-action="saveSection"><?php echo e(t('admin_assessment-templates.btn_save_section')); ?></button>
            <button type="button" class="btn btn-secondary" data-close="sectionModal"><?php echo e(t('admin_assessment-templates.btn_cancel')); ?></button>
        </div>
    </div>
</div>

<!-- Question Modal -->
<div id="questionModal" class="modal">
    <div class="modal-content" style="max-width:650px;">
        <span class="close" data-close="questionModal">&times;</span>
        <h3 style="margin-top:0; color:var(--theme-header-color);" id="questionModalTitle"><?php echo e(t('admin_assessment-templates.add_question_title')); ?></h3>
        <input type="hidden" id="qModalId" value="">
        <input type="hidden" id="qModalSectionId" value="">
        <div class="form-group">
            <label><?php echo e(t('admin_assessment-templates.label_question_text')); ?> <span style="color:#ef4444;">*</span></label>
            <textarea id="qModalText" class="form-control" rows="2" required></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label><?php echo e(t('admin_assessment-templates.label_question_type')); ?></label>
                <select id="qModalType" class="form-control" data-action="toggleOptionsEditor">
                    <option value="text"><?php echo e(t('admin_assessment-templates.qtype_text')); ?></option>
                    <option value="textarea"><?php echo e(t('admin_assessment-templates.qtype_textarea')); ?></option>
                    <option value="number"><?php echo e(t('admin_assessment-templates.qtype_number')); ?></option>
                    <option value="date"><?php echo e(t('admin_assessment-templates.qtype_date')); ?></option>
                    <option value="email"><?php echo e(t('admin_assessment-templates.qtype_email')); ?></option>
                    <option value="phone"><?php echo e(t('admin_assessment-templates.qtype_phone')); ?></option>
                    <option value="vat"><?php echo e(t('admin_assessment-templates.qtype_vat')); ?></option>
                    <option value="select"><?php echo e(t('admin_assessment-templates.qtype_dropdown')); ?></option>
                    <option value="radio"><?php echo e(t('admin_assessment-templates.qtype_radio')); ?></option>
                    <option value="checkbox"><?php echo e(t('admin_assessment-templates.qtype_checkbox')); ?></option>
                    <option value="button_group"><?php echo e(t('admin_assessment-templates.qtype_button_group')); ?></option>
                    <option value="button_group_multi"><?php echo e(t('admin_assessment-templates.qtype_button_group_multi')); ?></option>
                    <option value="file"><?php echo e(t('admin_assessment-templates.qtype_file')); ?></option>
                </select>
            </div>
            <div class="form-group">
                <label><?php echo e(t('admin_assessment-templates.label_required')); ?></label>
                <select id="qModalRequired" class="form-control">
                    <option value="1"><?php echo e(t('admin_assessment-templates.opt_yes')); ?></option>
                    <option value="0"><?php echo e(t('admin_assessment-templates.opt_no')); ?></option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_assessment-templates.label_help_text')); ?></label>
            <input type="text" id="qModalHelp" class="form-control" placeholder="<?php echo e(t('admin_assessment-templates.placeholder_help_text')); ?>">
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_assessment-templates.label_field_name')); ?> <small style="color:#666;"><?php echo e(t('admin_assessment-templates.field_name_hint')); ?></small>
                <a href="admin.php?section=field-reference" target="_blank" style="margin-left:8px; font-size:12px; font-weight:normal;"><?php echo e(t('admin_assessment-templates.view_field_reference')); ?></a>
            </label>
            <select id="qModalFieldName" class="form-control">
                <option value=""><?php echo e(t('admin_assessment-templates.opt_none')); ?></option>
                <?php
                $lastSection = '';
                foreach ($fieldReferences as $fr):
                    if ($fr['section'] !== $lastSection):
                        if ($lastSection !== '') echo '</optgroup>';
                        $lastSection = $fr['section'];
                        echo '<optgroup label="' . e($fr['section']) . '">';
                    endif;
                ?>
                <option value="<?php echo e($fr['field_name']); ?>" title="<?php echo e($fr['description']); ?>"><?php echo e($fr['field_name']); ?><?php if ($fr['description']): ?> — <?php echo e(mb_strimwidth($fr['description'], 0, 40, '...')); ?><?php endif; ?></option>
                <?php endforeach; ?>
                <?php if ($lastSection !== '') echo '</optgroup>'; ?>
            </select>
            <span id="fieldNameWarning" style="display:none; color:#b45309; font-size:12px; margin-top:4px;"></span>
        </div>
        <div class="form-group" id="optionsEditorGroup" style="display:none;">
            <label><?php echo e(t('admin_assessment-templates.label_options')); ?> <small style="color:#666;"><?php echo e(t('admin_assessment-templates.options_hint')); ?></small></label>
            <textarea id="qModalOptions" class="form-control" rows="4" placeholder="Yes&#10;No&#10;Not Applicable"></textarea>
        </div>
        <div style="border-top:1px solid #e5e7eb; padding-top:15px; margin-top:15px;">
            <label style="font-weight:600; font-size:13px; margin-bottom:10px; display:block;"><?php echo e(t('admin_assessment-templates.conditional_logic')); ?></label>
            <div class="form-row">
                <div class="form-group">
                    <label><?php echo e(t('admin_assessment-templates.label_show_when')); ?></label>
                    <select id="qModalDependsOn" class="form-control" data-action="loadDependsOnValues">
                        <option value=""><?php echo e(t('admin_assessment-templates.opt_always_visible')); ?></option>
                        <?php foreach ($allQuestionsFlat as $aq): ?>
                        <option value="<?php echo $aq['id']; ?>" data-type="<?php echo e($aq['type']); ?>" data-options="<?php echo e(json_encode($aq['options'])); ?>">
                            [<?php echo e($aq['section_name']); ?>] <?php echo e(mb_strimwidth($aq['text'], 0, 50, '...')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><?php echo e(t('admin_assessment-templates.label_equals_value')); ?></label>
                    <select id="qModalDependsValue" class="form-control">
                        <option value="">--</option>
                    </select>
                </div>
            </div>
        </div>
        <?php if ($templateCategory === 'onboarding'): ?>
        <!-- Role controls apply only to custom (added) questions; JS hides them for standard/default questions. -->
        <div id="qModalRoleControls" style="display:none;">
        <div class="form-group" style="border-top:1px solid #e5e7eb; padding-top:15px; margin-top:15px;">
            <label style="font-weight:600; font-size:13px; display:block; margin-bottom:4px;"><?php echo e(t('admin_assessment-templates.label_visible_roles')); ?></label>
            <small style="color:#666; display:block; margin-bottom:8px;"><?php echo e(t('admin_assessment-templates.visible_roles_hint')); ?></small>
            <div id="qModalVisibleRoles" style="display:flex; flex-wrap:wrap; gap:14px;">
                <?php foreach ($visibilityRoles as $slug => $label): ?>
                <label style="display:flex; align-items:center; gap:5px; font-weight:normal; font-size:13px; cursor:pointer;">
                    <input type="checkbox" class="q-visible-role" value="<?php echo e($slug); ?>"> <?php echo e($label); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <label style="font-weight:600; font-size:13px; display:block; margin:14px 0 4px;"><?php echo e(t('admin_assessment-templates.label_editable_roles')); ?></label>
            <small style="color:#666; display:block; margin-bottom:8px;"><?php echo e(t('admin_assessment-templates.editable_roles_hint')); ?></small>
            <div id="qModalEditableRoles" style="display:flex; flex-wrap:wrap; gap:14px;">
                <?php foreach ($visibilityRoles as $slug => $label): ?>
                <label style="display:flex; align-items:center; gap:5px; font-weight:normal; font-size:13px; cursor:pointer;">
                    <input type="checkbox" class="q-editable-role" value="<?php echo e($slug); ?>"> <?php echo e($label); ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        </div><!-- /qModalRoleControls -->
        <?php endif; ?>
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" class="btn btn-primary" data-action="saveQuestion"><?php echo e(t('admin_assessment-templates.btn_save_question')); ?></button>
            <button type="button" class="btn btn-secondary" data-close="questionModal"><?php echo e(t('admin_assessment-templates.btn_cancel')); ?></button>
        </div>
    </div>
</div>

<style>
/* Keep tall modals (e.g. the Question editor with options + conditional logic +
   role controls) usable on short viewports by scrolling within the modal. */
#questionModal .modal-content,
#sectionModal .modal-content {
    max-height: 90vh;
    overflow-y: auto;
}
.builder-section {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    background: #fafbfc;
}
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    flex-wrap: wrap;
    gap: 10px;
}
.section-header-left { display: flex; align-items: center; }
.section-header-right { display: flex; gap: 5px; flex-wrap: wrap; }
.question-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    margin-bottom: 4px;
    font-size: 13px;
    flex-wrap: wrap;
    gap: 8px;
}
.question-row-left { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
.question-row-right { display: flex; gap: 4px; flex-shrink: 0; }
.q-type-badge {
    background: #dbeafe;
    color: #1e40af;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    flex-shrink: 0;
}
.q-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>

<script src="app/js/template-builder.js" nonce="<?php echo cspNonce(); ?>"></script>
<script nonce="<?php echo cspNonce(); ?>">
// Pass data to JS
const templateId = <?php echo $editTemplate['id']; ?>;
let csrfToken = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const allQuestions = <?php echo json_encode($allQuestionsFlat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
// Custom (added) section/question ids — only these expose the role controls in the builder.
const customSectionIds = <?php echo json_encode(array_values(array_map('intval', array_keys($builderCustomMaps['sections'])))); ?>;
const customQuestionIds = <?php echo json_encode(array_values(array_map('intval', array_keys($builderCustomMaps['questions'])))); ?>;

// Question data cache for editing
const questionDataCache = <?php echo json_encode(
    array_reduce(
        array_merge(...array_map(function($s) { return $s['questions']; }, $editTemplate['sections'])),
        function($carry, $q) {
            $carry[$q['id']] = $q;
            return $carry;
        },
        []
    ),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT
); ?>;

// Field name usage map: field_name -> question_id (for duplicate detection)
const usedFieldNames = <?php echo json_encode($usedFieldNames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

// Warn when a field_name is already used by another question in this template
document.getElementById('qModalFieldName')?.addEventListener('change', function() {
    var warning = document.getElementById('fieldNameWarning');
    var editingId = parseInt(document.getElementById('qModalId').value) || 0;
    var val = this.value;
    if (val && usedFieldNames[val] && usedFieldNames[val] !== editingId) {
        warning.textContent = <?php echo json_encode(t('admin_assessment-templates.js_field_warning_prefix')); ?> + val + <?php echo json_encode(t('admin_assessment-templates.js_field_warning_suffix')); ?>;
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }
});

(function() {
    var modeSel = document.getElementById('editCertMode');
    var promptGroup = document.getElementById('editCertPromptGroup');
    var minimalGroup = document.getElementById('editMinimalGroup');
    var countLabel = document.getElementById('minimalCountLabel');

    function updateMinimalCount() {
        if (!countLabel) return;
        var n = document.querySelectorAll('.minimal-q-checkbox:checked').length;
        if (n > 0) {
            var tpl = countLabel.getAttribute('data-count-template') || '%d';
            countLabel.textContent = tpl.replace('%d', n);
        } else {
            countLabel.textContent = countLabel.getAttribute('data-none') || '';
        }
    }

    function syncEditMode() {
        var mode = modeSel ? modeSel.value : 'none';
        if (promptGroup) promptGroup.style.display = (mode === 'skip' || mode === 'minimal') ? 'block' : 'none';
        if (minimalGroup) minimalGroup.style.display = (mode === 'minimal') ? 'block' : 'none';
    }

    modeSel?.addEventListener('change', syncEditMode);
    document.getElementById('minimalSelectAll')?.addEventListener('click', function() {
        document.querySelectorAll('.minimal-q-checkbox').forEach(function(cb) { cb.checked = true; });
        updateMinimalCount();
    });
    document.getElementById('minimalClearAll')?.addEventListener('click', function() {
        document.querySelectorAll('.minimal-q-checkbox').forEach(function(cb) { cb.checked = false; });
        updateMinimalCount();
    });
    document.querySelectorAll('.minimal-q-checkbox').forEach(function(cb) {
        cb.addEventListener('change', updateMinimalCount);
    });
    document.querySelector('form [name="category"]')?.addEventListener('change', function() {
        document.getElementById('editCertUploadGroup').style.display = this.value === 'vendor_assessment' ? '' : 'none';
    });

    syncEditMode();
    updateMinimalCount();
})();
</script>

<?php endif; ?>
