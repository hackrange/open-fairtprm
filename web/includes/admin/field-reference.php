<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Field Name Reference
 *
 * Reference page listing all valid field_name values that can be used in
 * assessment template questions. Supports adding custom fields with category
 * assignment (Vendor Assessment, Procurement, Onboarding, or ALL).
 *
 * Built-in fields map to vendor_onboarding_requests columns and cannot be
 * deleted. Custom fields can be added for any category.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================
// TABLE INITIALIZATION
// ============================================================
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

// Seed built-in fields if table is empty
$seedCheck = $db->fetchOne("SELECT COUNT(*) AS cnt FROM field_references");
if (($seedCheck['cnt'] ?? 0) == 0) {
    $builtinFields = [
        // Section: Vendor Information
        ['vendor_name',                 'VARCHAR(500)',                              'Vendor Information',    'onboarding', 'Vendor legal name'],
        ['vendor_domain',               'VARCHAR(255)',                              'Vendor Information',    'onboarding', 'Vendor domain name for SRS scoring'],
        ['vendor_sisterdomains',        'TEXT',                                     'Vendor Information',    'onboarding', 'Related/sister domains for Shodan scanning (one per line)'],
        ['vendor_id',                   'VARCHAR(20)',                               'Vendor Information',    'onboarding', 'Vendor ID (VID)'],
        ['vendor_type',                 'VARCHAR(255)',                              'Vendor Information',    'onboarding', 'Vendor category/type'],
        ['relationship_manager',        'VARCHAR(255)',                              'Vendor Information',    'onboarding', 'Business relationship manager name'],
        ['expected_procurement_date',   'DATE',                                     'Vendor Information',    'onboarding', 'Expected procurement date'],
        ['product_service_description', 'TEXT',                                     'Vendor Information',    'onboarding', 'Product/service description (used in FAIR analysis)'],
        ['target_user_count',           'VARCHAR(100)',                              'Vendor Information',    'onboarding', 'Target user count (e.g., 50, 100-200)'],
        ['primary_contact_email',       'VARCHAR(255)',                              'Vendor Information',    'onboarding', 'Primary contact email address'],
        ['primary_contact_details',     'TEXT',                                     'Vendor Information',    'onboarding', 'Primary contact full name'],
        ['primary_contact_title',       'VARCHAR(255)',                              'Vendor Information',    'onboarding', 'Primary contact job title'],
        ['primary_contact_phone',       'VARCHAR(50)',                               'Vendor Information',    'onboarding', 'Primary contact direct phone'],
        ['vat_number',                  'VARCHAR(64)',                               'Vendor Information',    'onboarding', 'EU VAT number (validated against VIES)'],
        ['nda_in_place',                "ENUM('yes','no','')",                       'Vendor Information',    'onboarding', 'Is NDA in place?'],
        ['vendor_competitors',          'TEXT',                                     'Vendor Information',    'onboarding', 'Known vendor competitors'],
        ['vsu_onboarded',               "ENUM('yes','no','')",                       'Vendor Information',    'onboarding', 'Procurement onboarding status'],

        // Section: Engagement Details
        ['cost_center',                 'INT(10) UNSIGNED',                          'Engagement Details',    'onboarding', 'Accounting cost center (integer)'],
        ['project',                     'VARCHAR(255)',                              'Engagement Details',    'onboarding', 'Project name / identifier'],

        // Section: Data & Risk Assessment
        ['pii_record_count',                      'INT UNSIGNED',                              'Data & Risk Assessment', 'onboarding', 'Number of PII records'],
        ['spii_record_count',                     'INT UNSIGNED',                              'Data & Risk Assessment', 'onboarding', 'Number of Sensitive PII records'],
        ['sox_record_count',                      'INT UNSIGNED',                              'Data & Risk Assessment', 'onboarding', 'Number of SOX-regulated records'],
        ['business_impact',                       'DECIMAL(15,2)',                             'Data & Risk Assessment', 'onboarding', 'Business impact value in USD (used in FAIR analysis)'],
        ['confidential_info_shared',              "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Will confidential info be shared?'],
        ['confidential_info_justification',       'TEXT',                                     'Data & Risk Assessment', 'onboarding', 'Confidential info justification'],
        ['cross_border_transfer',                 "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Cross-border data transfer?'],
        ['cross_border_justification',            'TEXT',                                     'Data & Risk Assessment', 'onboarding', 'Cross-border transfer details'],
        ['offsite_data_hosting',                  "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Off-site data hosting?'],
        ['offsite_data_justification',            'TEXT',                                     'Data & Risk Assessment', 'onboarding', 'Off-site hosting details'],
        ['remote_network_access',                 "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Remote network access?'],
        ['remote_access_justification',           'TEXT',                                     'Data & Risk Assessment', 'onboarding', 'Remote access details'],
        ['source_code_access',                    "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Source code/repo access?'],
        ['source_code_justification',             'TEXT',                                     'Data & Risk Assessment', 'onboarding', 'Source code access details'],
        ['critical_business_function',            "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Supports critical business function?'],
        ['critical_function_justification',       'TEXT',                                     'Data & Risk Assessment', 'onboarding', 'Critical function details'],
        ['unauthorized_disclosure_impact',        "ENUM('low','moderate','high','severe','')", 'Data & Risk Assessment', 'onboarding', 'Impact of unauthorized disclosure'],
        ['unauthorized_disclosure_justification', 'TEXT',                                     'Data & Risk Assessment', 'onboarding', 'Disclosure impact justification'],
        ['unauthorized_modification_impact',      "ENUM('low','moderate','high','severe','')", 'Data & Risk Assessment', 'onboarding', 'Impact of unauthorized modification'],
        ['disruption_impact',                     "ENUM('low','moderate','high','severe','')", 'Data & Risk Assessment', 'onboarding', 'Impact of service disruption'],
        ['saml_sso_support',                      "ENUM('yes','no','unknown','')",             'Data & Risk Assessment', 'onboarding', 'SAML/SSO support?'],
        ['is_saas',                               "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Is SaaS product?'],
        ['vendor_use_ai',                         "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Does vendor solution use AI?'],

        // Section: Additional Information
        ['additional_information', 'TEXT', 'Additional Information', 'onboarding', 'Free-text additional information'],
    ];

    foreach ($builtinFields as $f) {
        $db->insert('field_references', [
            'field_name'  => $f[0],
            'column_type' => $f[1],
            'section'     => $f[2],
            'category'    => $f[3],
            'description' => $f[4],
            'is_builtin'  => 1,
        ]);
    }
}

// ============================================================
// POST HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_field-reference.err_invalid_request');
    } elseif (isset($_POST['add_field'])) {
        $fieldName    = trim($_POST['field_name'] ?? '');
        $columnType   = trim($_POST['column_type'] ?? 'VARCHAR(500)');
        $fieldSection = trim($_POST['section'] ?? '');
        $fieldCategory = $_POST['category'] ?? 'all';
        $fieldDesc    = trim($_POST['description'] ?? '');

        if (empty($fieldName)) {
            $error = t('admin_field-reference.err_field_name_required');
        } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $fieldName)) {
            $error = t('admin_field-reference.err_field_name_pattern');
        } elseif (empty($fieldSection)) {
            $error = t('admin_field-reference.err_section_required');
        } elseif (!in_array($fieldCategory, ['vendor_assessment', 'procurement', 'onboarding', 'all'])) {
            $error = t('admin_field-reference.err_invalid_category');
        } else {
            try {
                $db->insert('field_references', [
                    'field_name'  => $fieldName,
                    'column_type' => $columnType,
                    'section'     => $fieldSection,
                    'category'    => $fieldCategory,
                    'description' => $fieldDesc,
                    'is_builtin'  => 0,
                ]);
                $success = t('admin_field-reference.add_success_prefix') . '<code>' . htmlspecialchars($fieldName) . '</code>' . t('admin_field-reference.add_success_suffix');
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'uk_field_name') !== false) {
                    $error = t('admin_field-reference.err_duplicate_prefix') . '<code>' . htmlspecialchars($fieldName) . '</code>' . t('admin_field-reference.err_duplicate_suffix');
                } else {
                    error_log('Add field error: ' . $e->getMessage());
                    $error = t('admin_field-reference.err_add_failed');
                }
            }
        }
    } elseif (isset($_POST['delete_field'])) {
        $fieldId = intval($_POST['field_id'] ?? 0);
        if ($fieldId) {
            $field = $db->fetchOne("SELECT * FROM field_references WHERE id = ?", [$fieldId]);
            if (!$field) {
                $error = t('admin_field-reference.err_field_not_found');
            } elseif ($field['is_builtin']) {
                $error = t('admin_field-reference.err_cannot_delete_builtin');
            } else {
                $db->query("DELETE FROM field_references WHERE id = ? AND is_builtin = 0", [$fieldId]);
                $success = t('admin_field-reference.success_field_deleted');
            }
        }
    }
}

// ============================================================
// LOAD DATA
// ============================================================
$fields = $db->fetchAll("SELECT * FROM field_references ORDER BY
    CASE category
        WHEN 'all' THEN 0
        WHEN 'onboarding' THEN 1
        WHEN 'vendor_assessment' THEN 2
        WHEN 'procurement' THEN 3
    END, section, field_name");

$categoryLabels = [
    'vendor_assessment' => t('admin_field-reference.cat_vendor_assessment'),
    'procurement'       => t('admin_field-reference.cat_procurement'),
    'onboarding'        => t('admin_field-reference.cat_onboarding'),
    'all'               => t('admin_field-reference.cat_all'),
];

$categoryBadgeStyles = [
    'vendor_assessment' => 'background:#dbeafe; color:#1e40af;',
    'procurement'       => 'background:#fef3c7; color:#92400e;',
    'onboarding'        => 'background:#dcfce7; color:#166534;',
    'all'               => 'background:#f3e8ff; color:#7c3aed;',
];

// Collect distinct sections for the dropdown
$existingSections = $db->fetchAll("SELECT DISTINCT section FROM field_references WHERE section != '' ORDER BY section");
?>

<div class="page-header-bar">
    <h1><?php echo e(t('admin_field-reference.page_title')); ?></h1>
    <p><?php echo t('admin_field-reference.page_intro'); ?></p>
</div>

<div style="margin-bottom: 20px; display: flex; gap: 10px;">
    <button class="btn btn-primary" data-toggle="addFieldModal"><?php echo e(t('admin_field-reference.add_field_button')); ?></button>
    <a href="?section=assessment-templates" class="btn btn-secondary"><?php echo t('admin_field-reference.back_to_templates'); ?></a>
</div>

<div class="card">
    <?php if (empty($fields)): ?>
    <div style="text-align:center; padding:40px; color:#666;">
        <h3><?php echo e(t('admin_field-reference.no_fields_heading')); ?></h3>
        <p><?php echo e(t('admin_field-reference.no_fields_text')); ?></p>
    </div>
    <?php else: ?>
    <table class="table table-striped table-bordered" style="font-size:13px;">
        <thead>
            <tr style="background:#f8f9fa;">
                <th style="width:20%;"><?php echo e(t('admin_field-reference.field_name_label')); ?></th>
                <th style="width:15%;"><?php echo e(t('admin_field-reference.column_type_label')); ?></th>
                <th style="width:15%;"><?php echo e(t('admin_field-reference.section_label')); ?></th>
                <th style="width:14%;"><?php echo e(t('admin_field-reference.category_label')); ?></th>
                <th style="width:26%;"><?php echo e(t('admin_field-reference.description_label')); ?></th>
                <th style="width:10%;"><?php echo e(t('admin_field-reference.actions_label')); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($fields as $f): ?>
            <tr>
                <td><code><?php echo e($f['field_name']); ?></code></td>
                <td><code style="color:#8b5cf6;"><?php echo e($f['column_type']); ?></code></td>
                <td><?php echo e($f['section']); ?></td>
                <td>
                    <span class="badge" style="<?php echo $categoryBadgeStyles[$f['category']] ?? 'background:#e5e7eb; color:#374151;'; ?>">
                        <?php echo e($categoryLabels[$f['category']] ?? $f['category']); ?>
                    </span>
                </td>
                <td><?php echo e($f['description']); ?></td>
                <td>
                    <?php if ($f['is_builtin']): ?>
                        <span style="color:#999; font-size:11px;"><?php echo e(t('admin_field-reference.builtin_label')); ?></span>
                    <?php else: ?>
                        <form method="POST" style="display:inline;" data-confirm="<?php echo e(t('admin_field-reference.delete_confirm')); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="field_id" value="<?php echo $f['id']; ?>">
                            <button type="submit" name="delete_field" class="btn btn-sm" style="background:#ef4444;color:white;"><?php echo e(t('admin_field-reference.delete_button')); ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div style="margin-top:20px; padding:12px 16px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; font-size:13px;">
    <strong><?php echo e(t('admin_field-reference.notes_label')); ?></strong>
    <ul style="margin:8px 0 0 0; padding-left:20px;">
        <li><?php echo e(t('admin_field-reference.note_enum')); ?></li>
        <li><?php echo e(t('admin_field-reference.note_numeric')); ?></li>
        <li><?php echo e(t('admin_field-reference.note_unique')); ?></li>
        <li><?php echo e(t('admin_field-reference.note_fair')); ?></li>
        <li><?php echo t('admin_field-reference.note_category'); ?></li>
    </ul>
</div>

<!-- Add Field Modal -->
<div id="addFieldModal" class="modal">
    <div class="modal-content" style="max-width:550px;">
        <span class="close" data-close="addFieldModal">&times;</span>
        <h3 style="margin-top:0; color:var(--theme-header-color);"><?php echo e(t('admin_field-reference.modal_title')); ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <div class="form-group">
                <label><?php echo e(t('admin_field-reference.field_name_label')); ?> <span style="color:#ef4444;">*</span></label>
                <input type="text" name="field_name" class="form-control" required placeholder="<?php echo e(t('admin_field-reference.field_name_placeholder')); ?>" pattern="[a-z][a-z0-9_]*">
                <span class="form-help"><?php echo e(t('admin_field-reference.field_name_help')); ?></span>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php echo e(t('admin_field-reference.column_type_label')); ?></label>
                    <select name="column_type" class="form-control">
                        <option value="VARCHAR(500)">VARCHAR(500)</option>
                        <option value="VARCHAR(255)">VARCHAR(255)</option>
                        <option value="VARCHAR(100)">VARCHAR(100)</option>
                        <option value="TEXT">TEXT</option>
                        <option value="INT UNSIGNED">INT UNSIGNED</option>
                        <option value="DECIMAL(15,2)">DECIMAL(15,2)</option>
                        <option value="DATE">DATE</option>
                        <option value="ENUM('yes','no','')">ENUM('yes','no','')</option>
                        <option value="ENUM('low','moderate','high','severe','')">ENUM('low','moderate','high','severe','')</option>
                        <option value="ENUM('yes','no','unknown','')">ENUM('yes','no','unknown','')</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><?php echo e(t('admin_field-reference.category_label')); ?> <span style="color:#ef4444;">*</span></label>
                    <select name="category" class="form-control" required>
                        <option value="all"><?php echo e(t('admin_field-reference.cat_all')); ?></option>
                        <option value="vendor_assessment"><?php echo e(t('admin_field-reference.cat_vendor_assessment')); ?></option>
                        <option value="procurement"><?php echo e(t('admin_field-reference.cat_procurement')); ?></option>
                        <option value="onboarding"><?php echo e(t('admin_field-reference.cat_onboarding')); ?></option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label><?php echo e(t('admin_field-reference.section_label')); ?> <span style="color:#ef4444;">*</span></label>
                <input type="text" name="section" class="form-control" required placeholder="<?php echo e(t('admin_field-reference.section_placeholder')); ?>" list="sectionSuggestions">
                <datalist id="sectionSuggestions">
                    <?php foreach ($existingSections as $s): ?>
                    <option value="<?php echo e($s['section']); ?>">
                    <?php endforeach; ?>
                </datalist>
                <span class="form-help"><?php echo e(t('admin_field-reference.section_help')); ?></span>
            </div>
            <div class="form-group">
                <label><?php echo e(t('admin_field-reference.description_label')); ?></label>
                <input type="text" name="description" class="form-control" placeholder="<?php echo e(t('admin_field-reference.description_placeholder')); ?>">
            </div>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="submit" name="add_field" class="btn btn-primary"><?php echo e(t('admin_field-reference.add_field_submit')); ?></button>
                <button type="button" class="btn btn-secondary" data-close="addFieldModal"><?php echo e(t('admin_field-reference.cancel_button')); ?></button>
            </div>
        </form>
    </div>
</div>
