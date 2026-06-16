<?php
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
        $error = 'Invalid request. Please try again.';
    } elseif (isset($_POST['create_template'])) {
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            $error = 'Template name is required.';
        } else {
            try {
                $newId = $assessmentService->createTemplate([
                    'name' => $name,
                    'description' => trim($_POST['description'] ?? ''),
                    'category' => $_POST['category'] ?? 'vendor_assessment',
                    'allow_certificate_upload' => !empty($_POST['allow_certificate_upload']),
                    'certificate_upload_prompt' => trim($_POST['certificate_upload_prompt'] ?? ''),
                ]);
                $success = 'Template created. <a href="?section=assessment-templates&action=edit&template_id=' . $newId . '">Edit it now</a>';
            } catch (Exception $e) {
                error_log('Create template error: ' . $e->getMessage());
                $error = 'Failed to create template.';
            }
        }
    } elseif (isset($_POST['update_template'])) {
        $tid = intval($_POST['template_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$tid || empty($name)) {
            $error = 'Template ID and name are required.';
        } else {
            try {
                $assessmentService->updateTemplate($tid, [
                    'name' => $name,
                    'description' => trim($_POST['description'] ?? ''),
                    'category' => $_POST['category'] ?? 'vendor_assessment',
                    'allow_certificate_upload' => !empty($_POST['allow_certificate_upload']),
                    'certificate_upload_prompt' => trim($_POST['certificate_upload_prompt'] ?? ''),
                ]);
                $success = 'Template updated.';
                $templateId = $tid;
                $action = 'edit';
            } catch (Exception $e) {
                error_log('Update template error: ' . $e->getMessage());
                $error = 'Failed to update template.';
                $templateId = $tid;
                $action = 'edit';
            }
        }
    } elseif (isset($_POST['toggle_template'])) {
        $tid = intval($_POST['template_id'] ?? 0);
        if ($tid) {
            $assessmentService->toggleTemplate($tid);
            $success = 'Template status toggled.';
        }
    } elseif (isset($_POST['delete_template'])) {
        $tid = intval($_POST['template_id'] ?? 0);
        if ($tid) {
            if (!$assessmentService->canDeleteTemplate($tid)) {
                $error = 'Cannot delete: this template has linked assessments. Deactivate it instead.';
            } else {
                $assessmentService->deleteTemplate($tid);
                $success = 'Template deleted.';
            }
        }
    } elseif (isset($_POST['duplicate_template'])) {
        $tid = intval($_POST['template_id'] ?? 0);
        if ($tid) {
            try {
                $newId = $assessmentService->duplicateTemplate($tid);
                $success = 'Template duplicated. <a href="?section=assessment-templates&action=edit&template_id=' . $newId . '">Edit the copy</a>';
            } catch (Exception $e) {
                error_log('Duplicate template error: ' . $e->getMessage());
                $error = 'Failed to duplicate template.';
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
        $error = 'Template not found.';
        $action = 'list';
    }
}

$categoryLabels = [
    'vendor_assessment' => 'Vendor Assessment',
    'procurement' => 'Procurement',
    'onboarding' => 'Onboarding',
];
?>

<?php if ($action === 'list'): ?>
<!-- ============================================================ -->
<!-- LIST VIEW -->
<!-- ============================================================ -->
<div class="page-header-bar">
    <h1>Assessment Template Builder</h1>
    <p>Create and manage assessment templates for vendor questionnaires.</p>
</div>

<div style="margin-bottom: 20px; display: flex; gap: 10px;">
    <button class="btn btn-primary" data-toggle="createTemplateModal">+ New Template</button>
    <a href="?section=field-reference" class="btn btn-secondary">Field Reference</a>
</div>

<div class="card">
    <?php if (empty($allTemplates)): ?>
    <div style="text-align:center; padding:40px; color:#666;">
        <div style="font-size:40px; margin-bottom:10px;">&#128221;</div>
        <h3>No templates yet</h3>
        <p>Create your first assessment template to get started.</p>
    </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Template</th>
                <th>Category</th>
                <th>Sections</th>
                <th>Questions</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allTemplates as $t): ?>
            <tr>
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
                    <span class="badge badge-success">Active</span>
                    <?php else: ?>
                    <span class="badge badge-danger">Inactive</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <a href="?section=assessment-templates&action=edit&template_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="template_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" name="toggle_template" class="btn btn-sm btn-secondary">
                            <?php echo $t['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="template_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" name="duplicate_template" class="btn btn-sm" style="background:#8b5cf6;color:white;">Duplicate</button>
                    </form>
                    <?php $builtinSlugs = ['iso-27001-2022','tier-2-vendor','vendor-onboarding','ai-usage'];
                    if (!in_array($t['slug'], $builtinSlugs) && $assessmentService->canDeleteTemplate($t['id'])): ?>
                    <form method="POST" style="display:inline;" data-confirm="Delete this template? This cannot be undone.">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="template_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" name="delete_template" class="btn btn-sm" style="background:#ef4444;color:white;">Delete</button>
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
        <h3 style="margin-top:0; color:var(--theme-header-color);">Create New Template</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <div class="form-group">
                <label>Template Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" class="form-control" required placeholder="e.g., SOC 2 Type II Assessment">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Brief description of this template..."></textarea>
            </div>
            <div class="form-group">
                <label>Category</label>
                <select name="category" class="form-control">
                    <option value="vendor_assessment">Vendor Assessment</option>
                    <option value="procurement">Procurement</option>
                    <option value="onboarding">Onboarding</option>
                </select>
            </div>
            <div id="certUploadGroup">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="allow_certificate_upload" value="1">
                        Allow certificate upload (skip assessment)
                    </label>
                </div>
                <div class="form-group" id="certPromptGroup" style="display:none;">
                    <label>Certificate Upload Prompt</label>
                    <textarea name="certificate_upload_prompt" class="form-control" rows="2" placeholder="Instructions shown to vendor when uploading a certificate..."></textarea>
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="submit" name="create_template" class="btn btn-primary">Create Template</button>
                <button type="button" class="btn btn-secondary" data-close="createTemplateModal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?php echo cspNonce(); ?>">
document.querySelector('#createTemplateModal [name="allow_certificate_upload"]')?.addEventListener('change', function() {
    document.getElementById('certPromptGroup').style.display = this.checked ? 'block' : 'none';
});
document.querySelector('#createTemplateModal [name="category"]')?.addEventListener('change', function() {
    document.getElementById('certUploadGroup').style.display = this.value === 'vendor_assessment' ? '' : 'none';
});
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
        Edit: <?php echo e($editTemplate['name']); ?>
    </h1>
    <p>
        <?php echo e($categoryLabels[$editTemplate['category'] ?? 'vendor_assessment'] ?? ''); ?> &middot;
        <?php echo $editTemplate['is_active'] ? '<span style="color:#166534;">Active</span>' : '<span style="color:#991b1b;">Inactive</span>'; ?>
        <?php if ($assessmentCount > 0): ?>
        &middot; <span style="color:#92400e;"><?php echo $assessmentCount; ?> linked assessment(s)</span>
        <?php endif; ?>
    </p>
</div>

<?php if ($assessmentCount > 0): ?>
<div class="alert" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;">
    This template has <?php echo $assessmentCount; ?> linked assessment(s). Changes will affect future assessments but not existing ones.
</div>
<?php endif; ?>

<!-- Template Metadata Card -->
<div class="card">
    <h3>Template Settings</h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="template_id" value="<?php echo $editTemplate['id']; ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Template Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" class="form-control" required value="<?php echo e($editTemplate['name']); ?>">
            </div>
            <div class="form-group">
                <label>Category</label>
                <select name="category" class="form-control">
                    <option value="vendor_assessment" <?php echo ($editTemplate['category'] ?? '') === 'vendor_assessment' ? 'selected' : ''; ?>>Vendor Assessment</option>
                    <option value="procurement" <?php echo ($editTemplate['category'] ?? '') === 'procurement' ? 'selected' : ''; ?>>Procurement</option>
                    <option value="onboarding" <?php echo ($editTemplate['category'] ?? '') === 'onboarding' ? 'selected' : ''; ?>>Onboarding</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" class="form-control" rows="2"><?php echo e($editTemplate['description'] ?? ''); ?></textarea>
        </div>
        <div id="editCertUploadGroup" style="<?php echo ($editTemplate['category'] ?? '') === 'vendor_assessment' ? '' : 'display:none;'; ?>">
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="allow_certificate_upload" value="1" <?php echo $editTemplate['allow_certificate_upload'] ? 'checked' : ''; ?> id="editCertToggle">
                    Allow certificate upload (skip assessment)
                </label>
            </div>
            <div class="form-group" id="editCertPromptGroup" style="<?php echo $editTemplate['allow_certificate_upload'] ? '' : 'display:none;'; ?>">
                <label>Certificate Upload Prompt</label>
                <textarea name="certificate_upload_prompt" class="form-control" rows="2"><?php echo e($editTemplate['certificate_upload_prompt'] ?? ''); ?></textarea>
            </div>
        </div>
        <button type="submit" name="update_template" class="btn btn-primary">Save Settings</button>
    </form>
</div>

<!-- Sections & Questions Builder -->
<div class="card" id="builderCard">
    <h3>Sections & Questions</h3>
    <div id="sectionsContainer">
        <?php foreach ($editTemplate['sections'] as $sIdx => $sec): ?>
        <div class="builder-section" data-section-id="<?php echo $sec['id']; ?>">
            <div class="section-header">
                <div class="section-header-left">
                    <strong class="section-title-text"><?php echo e($sec['name']); ?></strong>
                    <small style="color:#666; margin-left:10px;"><?php echo count($sec['questions']); ?> question(s)</small>
                </div>
                <div class="section-header-right">
                    <?php if ($sIdx > 0): ?>
                    <button type="button" class="btn btn-sm btn-secondary" data-action="reorderSection" data-args="<?php echo e(json_encode([$sec['id'], 'up'])); ?>" title="Move up">&#9650;</button>
                    <?php endif; ?>
                    <?php if ($sIdx < count($editTemplate['sections']) - 1): ?>
                    <button type="button" class="btn btn-sm btn-secondary" data-action="reorderSection" data-args="<?php echo e(json_encode([$sec['id'], 'down'])); ?>" title="Move down">&#9660;</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-primary" data-action="editSection" data-args="<?php echo e(json_encode([$sec['id'], $sec['name'], $sec['description'] ?? ''])); ?>">Edit</button>
                    <button type="button" class="btn btn-sm" style="background:#ef4444;color:white;" data-action="deleteSection" data-arg="<?php echo $sec['id']; ?>">Delete</button>
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
                        <span class="badge badge-danger" style="font-size:10px;">Required</span>
                        <?php endif; ?>
                        <?php if (!empty($q['field_name'])): ?>
                        <span class="badge" style="font-size:10px; background:#dbeafe; color:#1e40af;" title="Maps to vendor field: <?php echo e($q['field_name']); ?>"><?php echo e($q['field_name']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($q['depends_on_question_id'])): ?>
                        <span class="badge badge-purple" style="font-size:10px;" title="Conditional">Conditional</span>
                        <?php endif; ?>
                    </div>
                    <div class="question-row-right">
                        <?php if ($qIdx > 0): ?>
                        <button type="button" class="btn btn-sm btn-secondary" data-action="reorderQuestion" data-args="<?php echo e(json_encode([$q['id'], $sec['id'], 'up'])); ?>" title="Move up">&#9650;</button>
                        <?php endif; ?>
                        <?php if ($qIdx < count($sec['questions']) - 1): ?>
                        <button type="button" class="btn btn-sm btn-secondary" data-action="reorderQuestion" data-args="<?php echo e(json_encode([$q['id'], $sec['id'], 'down'])); ?>" title="Move down">&#9660;</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-primary" data-action="editQuestion" data-arg="<?php echo $q['id']; ?>">Edit</button>
                        <button type="button" class="btn btn-sm" style="background:#ef4444;color:white;" data-action="deleteQuestion" data-args="<?php echo e(json_encode([$q['id'], $sec['id']])); ?>">Delete</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm" style="background:#10b981;color:white; margin-top:10px;" data-action="addQuestion" data-arg="<?php echo $sec['id']; ?>">+ Add Question</button>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:20px; padding-top:15px; border-top:1px solid #e5e7eb;">
        <button type="button" class="btn btn-primary" data-action="addSection">+ Add Section</button>
    </div>
</div>

<!-- Section Modal -->
<div id="sectionModal" class="modal">
    <div class="modal-content">
        <span class="close" data-close="sectionModal">&times;</span>
        <h3 style="margin-top:0; color:var(--theme-header-color);" id="sectionModalTitle">Add Section</h3>
        <input type="hidden" id="sectionModalId" value="">
        <div class="form-group">
            <label>Section Name <span style="color:#ef4444;">*</span></label>
            <input type="text" id="sectionModalName" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea id="sectionModalDesc" class="form-control" rows="2"></textarea>
        </div>
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" class="btn btn-primary" data-action="saveSection">Save Section</button>
            <button type="button" class="btn btn-secondary" data-close="sectionModal">Cancel</button>
        </div>
    </div>
</div>

<!-- Question Modal -->
<div id="questionModal" class="modal">
    <div class="modal-content" style="max-width:650px;">
        <span class="close" data-close="questionModal">&times;</span>
        <h3 style="margin-top:0; color:var(--theme-header-color);" id="questionModalTitle">Add Question</h3>
        <input type="hidden" id="qModalId" value="">
        <input type="hidden" id="qModalSectionId" value="">
        <div class="form-group">
            <label>Question Text <span style="color:#ef4444;">*</span></label>
            <textarea id="qModalText" class="form-control" rows="2" required></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Question Type</label>
                <select id="qModalType" class="form-control" data-action="toggleOptionsEditor">
                    <option value="text">Text</option>
                    <option value="textarea">Textarea</option>
                    <option value="number">Number</option>
                    <option value="date">Date</option>
                    <option value="email">Email</option>
                    <option value="select">Dropdown</option>
                    <option value="radio">Radio Buttons</option>
                    <option value="checkbox">Checkboxes</option>
                    <option value="button_group">Button Group</option>
                    <option value="button_group_multi">Button Group (Multi)</option>
                    <option value="file">File Upload</option>
                </select>
            </div>
            <div class="form-group">
                <label>Required?</label>
                <select id="qModalRequired" class="form-control">
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Help Text</label>
            <input type="text" id="qModalHelp" class="form-control" placeholder="Optional help text shown below the question">
        </div>
        <div class="form-group">
            <label>Field Name <small style="color:#666;">(maps to vendor data field)</small>
                <a href="admin.php?section=field-reference" target="_blank" style="margin-left:8px; font-size:12px; font-weight:normal;">View Field Reference</a>
            </label>
            <select id="qModalFieldName" class="form-control">
                <option value="">-- None --</option>
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
            <label>Options <small style="color:#666;">(one per line)</small></label>
            <textarea id="qModalOptions" class="form-control" rows="4" placeholder="Yes&#10;No&#10;Not Applicable"></textarea>
        </div>
        <div style="border-top:1px solid #e5e7eb; padding-top:15px; margin-top:15px;">
            <label style="font-weight:600; font-size:13px; margin-bottom:10px; display:block;">Conditional Logic (optional)</label>
            <div class="form-row">
                <div class="form-group">
                    <label>Show when question:</label>
                    <select id="qModalDependsOn" class="form-control" data-action="loadDependsOnValues">
                        <option value="">-- Always visible --</option>
                        <?php foreach ($allQuestionsFlat as $aq): ?>
                        <option value="<?php echo $aq['id']; ?>" data-type="<?php echo e($aq['type']); ?>" data-options="<?php echo e(json_encode($aq['options'])); ?>">
                            [<?php echo e($aq['section_name']); ?>] <?php echo e(mb_strimwidth($aq['text'], 0, 50, '...')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Equals value:</label>
                    <select id="qModalDependsValue" class="form-control">
                        <option value="">--</option>
                    </select>
                </div>
            </div>
        </div>
        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="button" class="btn btn-primary" data-action="saveQuestion">Save Question</button>
            <button type="button" class="btn btn-secondary" data-close="questionModal">Cancel</button>
        </div>
    </div>
</div>

<style>
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
        warning.textContent = 'Warning: "' + val + '" is already used by another question in this template.';
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }
});

document.getElementById('editCertToggle')?.addEventListener('change', function() {
    document.getElementById('editCertPromptGroup').style.display = this.checked ? 'block' : 'none';
});
document.querySelector('form [name="category"]')?.addEventListener('change', function() {
    document.getElementById('editCertUploadGroup').style.display = this.value === 'vendor_assessment' ? '' : 'none';
});
</script>

<?php endif; ?>
