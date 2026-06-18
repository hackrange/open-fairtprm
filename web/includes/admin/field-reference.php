<?php
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
        $error = 'Invalid request. Please try again.';
    } elseif (isset($_POST['add_field'])) {
        $fieldName    = trim($_POST['field_name'] ?? '');
        $columnType   = trim($_POST['column_type'] ?? 'VARCHAR(500)');
        $fieldSection = trim($_POST['section'] ?? '');
        $fieldCategory = $_POST['category'] ?? 'all';
        $fieldDesc    = trim($_POST['description'] ?? '');

        if (empty($fieldName)) {
            $error = 'Field name is required.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $fieldName)) {
            $error = 'Field name must start with a lowercase letter and contain only lowercase letters, numbers, and underscores.';
        } elseif (empty($fieldSection)) {
            $error = 'Section is required.';
        } elseif (!in_array($fieldCategory, ['vendor_assessment', 'procurement', 'onboarding', 'all'])) {
            $error = 'Invalid category.';
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
                $success = 'Field <code>' . htmlspecialchars($fieldName) . '</code> added successfully.';
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'uk_field_name') !== false) {
                    $error = 'Field name <code>' . htmlspecialchars($fieldName) . '</code> already exists.';
                } else {
                    error_log('Add field error: ' . $e->getMessage());
                    $error = 'Failed to add field.';
                }
            }
        }
    } elseif (isset($_POST['delete_field'])) {
        $fieldId = intval($_POST['field_id'] ?? 0);
        if ($fieldId) {
            $field = $db->fetchOne("SELECT * FROM field_references WHERE id = ?", [$fieldId]);
            if (!$field) {
                $error = 'Field not found.';
            } elseif ($field['is_builtin']) {
                $error = 'Cannot delete built-in fields.';
            } else {
                $db->query("DELETE FROM field_references WHERE id = ? AND is_builtin = 0", [$fieldId]);
                $success = 'Field deleted.';
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
    'vendor_assessment' => 'Vendor Assessment',
    'procurement'       => 'Procurement',
    'onboarding'        => 'Onboarding',
    'all'               => 'ALL',
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
    <h1>Field Name Reference</h1>
    <p>Valid <code>field_name</code> values for assessment template questions. Built-in fields map to vendor onboarding columns. Custom fields can be added for any category.</p>
</div>

<div style="margin-bottom: 20px; display: flex; gap: 10px;">
    <button class="btn btn-primary" data-toggle="addFieldModal">+ Add Field</button>
    <a href="?section=assessment-templates" class="btn btn-secondary">&larr; Back to Templates</a>
</div>

<div class="card">
    <?php if (empty($fields)): ?>
    <div style="text-align:center; padding:40px; color:#666;">
        <h3>No fields defined</h3>
        <p>Add your first field reference to get started.</p>
    </div>
    <?php else: ?>
    <table class="table table-striped table-bordered" style="font-size:13px;">
        <thead>
            <tr style="background:#f8f9fa;">
                <th style="width:20%;">Field Name</th>
                <th style="width:15%;">Column Type</th>
                <th style="width:15%;">Section</th>
                <th style="width:14%;">Category</th>
                <th style="width:26%;">Description</th>
                <th style="width:10%;">Actions</th>
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
                        <span style="color:#999; font-size:11px;">Built-in</span>
                    <?php else: ?>
                        <form method="POST" style="display:inline;" data-confirm="Delete this field? This cannot be undone.">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="field_id" value="<?php echo $f['id']; ?>">
                            <button type="submit" name="delete_field" class="btn btn-sm" style="background:#ef4444;color:white;">Delete</button>
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
    <strong>Notes:</strong>
    <ul style="margin:8px 0 0 0; padding-left:20px;">
        <li>ENUM fields are stored as lowercase values (e.g., "Yes" response becomes "yes").</li>
        <li>Integer and decimal fields accept numeric input only.</li>
        <li>Each field_name can only be used once per template.</li>
        <li>Fields marked with "used in FAIR analysis" feed into automated risk calculations.</li>
        <li><strong>Category</strong> determines which template types a field applies to. Use "ALL" for fields usable across all template categories.</li>
    </ul>
</div>

<!-- Add Field Modal -->
<div id="addFieldModal" class="modal">
    <div class="modal-content" style="max-width:550px;">
        <span class="close" data-close="addFieldModal">&times;</span>
        <h3 style="margin-top:0; color:var(--theme-header-color);">Add Custom Field</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <div class="form-group">
                <label>Field Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="field_name" class="form-control" required placeholder="e.g., vendor_risk_tier" pattern="[a-z][a-z0-9_]*">
                <span class="form-help">Lowercase letters, numbers, and underscores only. Must start with a letter.</span>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Column Type</label>
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
                    <label>Category <span style="color:#ef4444;">*</span></label>
                    <select name="category" class="form-control" required>
                        <option value="all">ALL</option>
                        <option value="vendor_assessment">Vendor Assessment</option>
                        <option value="procurement">Procurement</option>
                        <option value="onboarding">Onboarding</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Section <span style="color:#ef4444;">*</span></label>
                <input type="text" name="section" class="form-control" required placeholder="e.g., Vendor Information, Data & Risk Assessment" list="sectionSuggestions">
                <datalist id="sectionSuggestions">
                    <?php foreach ($existingSections as $s): ?>
                    <option value="<?php echo e($s['section']); ?>">
                    <?php endforeach; ?>
                </datalist>
                <span class="form-help">Groups related fields together. Type a new name or select an existing section.</span>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" class="form-control" placeholder="Brief description of what this field captures">
            </div>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="submit" name="add_field" class="btn btn-primary">Add Field</button>
                <button type="button" class="btn btn-secondary" data-close="addFieldModal">Cancel</button>
            </div>
        </form>
    </div>
</div>
