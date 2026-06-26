<?php
/**
 * Vendor Assessment Service - The Questionnaire Wrangler
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Manages the entire lifecycle of vendor security assessments -- from creation
 * to completion, with all the fun stuff in between: UUID-based access tokens
 * (so vendors can fill out forms without needing an account), multi-step forms
 * with autosave (because losing 45 minutes of checkbox-clicking is soul-crushing),
 * certificate uploads, and even auto-triggering FAIR analysis when an assessment
 * completes. Think of it as the project manager for vendor questionnaires: it
 * creates them, tracks them, nags about them, and celebrates when they're done.
 */

class VendorAssessmentService {
    private $db;
    /** @var array per-template custom-item maps cache (see getOnboardingCustomMaps) */
    private $customMapsCache = [];

    /**
     * Grab the database singleton and we're off to the races.
     */
    /**
     * The role slugs that can be granted per-section / per-question visibility on
     * ONBOARDING templates. 'super_admin' is the is_super_admin flag (not an ACL
     * group). Empty selection on a section/question = visible to everyone.
     */
    const VISIBILITY_ROLES = ['stakeholder', 'cyber_tprm', 'administrator', 'auditor', 'procurement', 'super_admin'];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Decode a visible_roles value (JSON string or array) into a clean slug list.
     */
    private static function decodeRoles($value) {
        if (is_array($value)) {
            $arr = $value;
        } elseif (is_string($value) && $value !== '') {
            $arr = json_decode($value, true);
        } else {
            $arr = [];
        }
        if (!is_array($arr)) $arr = [];
        return array_values(array_filter(array_map('strval', $arr), function ($r) { return $r !== ''; }));
    }

    /**
     * Sanitize a visible_roles input (from the template builder) down to the known
     * VISIBILITY_ROLES, returning a JSON string or null when nothing valid remains.
     */
    public static function sanitizeVisibleRoles($value) {
        $valid = array_values(array_intersect(self::decodeRoles($value), self::VISIBILITY_ROLES));
        return empty($valid) ? null : json_encode($valid);
    }

    /**
     * Decide whether a viewer may see an item given its visible_roles value.
     * Empty/NULL restriction = visible to everyone. Super admins always pass.
     * Otherwise the viewer must belong to one of the listed groups.
     *
     * @param mixed $visibleRoles JSON string / array stored on the section or question
     * @param array $viewerGroups the viewer's ACL group slugs
     * @param bool  $isSuperAdmin whether the viewer has the is_super_admin flag
     */
    public static function roleGatePasses($visibleRoles, array $viewerGroups, $isSuperAdmin) {
        if ($isSuperAdmin) return true;
        $roles = self::decodeRoles($visibleRoles);
        if (empty($roles)) return true; // unrestricted
        return count(array_intersect($viewerGroups, $roles)) > 0;
    }

    /**
     * Sanitize an editable_roles input. Same role universe as visible_roles.
     */
    public static function sanitizeEditableRoles($value) {
        return self::sanitizeVisibleRoles($value);
    }

    /**
     * Decide whether a viewer may EDIT an item given its editable_roles value.
     * Unlike visibility, an empty/NULL restriction means NO ONE may edit (edit is
     * grant-only — "no one until granted"); a role must be explicitly listed.
     * Super admins always pass.
     *
     * @param mixed $editableRoles JSON string / array stored on the section or question
     * @param array $viewerGroups  the viewer's ACL group slugs
     * @param bool  $isSuperAdmin  whether the viewer has the is_super_admin flag
     */
    public static function roleCanEdit($editableRoles, array $viewerGroups, $isSuperAdmin) {
        if ($isSuperAdmin) return true;
        $roles = self::decodeRoles($editableRoles);
        if (empty($roles)) return false; // grant-only: nobody edits until a role is added
        return count(array_intersect($viewerGroups, $roles)) > 0;
    }

    /**
     * Decide whether a viewer may SEE an item. Edit access always implies
     * visibility. Super admins always pass.
     *
     * The default for empty visible_roles depends on $isCustom:
     *  - STANDARD items (isCustom=false): empty visible_roles = visible to ALL
     *    (the original onboarding questions behave as they always have).
     *  - CUSTOM items (isCustom=true): visibility is GRANT-ONLY — an empty
     *    visible_roles means visible to NO ONE except super admins and any role
     *    explicitly granted visible_roles or editable_roles. Added (custom) fields
     *    and sections stay hidden until a role is granted.
     *
     * @param mixed $visibleRoles  visible_roles value on the section/question
     * @param mixed $editableRoles editable_roles value on the section/question
     * @param bool  $isCustom      whether the item is a custom onboarding field/section
     */
    public static function roleCanSee($visibleRoles, $editableRoles, array $viewerGroups, $isSuperAdmin, $isCustom = false) {
        if ($isSuperAdmin) return true;
        if ($isCustom) {
            // Grant-only: must be explicitly listed under visible_roles...
            $vis = self::decodeRoles($visibleRoles);
            if (!empty($vis) && count(array_intersect($viewerGroups, $vis)) > 0) return true;
        } else {
            // Standard: empty visible_roles = visible to everyone.
            if (self::roleGatePasses($visibleRoles, $viewerGroups, $isSuperAdmin)) return true;
        }
        // ...or hold edit access, which always implies visibility.
        return self::roleCanEdit($editableRoles, $viewerGroups, $isSuperAdmin);
    }

    /**
     * Per-template custom maps: which sections and questions are "custom" (i.e.
     * added beyond the original onboarding questions). A question is custom when
     * its field_name is set but is NOT a real vendor_onboarding_requests column.
     * A section is custom when it contains no standard (real-column) question.
     * Only onboarding-category templates have this distinction; for any other
     * category every item is treated as standard (empty maps).
     *
     * Result: ['sections' => [sectionId => true], 'questions' => [questionId => true]].
     * Cached per template id for the lifetime of the service instance.
     */
    public function getOnboardingCustomMaps($templateId) {
        $templateId = (int)$templateId;
        if (isset($this->customMapsCache[$templateId])) return $this->customMapsCache[$templateId];

        $out = ['sections' => [], 'questions' => []];
        $tpl = $this->db->fetchOne('SELECT category FROM assessment_templates WHERE id = :id', [':id' => $templateId]);
        if (!$tpl || ($tpl['category'] ?? '') !== 'onboarding') {
            return $this->customMapsCache[$templateId] = $out;
        }
        $known = $this->getVendorColumnSet();
        foreach ($this->getSections($templateId) as $sec) {
            $sectionHasStandard = false;
            foreach ($this->getQuestions($sec['id']) as $q) {
                $fn = trim((string)($q['field_name'] ?? ''));
                if ($fn === '') continue;                       // informational: neither custom nor standard
                if (isset($known[strtolower($fn)])) {
                    $sectionHasStandard = true;                 // maps to a real column => standard
                } else {
                    $out['questions'][(int)$q['id']] = true;    // custom field
                }
            }
            if (!$sectionHasStandard) $out['sections'][(int)$sec['id']] = true; // custom section
        }
        return $this->customMapsCache[$templateId] = $out;
    }

    /**
     * Reads the SQL schema file and runs each statement to create the assessment
     * tables if they don't already exist. Ignores "already exists" and "duplicate"
     * errors because this might run on every deploy and we don't want it to
     * explode just because the tables were already there. Kind of like a
     * polite "CREATE TABLE IF NOT EXISTS" but for multiple statements.
     */
    public function initializeTables() {
        $schemaFile = dirname(__DIR__) . '/schema/vendor_assessments.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);

            // Split the SQL file by semicolons and run each statement individually.
            // Why not just run the whole file at once? Because PDO doesn't like
            // multiple statements, and we want to handle errors per-statement.
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if (!empty($statement) && !preg_match('/^--/', $statement)) {
                    try {
                        $this->db->query($statement);
                    } catch (Exception $e) {
                        // Gracefully ignore "table already exists" and duplicate errors.
                        // Anything else gets logged because that's actually a problem.
                        $msg = strtolower($e->getMessage());
                        $ignorable = strpos($msg, 'already exists') !== false ||
                                     strpos($msg, 'duplicate') !== false ||
                                     strpos($msg, 'unique constraint') !== false;
                        if (!$ignorable) {
                            error_log('Schema error: ' . $e->getMessage());
                        }
                    }
                }
            }
        }
    }

    /**
     * Get all assessment templates. By default only returns active ones.
     * Optionally filter by category (vendor_assessment, procurement, onboarding).
     */
    public function getTemplates($activeOnly = true, $category = null) {
        $sql = 'SELECT t.*, (SELECT COUNT(*) FROM assessment_questions q JOIN assessment_sections s ON q.section_id = s.id WHERE s.template_id = t.id) as question_count, (SELECT COUNT(*) FROM assessment_sections s2 WHERE s2.template_id = t.id) as section_count FROM assessment_templates t';
        $where = [];
        $params = [];

        if ($activeOnly) {
            $where[] = 't.is_active = 1';
        }
        if ($category) {
            $where[] = 't.category = :category';
            $params[':category'] = $category;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.name';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Look up a template by ID (numeric) or slug (string).
     * Automatically detects which one you passed -- because being
     * flexible about input types is the polite thing to do.
     */
    public function getTemplate($idOrSlug) {
        $field = is_numeric($idOrSlug) ? 'id' : 'slug';
        return $this->db->fetchOne(
            "SELECT * FROM assessment_templates WHERE {$field} = :value",
            [':value' => $idOrSlug]
        );
    }

    /**
     * Get all sections for a given template, sorted by their display order.
     * Sections are like chapters in the assessment book -- "Company Info",
     * "Security Controls", "Data Handling", etc.
     */
    public function getSections($templateId) {
        return $this->db->fetchAll(
            'SELECT * FROM assessment_sections WHERE template_id = :template_id ORDER BY sort_order',
            [':template_id' => $templateId]
        );
    }

    /**
     * Get all questions within a section, sorted by display order.
     * JSON-decodes the options field since some question types (dropdowns,
     * checkboxes) store their possible answers as JSON. Because of course they do.
     */
    public function getQuestions($sectionId) {
        $questions = $this->db->fetchAll(
            'SELECT * FROM assessment_questions WHERE section_id = :section_id ORDER BY sort_order',
            [':section_id' => $sectionId]
        );

        // Decode JSON options for multi-choice questions
        foreach ($questions as &$q) {
            if ($q['options']) {
                $q['options'] = json_decode($q['options'], true);
            }
        }

        return $questions;
    }

    /**
     * Get a single question by ID.
     */
    public function getQuestion($questionId) {
        return $this->db->fetchOne(
            'SELECT * FROM assessment_questions WHERE id = :id',
            [':id' => $questionId]
        );
    }

    /**
     * SECURITY (IDOR): verify a question actually belongs to a given template.
     * The public assessment-save endpoints resolve the assessment from its UUID
     * token but accept the question_id separately from the client. Without this
     * check a token holder could write responses for off-template / cross-template
     * questions (and abuse field-mapped questions like vendor_name). The
     * relationship is assessment_questions.section_id -> assessment_sections.template_id.
     */
    public function questionBelongsToTemplate($questionId, $templateId) {
        $row = $this->db->fetchOne(
            'SELECT q.id FROM assessment_questions q
               JOIN assessment_sections s ON q.section_id = s.id
              WHERE q.id = :qid AND s.template_id = :tid',
            [':qid' => (int)$questionId, ':tid' => (int)$templateId]
        );
        return !empty($row);
    }

    /**
     * Get ALL questions for a template in one shot, with section info joined in.
     * Useful for bulk operations like calculating completion percentage or
     * exporting the full assessment. Sorted by section order, then question order.
     */
    public function getAllQuestions($templateId) {
        $questions = $this->db->fetchAll(
            'SELECT q.*, s.name as section_name, s.sort_order as section_order
             FROM assessment_questions q
             JOIN assessment_sections s ON q.section_id = s.id
             WHERE s.template_id = :template_id
             ORDER BY s.sort_order, q.sort_order',
            [':template_id' => $templateId]
        );

        // Same JSON decode dance as getQuestions()
        foreach ($questions as &$q) {
            if ($q['options']) {
                $q['options'] = json_decode($q['options'], true);
            }
        }

        return $questions;
    }

    // ========================================================================
    // TEMPLATE CRUD - Admin template builder operations
    // ========================================================================

    /**
     * Get a template with all its sections and questions loaded.
     * Used by the template builder edit view.
     */
    public function getTemplateWithSectionsAndQuestions($templateId) {
        $template = $this->getTemplate($templateId);
        if (!$template) return null;

        $template['sections'] = [];
        $sections = $this->getSections($templateId);
        foreach ($sections as $section) {
            $section['questions'] = $this->getQuestions($section['id']);
            $template['sections'][] = $section;
        }
        return $template;
    }

    /**
     * Generate a URL-safe slug from a template name. Appends a number if the slug
     * already exists so we never violate the unique constraint.
     */
    public function generateUniqueSlug($name) {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        if (empty($slug)) $slug = 'template';

        $baseSlug = $slug;
        $counter = 1;
        while ($this->db->fetchOne('SELECT id FROM assessment_templates WHERE slug = :slug', [':slug' => $slug])) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        return $slug;
    }

    /**
     * Create a new assessment template.
     */
    public function createTemplate($data) {
        $slug = $this->generateUniqueSlug($data['name']);
        $mode = $this->normalizeCertificateMode($data['certificate_upload_mode'] ?? null, $data['allow_certificate_upload'] ?? null);
        return $this->db->insert('assessment_templates', [
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? '',
            'category' => $data['category'] ?? 'vendor_assessment',
            'certificate_upload_mode' => $mode,
            // Kept in sync with the mode so existing checks that gate the cert-upload
            // UI keep working: enabled for both 'skip' and 'minimal'.
            'allow_certificate_upload' => $mode === 'none' ? 0 : 1,
            'certificate_upload_prompt' => $data['certificate_upload_prompt'] ?? null,
            'is_active' => 0, // New templates start inactive
        ]);
    }

    /**
     * Normalize a certificate-upload mode value to one of none|skip|minimal.
     * Falls back to the legacy boolean allow_certificate_upload when no explicit
     * mode is provided (so older callers keep working): true => 'skip'.
     */
    public function normalizeCertificateMode($mode, $legacyAllow = null) {
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';
        if (in_array($mode, ['none', 'skip', 'minimal'], true)) {
            return $mode;
        }
        return !empty($legacyAllow) ? 'skip' : 'none';
    }

    /**
     * Update template metadata (name, description, category, certificate settings).
     */
    public function updateTemplate($templateId, $data) {
        $mode = $this->normalizeCertificateMode($data['certificate_upload_mode'] ?? null, $data['allow_certificate_upload'] ?? null);
        $update = [
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'category' => $data['category'] ?? 'vendor_assessment',
            'certificate_upload_mode' => $mode,
            'allow_certificate_upload' => $mode === 'none' ? 0 : 1,
            'certificate_upload_prompt' => $data['certificate_upload_prompt'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        return $this->db->update('assessment_templates', $update, 'id = :id', [':id' => $templateId]);
    }

    /**
     * Set which questions make up the "minimal" certificate-mode set for a template.
     * Flags the given question IDs (scoped to this template) include_in_minimal = 1 and
     * clears the flag on all other questions of the template. Pass an empty array to
     * clear the whole set. Uses the same section-subquery scoping as the SQL migrations.
     */
    public function setMinimalQuestions($templateId, array $questionIds) {
        $this->db->query(
            'UPDATE assessment_questions SET include_in_minimal = 0
             WHERE section_id IN (SELECT id FROM assessment_sections WHERE template_id = :tid)',
            [':tid' => $templateId]
        );

        $ids = array_values(array_unique(array_filter(array_map('intval', $questionIds))));
        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = (int)$templateId;
        $this->db->query(
            "UPDATE assessment_questions SET include_in_minimal = 1
             WHERE id IN ($placeholders)
               AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = ?)",
            $params
        );
    }

    /**
     * Toggle template active/inactive.
     */
    public function toggleTemplate($templateId) {
        $template = $this->getTemplate($templateId);
        if (!$template) return false;
        $newState = $template['is_active'] ? 0 : 1;
        return $this->db->update('assessment_templates', [
            'is_active' => $newState,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', [':id' => $templateId]);
    }

    /**
     * Check if a template can be safely deleted (no linked assessments).
     */
    public function canDeleteTemplate($templateId) {
        $count = $this->db->fetchOne(
            'SELECT COUNT(*) as cnt FROM vendor_assessments WHERE template_id = :id',
            [':id' => $templateId]
        );
        return ($count['cnt'] ?? 0) == 0;
    }

    /**
     * Delete a template and all its sections/questions (CASCADE).
     */
    public function deleteTemplate($templateId) {
        return $this->db->query('DELETE FROM assessment_templates WHERE id = :id', [':id' => $templateId]);
    }

    /**
     * Deep-copy a template including all sections, questions, and conditional logic.
     * Remaps depends_on_question_id references to the new question IDs.
     */
    public function duplicateTemplate($templateId) {
        $source = $this->getTemplateWithSectionsAndQuestions($templateId);
        if (!$source) return false;

        // Create new template
        $newName = $source['name'] . ' (Copy)';
        $newTemplateId = $this->createTemplate([
            'name' => $newName,
            'description' => $source['description'],
            'category' => $source['category'] ?? 'vendor_assessment',
            'certificate_upload_mode' => $source['certificate_upload_mode'] ?? null,
            'allow_certificate_upload' => $source['allow_certificate_upload'],
            'certificate_upload_prompt' => $source['certificate_upload_prompt'],
        ]);

        // Map old question IDs to new ones for conditional logic remapping
        $questionIdMap = [];

        foreach ($source['sections'] as $section) {
            $newSectionId = $this->db->insert('assessment_sections', [
                'template_id' => $newTemplateId,
                'name' => $section['name'],
                'description' => $section['description'],
                'sort_order' => $section['sort_order'],
            ]);

            foreach ($section['questions'] as $q) {
                $newQuestionId = $this->db->insert('assessment_questions', [
                    'section_id' => $newSectionId,
                    'question_text' => $q['question_text'],
                    'question_type' => $q['question_type'],
                    'options' => is_array($q['options']) ? json_encode($q['options']) : $q['options'],
                    'is_required' => $q['is_required'],
                    'help_text' => $q['help_text'],
                    'sort_order' => $q['sort_order'],
                    'field_name' => $q['field_name'] ?? null,
                    'include_in_minimal' => $q['include_in_minimal'] ?? 0,
                    // depends_on fields will be remapped after all questions are created
                    'depends_on_question_id' => null,
                    'depends_on_value' => $q['depends_on_value'] ?? null,
                ]);
                $questionIdMap[$q['id']] = $newQuestionId;
            }
        }

        // Second pass: remap conditional logic references
        foreach ($source['sections'] as $section) {
            foreach ($section['questions'] as $q) {
                if (!empty($q['depends_on_question_id']) && isset($questionIdMap[$q['depends_on_question_id']])) {
                    $newQid = $questionIdMap[$q['id']];
                    $newDependsOn = $questionIdMap[$q['depends_on_question_id']];
                    $this->db->update('assessment_questions', [
                        'depends_on_question_id' => $newDependsOn,
                    ], 'id = :id', [':id' => $newQid]);
                }
            }
        }

        return $newTemplateId;
    }

    // ========================================================================
    // SECTION CRUD
    // ========================================================================

    /**
     * Create a new section in a template.
     */
    public function createSection($templateId, $data) {
        // Get next sort order
        $max = $this->db->fetchOne(
            'SELECT COALESCE(MAX(sort_order), 0) as max_order FROM assessment_sections WHERE template_id = :tid',
            [':tid' => $templateId]
        );
        return $this->db->insert('assessment_sections', [
            'template_id' => $templateId,
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'sort_order' => ($max['max_order'] ?? 0) + 1,
            'visible_roles' => array_key_exists('visible_roles', $data) ? $data['visible_roles'] : null,
            'editable_roles' => array_key_exists('editable_roles', $data) ? $data['editable_roles'] : null,
        ]);
    }

    /**
     * Update a section's name and description.
     */
    public function updateSection($sectionId, $data) {
        $update = [
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
        ];
        if (array_key_exists('visible_roles', $data)) {
            $update['visible_roles'] = $data['visible_roles'];
        }
        if (array_key_exists('editable_roles', $data)) {
            $update['editable_roles'] = $data['editable_roles'];
        }
        return $this->db->update('assessment_sections', $update, 'id = :id', [':id' => $sectionId]);
    }

    /**
     * Delete a section (CASCADE deletes its questions too).
     */
    public function deleteSection($sectionId) {
        // Clear any depends_on references pointing to questions in this section
        $this->db->query(
            'UPDATE assessment_questions SET depends_on_question_id = NULL, depends_on_value = NULL WHERE depends_on_question_id IN (SELECT id FROM assessment_questions WHERE section_id = :sid)',
            [':sid' => $sectionId]
        );
        return $this->db->query('DELETE FROM assessment_sections WHERE id = :id', [':id' => $sectionId]);
    }

    /**
     * Reorder sections by an array of section IDs in the desired order.
     */
    public function reorderSections($templateId, $sectionIds) {
        // Two-pass to avoid unique constraint (template_id, sort_order) conflicts:
        // Pass 1: set all to negative offsets (guaranteed unique, no conflicts)
        foreach ($sectionIds as $order => $sectionId) {
            $this->db->update('assessment_sections', [
                'sort_order' => -($order + 1),
            ], 'id = :id AND template_id = :tid', [':id' => $sectionId, ':tid' => $templateId]);
        }
        // Pass 2: set to final positive values
        foreach ($sectionIds as $order => $sectionId) {
            $this->db->update('assessment_sections', [
                'sort_order' => $order + 1,
            ], 'id = :id AND template_id = :tid', [':id' => $sectionId, ':tid' => $templateId]);
        }
        return true;
    }

    // ========================================================================
    // QUESTION CRUD
    // ========================================================================

    /**
     * Create a new question in a section.
     */
    public function createQuestion($sectionId, $data) {
        $max = $this->db->fetchOne(
            'SELECT COALESCE(MAX(sort_order), 0) as max_order FROM assessment_questions WHERE section_id = :sid',
            [':sid' => $sectionId]
        );
        return $this->db->insert('assessment_questions', [
            'section_id' => $sectionId,
            'question_text' => $data['question_text'],
            'question_type' => $data['question_type'] ?? 'text',
            'options' => !empty($data['options']) ? (is_array($data['options']) ? json_encode($data['options']) : $data['options']) : null,
            'is_required' => isset($data['is_required']) ? (int)$data['is_required'] : 1,
            'help_text' => $data['help_text'] ?? null,
            'sort_order' => ($max['max_order'] ?? 0) + 1,
            'field_name' => !empty($data['field_name']) ? $data['field_name'] : null,
            'depends_on_question_id' => !empty($data['depends_on_question_id']) ? (int)$data['depends_on_question_id'] : null,
            'depends_on_value' => $data['depends_on_value'] ?? null,
            'visible_roles' => array_key_exists('visible_roles', $data) ? $data['visible_roles'] : null,
            'editable_roles' => array_key_exists('editable_roles', $data) ? $data['editable_roles'] : null,
        ]);
    }

    /**
     * Update an existing question.
     */
    public function updateQuestion($questionId, $data) {
        $update = [
            'question_text' => $data['question_text'],
            'question_type' => $data['question_type'] ?? 'text',
            'options' => !empty($data['options']) ? (is_array($data['options']) ? json_encode($data['options']) : $data['options']) : null,
            'is_required' => isset($data['is_required']) ? (int)$data['is_required'] : 1,
            'help_text' => $data['help_text'] ?? null,
            'field_name' => !empty($data['field_name']) ? $data['field_name'] : null,
            'depends_on_question_id' => !empty($data['depends_on_question_id']) ? (int)$data['depends_on_question_id'] : null,
            'depends_on_value' => $data['depends_on_value'] ?? null,
        ];
        if (array_key_exists('visible_roles', $data)) {
            $update['visible_roles'] = $data['visible_roles'];
        }
        if (array_key_exists('editable_roles', $data)) {
            $update['editable_roles'] = $data['editable_roles'];
        }
        return $this->db->update('assessment_questions', $update, 'id = :id', [':id' => $questionId]);
    }

    /**
     * Delete a question and clear any conditional references pointing to it.
     */
    public function deleteQuestion($questionId) {
        // Clear depends_on references from other questions pointing to this one
        $this->db->query(
            'UPDATE assessment_questions SET depends_on_question_id = NULL, depends_on_value = NULL WHERE depends_on_question_id = :qid',
            [':qid' => $questionId]
        );
        // Remove any stored answers for this field FIRST. The responses.question_id
        // foreign key is RESTRICT, so the question delete below would otherwise fail
        // whenever a vendor has answered it. Deleting a field intentionally discards
        // the data it holds (the UI warns the user before this runs).
        $this->db->query(
            'DELETE FROM vendor_assessment_responses WHERE question_id = :qid',
            [':qid' => $questionId]
        );
        return $this->db->query('DELETE FROM assessment_questions WHERE id = :id', [':id' => $questionId]);
    }

    /**
     * Reorder questions within a section by an array of question IDs.
     */
    public function reorderQuestions($sectionId, $questionIds) {
        // Two-pass to avoid unique constraint (section_id, sort_order) conflicts:
        // Pass 1: set all to negative offsets (guaranteed unique, no conflicts)
        foreach ($questionIds as $order => $questionId) {
            $this->db->update('assessment_questions', [
                'sort_order' => -($order + 1),
            ], 'id = :id AND section_id = :sid', [':id' => $questionId, ':sid' => $sectionId]);
        }
        // Pass 2: set to final positive values
        foreach ($questionIds as $order => $questionId) {
            $this->db->update('assessment_questions', [
                'sort_order' => $order + 1,
            ], 'id = :id AND section_id = :sid', [':id' => $questionId, ':sid' => $sectionId]);
        }
        return true;
    }

    /**
     * Get the number of active assessments linked to a template.
     */
    public function getAssessmentCountForTemplate($templateId) {
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) as cnt FROM vendor_assessments WHERE template_id = :id',
            [':id' => $templateId]
        );
        return $result['cnt'] ?? 0;
    }

    /**
     * Check if a question's conditional dependency is met given the current responses.
     * Returns true if the question should be visible (no dependency or dependency is met).
     */
    public function isQuestionVisible($question, $responses) {
        if (empty($question['depends_on_question_id'])) {
            return true;
        }
        $parentId = $question['depends_on_question_id'];
        $requiredValue = $question['depends_on_value'] ?? '';

        if (!isset($responses[$parentId]) || empty($responses[$parentId]['response_value'])) {
            return false;
        }

        $parentValue = $responses[$parentId]['response_value'];
        // Handle JSON array values (checkboxes)
        $decoded = json_decode($parentValue, true);
        if (is_array($decoded)) {
            return in_array($requiredValue, $decoded);
        }
        return $parentValue === $requiredValue;
    }

    /**
     * Creates a brand new assessment and generates a UUID token for vendor access.
     *
     * The UUID is the magic key -- vendors get a link with this token and can
     * fill out the assessment without logging in. It's like a one-time password
     * but for questionnaires.
     *
     * Also updates the linked vendor record with contact info if provided,
     * so we're always keeping that data fresh.
     *
     * Returns the new assessment ID, UUID, and expiry date.
     */
    public function createAssessment($templateId, $vendorName, $vendorEmail, $vendorContactName = null, $vendorContactEmail = null, $vendorRequestId = null, $createdBy = null, $expiresInDays = 30, $prefillFromPrior = false) {
        $uuid = $this->generateUUID();
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days"));

        $insertId = $this->db->insert('vendor_assessments', [
            'uuid' => $uuid,
            'template_id' => $templateId,
            'vendor_request_id' => $vendorRequestId,
            'vendor_name' => $vendorName,
            'vendor_email' => $vendorEmail,
            'vendor_contact_name' => $vendorContactName,
            'vendor_contact_email' => $vendorContactEmail,
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'created_by' => $createdBy
        ]);

        // If this assessment is linked to a vendor onboarding request AND
        // we have contact info, update the vendor record too. Belt and suspenders.
        if ($vendorRequestId && ($vendorContactName || $vendorContactEmail)) {
            $vendorUpdate = [];
            if ($vendorContactName) {
                $vendorUpdate['primary_contact_details'] = $vendorContactName;
            }
            if ($vendorContactEmail) {
                $vendorUpdate['primary_contact_email'] = $vendorContactEmail;
            }
            if (!empty($vendorUpdate)) {
                $this->db->update('vendor_onboarding_requests', $vendorUpdate, 'id = :id', [':id' => $vendorRequestId]);
            }
        }

        // Pre-populate this fresh assessment with the answers from the vendor's
        // most recent completed assessment of the same template, so a returning
        // vendor only updates what changed instead of starting from a blank form.
        //
        // This is restricted on TWO axes so a new form can never inherit another
        // vendor's answers (cross-vendor data leak):
        //   1. $prefillFromPrior must be explicitly enabled -- only the
        //      re-assessment flow in vendor-assessments.php sets it.
        //   2. The template must be a recurring vendor assessment, NOT an
        //      'onboarding' template -- a new vendor onboarding always starts blank.
        // Wrapped in a guard -- a pre-fill hiccup must never block creation.
        $prefilledCount = 0;
        if ($prefillFromPrior) {
            $tplRow = $this->db->fetchOne('SELECT category FROM assessment_templates WHERE id = :id', [':id' => $templateId]);
            $isOnboardingTpl = $tplRow && (($tplRow['category'] ?? '') === 'onboarding');
            if (!$isOnboardingTpl) {
                try {
                    $prefilledCount = $this->prefillFromPriorAssessment(
                        $insertId,
                        $templateId,
                        $vendorRequestId,
                        $vendorEmail,
                        $vendorContactEmail
                    );
                } catch (Exception $e) {
                    error_log('Assessment pre-fill from prior assessment failed: ' . $e->getMessage());
                }
            }
        }

        return [
            'id' => $insertId,
            'uuid' => $uuid,
            'expires_at' => $expiresAt,
            'prefilled_count' => $prefilledCount
        ];
    }

    /**
     * Copies answers from a vendor's previous completed assessment into a newly
     * created one so they can edit rather than re-enter everything.
     *
     * Matching is conservative on purpose -- we only ever copy between assessments
     * built from the SAME template, because responses are keyed by the template's
     * question IDs. If the templates differ, the question IDs wouldn't line up and
     * we'd attach answers to the wrong questions.
     *
     * Vendor matching prefers the linked onboarding record (vendor_request_id);
     * when that's missing we fall back to the contact/vendor email address.
     *
     * File-upload answers are intentionally NOT copied -- the vendor should attach
     * current documents (a SOC 2 report from last year may well be expired), and
     * copying the reference would point at the previous assessment's stored file.
     *
     * Returns the number of answers copied (0 if no suitable prior assessment).
     */
    private function prefillFromPriorAssessment($newAssessmentId, $templateId, $vendorRequestId, $vendorEmail, $vendorContactEmail) {
        $priorId = $this->findPriorCompletedAssessmentId(
            $templateId,
            $vendorRequestId,
            $vendorEmail,
            $vendorContactEmail,
            $newAssessmentId
        );
        if (!$priorId) {
            return 0;
        }

        // Questions of type 'file' are skipped -- the vendor re-uploads documents.
        $fileQuestionIds = [];
        $fileRows = $this->db->fetchAll(
            "SELECT q.id
             FROM assessment_questions q
             JOIN assessment_sections s ON q.section_id = s.id
             WHERE s.template_id = :tid AND q.question_type = 'file'",
            [':tid' => $templateId]
        );
        foreach ($fileRows as $row) {
            $fileQuestionIds[$row['id']] = true;
        }

        $responses = $this->db->fetchAll(
            'SELECT question_id, response_value, file_path
             FROM vendor_assessment_responses
             WHERE assessment_id = :sid',
            [':sid' => $priorId]
        );

        $copied = 0;
        foreach ($responses as $r) {
            // Skip file-upload answers (by question type or stored file reference)
            if (isset($fileQuestionIds[$r['question_id']]) || !empty($r['file_path'])) {
                continue;
            }
            // Nothing useful to carry forward for empty answers
            if ($r['response_value'] === null || $r['response_value'] === '') {
                continue;
            }
            $this->db->insert('vendor_assessment_responses', [
                'assessment_id' => $newAssessmentId,
                'question_id' => $r['question_id'],
                'response_value' => $r['response_value']
            ]);
            $copied++;
        }

        return $copied;
    }

    /**
     * Finds the most recent COMPLETED assessment to copy answers from, scoped to
     * the given template. Tries the linked onboarding record first, then falls
     * back to matching on the contact/vendor email address.
     *
     * Returns the prior assessment ID, or null when there's nothing to copy.
     */
    private function findPriorCompletedAssessmentId($templateId, $vendorRequestId, $vendorEmail, $vendorContactEmail, $excludeId) {
        // 1. Match by the linked vendor onboarding request (most reliable)
        if (!empty($vendorRequestId)) {
            $row = $this->db->fetchOne(
                "SELECT id
                 FROM vendor_assessments
                 WHERE template_id = :tid
                   AND vendor_request_id = :vrid
                   AND status = 'completed'
                   AND id != :exclude
                 ORDER BY COALESCE(completed_at, updated_at, created_at) DESC
                 LIMIT 1",
                [':tid' => $templateId, ':vrid' => $vendorRequestId, ':exclude' => $excludeId]
            );
            if ($row) {
                return $row['id'];
            }
        }

        // 2. Fall back to matching on email (contact email or general vendor email)
        $emails = [];
        foreach ([$vendorContactEmail, $vendorEmail] as $email) {
            $email = strtolower(trim((string)$email));
            if ($email !== '' && !in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }
        if (empty($emails)) {
            return null;
        }

        // Native prepared statements (EMULATE_PREPARES => false) don't allow a
        // named placeholder to appear more than once, so give each column its own.
        $params = [':tid' => $templateId, ':exclude' => $excludeId];
        $clauses = [];
        foreach ($emails as $i => $email) {
            $params[":emc{$i}"] = $email;
            $params[":emv{$i}"] = $email;
            $clauses[] = "LOWER(vendor_contact_email) = :emc{$i} OR LOWER(vendor_email) = :emv{$i}";
        }
        $emailWhere = '(' . implode(' OR ', $clauses) . ')';

        $row = $this->db->fetchOne(
            "SELECT id
             FROM vendor_assessments
             WHERE template_id = :tid
               AND status = 'completed'
               AND id != :exclude
               AND {$emailWhere}
             ORDER BY COALESCE(completed_at, updated_at, created_at) DESC
             LIMIT 1",
            $params
        );

        return $row ? $row['id'] : null;
    }

    /**
     * Look up an assessment by its UUID token.
     * This is how vendors access their assessment -- they hit a URL with the token
     * and we find the matching assessment. Joins in the template info so we know
     * what type of assessment it is and whether certificate uploads are allowed.
     */
    public function getAssessmentByUUID($uuid) {
        $assessment = $this->db->fetchOne(
            'SELECT a.*, t.name as template_name, t.slug as template_slug,
                    t.allow_certificate_upload, t.certificate_upload_prompt, t.certificate_upload_mode
             FROM vendor_assessments a
             JOIN assessment_templates t ON a.template_id = t.id
             WHERE a.uuid = :uuid',
            [':uuid' => $uuid]
        );

        return $assessment;
    }

    /**
     * Same as getAssessmentByUUID but uses the internal numeric ID.
     * For internal admin operations where we already know the ID.
     */
    public function getAssessmentById($id) {
        return $this->db->fetchOne(
            'SELECT a.*, t.name as template_name, t.slug as template_slug,
                    t.allow_certificate_upload, t.certificate_upload_prompt, t.certificate_upload_mode
             FROM vendor_assessments a
             JOIN assessment_templates t ON a.template_id = t.id
             WHERE a.id = :id',
            [':id' => $id]
        );
    }

    /**
     * Get all assessments for the admin dashboard view.
     * Joins in a bunch of user info to figure out who the stakeholder is.
     * The COALESCE chain tries: assigned stakeholder -> owner -> original creator.
     * It's like a game of "who's responsible for this?" but in SQL.
     * Supports filtering by status and template_id.
     */
    public function getAllAssessments($filters = []) {
        // This query is a bit of a beast -- it joins through multiple tables to
        // figure out who the stakeholder is for each assessment. The priority is:
        // 1. Explicitly assigned 'stakeholder' role
        // 2. Assigned 'owner' role
        // 3. Whoever created the vendor onboarding request (last resort)
        $sql = "SELECT a.*, t.name as template_name, u.full_name as created_by_name,
                       COALESCE(stakeholder_user.full_name, owner_user.full_name, vor_creator.full_name) as stakeholder_name,
                       COALESCE(stakeholder_user.email, owner_user.email, vor_creator.email) as stakeholder_email,
                       COALESCE(stakeholder_user.username, owner_user.username, vor_creator.username) as stakeholder_username
                FROM vendor_assessments a
                JOIN assessment_templates t ON a.template_id = t.id
                LEFT JOIN users u ON a.created_by = u.id
                LEFT JOIN vendor_onboarding_requests vor ON a.vendor_request_id = vor.id
                LEFT JOIN users vor_creator ON vor.created_by = vor_creator.id
                LEFT JOIN vendor_onboarding_stakeholders vos_stakeholder
                    ON a.vendor_request_id = vos_stakeholder.request_id AND vos_stakeholder.role = 'stakeholder'
                LEFT JOIN users stakeholder_user ON vos_stakeholder.user_id = stakeholder_user.id
                LEFT JOIN vendor_onboarding_stakeholders vos_owner
                    ON a.vendor_request_id = vos_owner.request_id AND vos_owner.role = 'owner'
                LEFT JOIN users owner_user ON vos_owner.user_id = owner_user.id";

        $where = [];
        $params = [];

        // Optional filters -- tack on WHERE clauses as needed
        if (!empty($filters['status'])) {
            $where[] = 'a.status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['template_id'])) {
            $where[] = 'a.template_id = :template_id';
            $params[':template_id'] = $filters['template_id'];
        }

        if (!empty($filters['category'])) {
            $where[] = 't.category = :category';
            $params[':category'] = $filters['category'];
        }

        // SECURITY (BOLA): when a scope_user_id is supplied (set for scoped roles
        // such as stakeholders), restrict the result set to assessments the user
        // created or is assigned to (stakeholder/owner on the vendor request),
        // rather than returning the entire org inventory and its bearer tokens.
        if (!empty($filters['scope_user_id'])) {
            $where[] = '(a.created_by = :scope_uid
                         OR EXISTS (SELECT 1 FROM vendor_onboarding_stakeholders vse
                                    WHERE vse.request_id = a.vendor_request_id
                                      AND vse.user_id = :scope_uid2))';
            $params[':scope_uid'] = (int)$filters['scope_user_id'];
            $params[':scope_uid2'] = (int)$filters['scope_user_id'];
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY a.created_at DESC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Updates the status of an assessment and handles side effects:
     * - 'in_progress': Sets the started_at timestamp (once)
     * - 'completed': Sets the completed_at timestamp AND triggers FAIR analysis
     *
     * The FAIR auto-trigger is the sneaky cool part -- when a vendor finishes
     * their assessment AND they have an SRS score, we auto-create a draft
     * FAIR analysis so the risk team doesn't have to start from scratch.
     */
    public function updateStatus($assessmentId, $status) {
        $updateData = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($status === 'in_progress') {
            // Only set started_at the first time -- don't overwrite if they
            // paused and came back later
            $assessment = $this->getAssessmentById($assessmentId);
            if (!$assessment['started_at']) {
                $updateData['started_at'] = date('Y-m-d H:i:s');
            }
        } elseif ($status === 'completed') {
            $updateData['completed_at'] = date('Y-m-d H:i:s');
        }

        $result = $this->db->update('vendor_assessments', $updateData, 'id = :id', [':id' => $assessmentId]);

        // When an assessment is completed, sync field_name-mapped responses
        // back to the vendor_onboarding_requests record, then check for FAIR analysis
        // and process any assessment triggers.
        if ($status === 'completed') {
            $this->syncAssessmentToVendor($assessmentId);
            $this->triggerFairAnalysisIfNeeded($assessmentId);
            $this->processAssessmentTriggers($assessmentId);
        }

        return $result;
    }

    /**
     * Process workflow rules when an assessment is completed.
     *
     * Queries the assessment_workflow_rules table for active rules matching
     * the completed assessment's template. For each rule, checks if the
     * vendor's response to the specified question matches the condition value.
     * If it matches, auto-creates a new assessment from the target template
     * linked to the same vendor and emails them the link. Duplicate checks
     * prevent the same rule from firing twice for the same assessment.
     */
    private function processAssessmentTriggers($assessmentId) {
        try {
            $assessment = $this->getAssessmentById($assessmentId);
            if (!$assessment) return;

            // Find active workflow rules for this assessment's template
            $rules = $this->db->fetchAll(
                "SELECT r.*, r.assign_to, q.question_type
                 FROM assessment_workflow_rules r
                 JOIN assessment_questions q ON r.question_id = q.id
                 WHERE r.source_template_id = :tid
                   AND r.is_active = 1",
                [':tid' => $assessment['template_id']]
            );

            if (empty($rules)) return;

            $responses = $this->getResponses($assessmentId);

            foreach ($rules as $rule) {
                $qid = (int)$rule['question_id'];
                $targetTemplateId = (int)$rule['target_template_id'];
                $conditionValue = $rule['condition_value'];
                $ruleId = (int)$rule['id'];

                // Check if this question was answered
                if (!isset($responses[$qid]) || empty($responses[$qid]['response_value'])) {
                    continue;
                }

                $responseValue = $responses[$qid]['response_value'];
                $matched = false;

                // For multi-select types, check if condition value is in the JSON array
                if (in_array($rule['question_type'], ['checkbox', 'button_group_multi'])) {
                    $decoded = json_decode($responseValue, true);
                    if (is_array($decoded) && in_array($conditionValue, $decoded)) {
                        $matched = true;
                    }
                } else {
                    // String match for radio/select/button_group/text
                    if ($responseValue === $conditionValue) {
                        $matched = true;
                    }
                }

                if (!$matched) continue;

                // Duplicate check: don't fire the same rule twice for the same assessment
                $existing = $this->db->fetchOne(
                    "SELECT id FROM vendor_assessments
                     WHERE triggered_by_assessment_id = :aid
                       AND triggered_by_rule_id = :rid",
                    [':aid' => $assessmentId, ':rid' => $ruleId]
                );
                if ($existing) continue;

                // Verify target template exists and is active
                $targetTemplate = $this->db->fetchOne(
                    "SELECT id FROM assessment_templates WHERE id = :id AND is_active = 1",
                    [':id' => $targetTemplateId]
                );
                if (!$targetTemplate) continue;

                // Create the triggered assessment
                $uuid = $this->generateUUID();
                $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
                $assignTo = $rule['assign_to'] ?? 'vendor';

                // Resolve the vendor contact. The source assessment's vendor_email can be
                // the internal requester (e.g. the stakeholder who initiated onboarding,
                // since it defaults to the logged-in user's email at creation time). The
                // vendor's actual contact is captured in the onboarding form and synced to
                // vendor_onboarding_requests.primary_contact_email, so prefer that when the
                // assessment is linked to an onboarding request.
                $vendorContactEmail = $assessment['vendor_contact_email'] ?? null;
                $vendorContactName  = $assessment['vendor_contact_name'] ?? null;
                if (!empty($assessment['vendor_request_id'])) {
                    $onboardingContact = $this->db->fetchOne(
                        "SELECT primary_contact_email, primary_contact_details
                         FROM vendor_onboarding_requests WHERE id = ?",
                        [$assessment['vendor_request_id']]
                    );
                    if (!empty($onboardingContact['primary_contact_email'])) {
                        $vendorContactEmail = $onboardingContact['primary_contact_email'];
                        if (!empty($onboardingContact['primary_contact_details'])) {
                            $vendorContactName = $onboardingContact['primary_contact_details'];
                        }
                    }
                }

                $triggeredId = $this->db->insert('vendor_assessments', [
                    'uuid' => $uuid,
                    'template_id' => $targetTemplateId,
                    'vendor_request_id' => $assessment['vendor_request_id'],
                    'vendor_name' => $assessment['vendor_name'],
                    'vendor_email' => $assessment['vendor_email'],
                    'vendor_contact_name' => $vendorContactName,
                    'vendor_contact_email' => $vendorContactEmail,
                    'status' => 'pending',
                    'expires_at' => $expiresAt,
                    'triggered_by_assessment_id' => $assessmentId,
                    'triggered_by_question_id' => $qid,
                    'triggered_by_rule_id' => $ruleId,
                    'created_by' => $assessment['created_by'],
                ]);

                // Carry forward answers from a prior completed assessment of the
                // same (target) template for this vendor, just like manual creation.
                try {
                    $this->prefillFromPriorAssessment(
                        $triggeredId,
                        $targetTemplateId,
                        $assessment['vendor_request_id'],
                        $assessment['vendor_email'],
                        $assessment['vendor_contact_email'] ?? null
                    );
                } catch (Exception $e) {
                    error_log('Triggered assessment pre-fill failed: ' . $e->getMessage());
                }

                error_log("Workflow rule #{$ruleId} triggered assessment (template {$targetTemplateId}) from assessment {$assessmentId}, question {$qid}, value '{$conditionValue}', assign_to '{$assignTo}'");

                // Send email notification based on the rule's assign_to setting
                try {
                    $assessmentUrl = baseUrl('vendor-assessment.php?token=' . $uuid);
                    $recipientEmail = null;
                    $recipientName = null;

                    if ($assignTo === 'stakeholder' && $assessment['vendor_request_id']) {
                        // Look up the assigned stakeholder for this vendor
                        $stakeholder = $this->db->fetchOne(
                            "SELECT u.email, u.full_name FROM vendor_onboarding_stakeholders vos
                             JOIN users u ON vos.user_id = u.id
                             WHERE vos.request_id = :rid AND vos.role = 'stakeholder'
                             LIMIT 1",
                            [':rid' => $assessment['vendor_request_id']]
                        );
                        if ($stakeholder) {
                            $recipientEmail = $stakeholder['email'];
                            $recipientName = $stakeholder['full_name'];
                        }
                    } elseif ($assignTo === 'creator' && $assessment['created_by']) {
                        // Look up the admin who created the original assessment
                        $creator = $this->db->fetchOne(
                            "SELECT email, full_name FROM users WHERE id = :id",
                            [':id' => $assessment['created_by']]
                        );
                        if ($creator) {
                            $recipientEmail = $creator['email'];
                            $recipientName = $creator['full_name'];
                        }
                    }

                    // Default: send to vendor contact (resolved above, preferring the
                    // onboarding request's primary contact over the source assessment's
                    // vendor_email, which may be the internal requester's address).
                    if (empty($recipientEmail)) {
                        $recipientEmail = $vendorContactEmail ?: $assessment['vendor_email'];
                        $recipientName = $vendorContactName;
                    }

                    if (!empty($recipientEmail) && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                        require_once __DIR__ . '/EmailService.php';
                        $encryption = new Encryption();
                        $emailService = new EmailService($this->db, $encryption);

                        if ($emailService->isEnabled()) {
                            $fullName = $recipientName;
                            if (!$fullName && $assessment['vendor_request_id']) {
                                $onboardingRecord = $this->db->fetchOne(
                                    "SELECT primary_contact_details FROM vendor_onboarding_requests WHERE id = ?",
                                    [$assessment['vendor_request_id']]
                                );
                                $fullName = !empty($onboardingRecord['primary_contact_details']) ? $onboardingRecord['primary_contact_details'] : null;
                            }

                            $emailService->sendAssessmentEmail(
                                $recipientEmail,
                                $assessment['vendor_name'],
                                $assessmentUrl,
                                $recipientName,
                                $targetTemplateId,
                                $fullName,
                                $expiresAt
                            );
                        }
                    }
                } catch (Exception $emailEx) {
                    error_log('Workflow rule email failed: ' . $emailEx->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('Failed to process workflow rules: ' . $e->getMessage());
        }
    }

    /**
     * Sync assessment responses back to the linked vendor_onboarding_requests record.
     *
     * For onboarding-category templates, questions can have a field_name that maps
     * directly to a column in vendor_onboarding_requests. When the assessment is
     * completed, we copy each response value into the corresponding vendor field.
     *
     * Values like "Yes"/"No" from radio buttons are normalized to lowercase to match
     * the ENUM expectations in the vendor table.
     */
    private function syncAssessmentToVendor($assessmentId) {
        try {
            $assessment = $this->getAssessmentById($assessmentId);
            if (!$assessment || !$assessment['vendor_request_id']) {
                return;
            }

            // Fetch all responses with their field_name mapping
            $responses = $this->db->fetchAll(
                "SELECT q.field_name, r.response_value
                 FROM vendor_assessment_responses r
                 JOIN assessment_questions q ON r.question_id = q.id
                 WHERE r.assessment_id = :aid AND q.field_name IS NOT NULL AND q.field_name != ''",
                [':aid' => $assessmentId]
            );

            // Always update the assessment's own vendor_name from a vendor_name field response
            foreach ($responses as $row) {
                if ($row['field_name'] === 'vendor_name' && $row['response_value'] !== null && trim($row['response_value']) !== '') {
                    $this->db->update('vendor_assessments', [
                        'vendor_name' => trim($row['response_value'])
                    ], 'id = :id', [':id' => $assessmentId]);
                    break;
                }
            }

            // Sync field_name-mapped responses to vendor_onboarding_requests for
            // onboarding AND vendor_assessment templates. Vendor assessments can also
            // map to onboarding-category fields (e.g. vendor_use_ai) so the linked
            // onboarding request stays in sync as the assessment is filled out.
            $template = $this->db->fetchOne(
                'SELECT category FROM assessment_templates WHERE id = :id',
                [':id' => $assessment['template_id']]
            );
            if (!$template || !in_array($template['category'], ['onboarding', 'vendor_assessment'], true)) {
                return;
            }

            // Allowlist of vendor_onboarding_requests columns that can be written to
            $allowedFields = [
                'vendor_name', 'vendor_domain', 'vendor_sisterdomains', 'vendor_id', 'vendor_type',
                'relationship_manager', 'expected_procurement_date',
                'product_service_description', 'target_user_count',
                'primary_contact_email', 'primary_contact_details',
                'primary_contact_title', 'primary_contact_phone', 'vat_number',
                'nda_in_place', 'vendor_competitors', 'vsu_onboarded',
                'confidential_info_shared', 'confidential_info_justification',
                'cross_border_transfer', 'cross_border_justification',
                'offsite_data_hosting', 'offsite_data_justification',
                'remote_network_access', 'remote_access_justification',
                'source_code_access', 'source_code_justification',
                'critical_business_function', 'critical_function_justification',
                'unauthorized_disclosure_impact', 'unauthorized_disclosure_justification',
                'unauthorized_modification_impact', 'disruption_impact',
                'saml_sso_support', 'is_saas', 'vendor_use_ai',
                'pii_record_count', 'spii_record_count', 'sox_record_count',
                'business_impact', 'additional_information',
                'cost_center', 'project',
            ];

            // Fields that use ENUM('yes','no','') -- need lowercase normalization
            $yesNoFields = [
                'nda_in_place', 'vsu_onboarded',
                'confidential_info_shared', 'cross_border_transfer',
                'offsite_data_hosting', 'remote_network_access',
                'source_code_access', 'critical_business_function', 'is_saas', 'vendor_use_ai',
            ];

            // Fields that use ENUM('yes','no','unknown','')
            $yesNoUnknownFields = ['saml_sso_support'];

            // Fields that use ENUM('low','moderate','high','severe','')
            $impactFields = [
                'unauthorized_disclosure_impact',
                'unauthorized_modification_impact',
                'disruption_impact',
            ];

            // Integer fields
            $intFields = ['pii_record_count', 'spii_record_count', 'sox_record_count', 'cost_center'];

            // Decimal fields
            $decimalFields = ['business_impact'];

            $updateData = [];
            foreach ($responses as $row) {
                $field = $row['field_name'];
                $value = $row['response_value'];

                if (!in_array($field, $allowedFields, true) || $value === null || $value === '') {
                    continue;
                }

                // Normalize values based on field type
                if (in_array($field, $yesNoFields, true)) {
                    $value = strtolower(trim($value));
                    if (!in_array($value, ['yes', 'no'], true)) continue;
                } elseif (in_array($field, $yesNoUnknownFields, true)) {
                    $value = strtolower(trim($value));
                    if (!in_array($value, ['yes', 'no', 'unknown'], true)) continue;
                } elseif (in_array($field, $impactFields, true)) {
                    $value = strtolower(trim($value));
                    if (!in_array($value, ['low', 'moderate', 'high', 'severe'], true)) continue;
                } elseif (in_array($field, $intFields, true)) {
                    $value = intval($value);
                } elseif (in_array($field, $decimalFields, true)) {
                    $value = floatval($value);
                } elseif ($field === 'vendor_domain') {
                    // Store just the domain even if the vendor pasted a full URL
                    $value = normalizeVendorDomain($value);
                    if ($value === '') continue;
                } else {
                    $value = trim($value);
                }

                $updateData[$field] = $value;
            }

            // When the assessment is completed, also transition the onboarding
            // request from draft to submitted so it enters the review workflow.
            $currentRequest = $this->db->fetchOne(
                'SELECT status, vendor_name FROM vendor_onboarding_requests WHERE id = :id',
                [':id' => $assessment['vendor_request_id']]
            );
            // Only onboarding-category assessments should advance the request
            // out of draft. A completed vendor assessment must not flip the
            // workflow status of the underlying onboarding request.
            if ($template['category'] === 'onboarding' && $currentRequest && $currentRequest['status'] === 'draft') {
                $updateData['status'] = 'submitted';
                $updateData['submitted_at'] = date('Y-m-d H:i:s');
            }

            // If no field_name mappings provided a vendor_name, fall back to
            // the assessment's own vendor_name (set during autosave).
            if (empty($updateData['vendor_name']) && !empty($assessment['vendor_name']) && $assessment['vendor_name'] !== '(New Vendor)') {
                if (empty($currentRequest['vendor_name'])) {
                    $updateData['vendor_name'] = $assessment['vendor_name'];
                }
            }

            if (!empty($updateData)) {
                $this->db->update(
                    'vendor_onboarding_requests',
                    $updateData,
                    'id = :id',
                    [':id' => $assessment['vendor_request_id']]
                );
            }
        } catch (Exception $e) {
            error_log('Failed to sync assessment to vendor record: ' . $e->getMessage());
        }
    }

    /**
     * Sync a single question response to the vendor_onboarding_requests record.
     * Called during autosave so edits to completed assessments are immediately
     * reflected on the vendor dashboard and SRS details page.
     */
    public function syncFieldToVendor($assessmentId, $questionId, $value) {
        try {
            $assessment = $this->getAssessmentById($assessmentId);
            if (!$assessment || !$assessment['vendor_request_id']) return;

            // Sync for onboarding AND vendor_assessment templates (see syncAssessmentToVendor)
            $template = $this->db->fetchOne(
                'SELECT category FROM assessment_templates WHERE id = :id',
                [':id' => $assessment['template_id']]
            );
            if (!$template || !in_array($template['category'], ['onboarding', 'vendor_assessment'], true)) return;

            // Get the field_name for this question
            $question = $this->db->fetchOne(
                'SELECT field_name FROM assessment_questions WHERE id = :id',
                [':id' => $questionId]
            );
            if (!$question || empty($question['field_name'])) return;

            $field = $question['field_name'];

            // Same allowlist as syncAssessmentToVendor
            $allowedFields = [
                'vendor_name', 'vendor_domain', 'vendor_sisterdomains', 'vendor_id', 'vendor_type',
                'relationship_manager', 'expected_procurement_date',
                'product_service_description', 'target_user_count',
                'primary_contact_email', 'primary_contact_details',
                'primary_contact_title', 'primary_contact_phone', 'vat_number',
                'nda_in_place', 'vendor_competitors', 'vsu_onboarded',
                'confidential_info_shared', 'confidential_info_justification',
                'cross_border_transfer', 'cross_border_justification',
                'offsite_data_hosting', 'offsite_data_justification',
                'remote_network_access', 'remote_access_justification',
                'source_code_access', 'source_code_justification',
                'critical_business_function', 'critical_function_justification',
                'unauthorized_disclosure_impact', 'unauthorized_disclosure_justification',
                'unauthorized_modification_impact', 'disruption_impact',
                'saml_sso_support', 'is_saas', 'vendor_use_ai',
                'pii_record_count', 'spii_record_count', 'sox_record_count',
                'business_impact', 'additional_information',
                'cost_center', 'project',
            ];
            if (!in_array($field, $allowedFields, true)) return;

            // Normalize value based on field type
            $yesNoFields = ['nda_in_place', 'vsu_onboarded', 'confidential_info_shared', 'cross_border_transfer', 'offsite_data_hosting', 'remote_network_access', 'source_code_access', 'critical_business_function', 'is_saas', 'vendor_use_ai'];
            $yesNoUnknownFields = ['saml_sso_support'];
            $impactFields = ['unauthorized_disclosure_impact', 'unauthorized_modification_impact', 'disruption_impact'];
            $intFields = ['pii_record_count', 'spii_record_count', 'sox_record_count', 'cost_center'];
            $decimalFields = ['business_impact'];

            if ($value === null || $value === '') {
                $normalized = '';
            } elseif (in_array($field, $yesNoFields, true)) {
                $normalized = strtolower(trim($value));
                if (!in_array($normalized, ['yes', 'no'], true)) return;
            } elseif (in_array($field, $yesNoUnknownFields, true)) {
                $normalized = strtolower(trim($value));
                if (!in_array($normalized, ['yes', 'no', 'unknown'], true)) return;
            } elseif (in_array($field, $impactFields, true)) {
                $normalized = strtolower(trim($value));
                if (!in_array($normalized, ['low', 'moderate', 'high', 'severe'], true)) return;
            } elseif (in_array($field, $intFields, true)) {
                $normalized = intval($value);
            } elseif (in_array($field, $decimalFields, true)) {
                $normalized = floatval($value);
            } elseif ($field === 'vendor_domain') {
                // Store just the domain even if the vendor pasted a full URL
                $normalized = normalizeVendorDomain($value);
            } else {
                $normalized = trim($value);
            }

            $this->db->update(
                'vendor_onboarding_requests',
                [$field => $normalized],
                'id = :id',
                [':id' => $assessment['vendor_request_id']]
            );
        } catch (Exception $e) {
            error_log('Failed to sync field to vendor record: ' . $e->getMessage());
        }
    }

    /**
     * The behind-the-scenes magic that decides whether to auto-create a FAIR analysis.
     *
     * Rules:
     * 1. Vendor must have an SRS score (no score = no data to pre-populate)
     * 2. No FAIR analysis for this vendor in the last 30 days (avoid duplicates)
     *
     * If both conditions are met, it creates a draft FAIR analysis pre-populated
     * with the vendor's data. If anything goes wrong, it logs the error but
     * doesn't blow up the assessment completion -- that would be rude.
     */
    private function triggerFairAnalysisIfNeeded($assessmentId) {
        try {
            // Get the assessment with all the vendor onboarding data we might need
            $assessment = $this->db->fetchOne(
                "SELECT va.*, vor.id as onboarding_id, vor.vendor_name, vor.vendor_domain,
                        vor.current_srs_score, vor.current_shodan_score, vor.custom_score,
                        vor.pii_record_count, vor.spii_record_count,
                        vor.sox_record_count, vor.business_impact, vor.product_service_description
                 FROM vendor_assessments va
                 LEFT JOIN vendor_onboarding_requests vor ON va.vendor_request_id = vor.id
                 WHERE va.id = :id",
                [':id' => $assessmentId]
            );

            // No linked vendor onboarding request? Nothing to auto-generate from.
            if (!$assessment || !$assessment['vendor_request_id']) {
                return;
            }

            // No score from any source means we can't meaningfully pre-populate
            if (empty($assessment['current_srs_score']) && empty($assessment['current_shodan_score']) && empty($assessment['custom_score'])) {
                return;
            }

            $vendorName = $assessment['vendor_name'];
            $vendorDomain = $assessment['vendor_domain'];

            if (empty($vendorName)) {
                return; // Need at least a name to create the analysis
            }

            // Check for existing recent FAIR analysis -- no point creating duplicates
            $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
            $recentFair = $this->db->fetchOne(
                "SELECT id FROM tprm_results
                 WHERE vendor_name = :vendor_name
                   AND created_at >= :since
                 ORDER BY created_at DESC
                 LIMIT 1",
                [':vendor_name' => $vendorName, ':since' => $thirtyDaysAgo]
            );

            if ($recentFair) {
                return; // Already got a recent one -- skip it
            }

            // All clear -- create the draft FAIR analysis
            $this->createDraftFairAnalysis($assessment);

        } catch (Exception $e) {
            // Don't let a failed auto-create ruin the assessment completion party
            error_log('Failed to auto-create FAIR analysis: ' . $e->getMessage());
        }
    }

    /**
     * Creates a draft FAIR analysis record pre-populated with vendor data.
     * Pulls in SRS scores, record counts, business impact, and scope of work.
     * Encrypts sensitive fields because that's how tprm_results rolls.
     * Leaves it in "draft" status so a human can review before finalizing.
     */
    private function createDraftFairAnalysis($assessment) {
        // Figure out who to attribute this to -- the assessment creator, or admin as fallback
        $userId = $assessment['created_by'] ?? 1;

        // Compute combined score grade from all available sources
        require_once __DIR__ . '/SRSService.php';
        $srsService = new SRSService();
        $combined = $srsService->getCombinedScore($assessment);
        $securityGrade = $combined['grade'] ?? 'F';

        // Build the FAIR analysis record with everything we know about this vendor
        $fairData = [
            'user_id' => $userId,
            'vendor_name' => $assessment['vendor_name'],
            'vendor_domain' => $assessment['vendor_domain'] ?? null,
            'security_score' => $securityGrade,
            'pii_record_count' => $assessment['pii_record_count'] ?? 0,
            'spii_record_count' => $assessment['spii_record_count'] ?? 0,
            'sox_record_count' => $assessment['sox_record_count'] ?? 0,
            'status' => 'draft',  // Always draft -- let a human finalize it
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Some FAIR fields are encrypted at rest because they contain sensitive biz info
        require_once __DIR__ . '/Encryption.php';
        $encryption = new Encryption();

        if (!empty($assessment['product_service_description'])) {
            $fairData['scope_of_work'] = $encryption->encrypt($assessment['product_service_description']);
        }

        if (!empty($assessment['business_impact'])) {
            $fairData['business_impact'] = $encryption->encrypt((string)$assessment['business_impact']);
        }

        // Leave a breadcrumb so reviewers know this was auto-generated
        $autoNote = "Auto-generated from completed Vendor Assessment (ID: {$assessment['id']}) on " . date('Y-m-d H:i:s') .
                    "\nCombined Score: {$combined['score']}% (Grade: {$securityGrade}, Sources: {$combined['source_count']})";
        $fairData['vendor_risk_assessment'] = $encryption->encrypt($autoNote);

        // Insert the draft and log what we did
        $this->db->insert('tprm_results', $fairData);

        error_log("Auto-created draft FAIR analysis for vendor: {$assessment['vendor_name']} (Assessment ID: {$assessment['id']})");
    }

    /**
     * Updates which section the vendor is currently on.
     * Used by the multi-step form to remember where they left off
     * so they can pick up right where they stopped. Like a bookmark, but for forms.
     */
    public function updateCurrentSection($assessmentId, $sectionId) {
        return $this->db->update('vendor_assessments', [
            'current_section_id' => $sectionId,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $assessmentId]);
    }

    /**
     * Saves a certificate upload (ISO 27001, SOC 2, etc.).
     *
     * In "skip" mode the certificate stands in for the whole questionnaire, so
     * $autoComplete (the default) marks the assessment completed. In "minimal"
     * mode the certificate is necessary but not sufficient — the vendor still has
     * to answer the selected questions — so callers pass $autoComplete = false and
     * the assessment is completed later via the normal submit flow.
     */
    public function saveCertificate($assessmentId, $filePath, $expiryDate = null, $autoComplete = true) {
        $data = [
            'certificate_uploaded' => 1,
            'certificate_path' => $filePath,
            'certificate_expiry' => $expiryDate,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if ($autoComplete) {
            $data['status'] = 'completed';
            $data['completed_at'] = date('Y-m-d H:i:s');
        }
        return $this->db->update('vendor_assessments', $data, 'id = :id', [':id' => $assessmentId]);
    }

    /**
     * Resolve a loaded assessment's effective certificate-upload mode
     * (none|skip|minimal), tolerant of rows created before the mode column existed.
     */
    public function getEffectiveCertificateMode($assessment) {
        return $this->normalizeCertificateMode(
            $assessment['certificate_upload_mode'] ?? null,
            $assessment['allow_certificate_upload'] ?? null
        );
    }

    /**
     * Record the submitter's attestation (name/title/email/phone, client IP and
     * the truthfulness flag) against the assessment. Called at final submission
     * from both the questionnaire submit and the certificate-skip upload. Only
     * whitelisted keys are written; attested_at is stamped when attested.
     */
    public function saveSubmitterAttestation($assessmentId, array $data) {
        $allowed = ['submitter_name', 'submitter_title', 'submitter_email', 'submitter_phone', 'submitter_ip_address', 'submitter_attested'];
        $row = array_intersect_key($data, array_flip($allowed));
        if (empty($row)) {
            return false;
        }
        if (!empty($row['submitter_attested'])) {
            $row['attested_at'] = date('Y-m-d H:i:s');
        }
        $row['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('vendor_assessments', $row, 'id = :id', [':id' => $assessmentId]);
    }

    /**
     * The autosave workhorse -- saves a single answer as the vendor fills out the form.
     * Uses an upsert pattern: if we already have a response for this question, update it;
     * otherwise insert a new one. Supports both text values and array values
     * (multi-select checkboxes get JSON-encoded). Optional file_path for file uploads.
     */
    public function saveResponse($assessmentId, $questionId, $value, $filePath = null) {
        // Check if we already have a response for this question
        $existing = $this->db->fetchOne(
            'SELECT id FROM vendor_assessment_responses WHERE assessment_id = :aid AND question_id = :qid',
            [':aid' => $assessmentId, ':qid' => $questionId]
        );

        if ($existing) {
            // Update existing response
            $result = $this->db->update('vendor_assessment_responses', [
                'response_value' => is_array($value) ? json_encode($value) : $value,
                'file_path' => $filePath,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', [':id' => $existing['id']]);
        } else {
            // Insert new response
            $result = $this->db->insert('vendor_assessment_responses', [
                'assessment_id' => $assessmentId,
                'question_id' => $questionId,
                'response_value' => is_array($value) ? json_encode($value) : $value,
                'file_path' => $filePath
            ]);
        }

        // If this question maps to vendor_name, update the assessment title in real time
        $stringValue = is_array($value) ? '' : trim($value);
        if ($stringValue !== '') {
            $question = $this->db->fetchOne(
                'SELECT field_name FROM assessment_questions WHERE id = :id',
                [':id' => $questionId]
            );
            if ($question && $question['field_name'] === 'vendor_name') {
                $this->db->update('vendor_assessments', [
                    'vendor_name' => $stringValue
                ], 'id = :id', [':id' => $assessmentId]);
            }
        }

        return $result;
    }

    /**
     * Gets all responses for an assessment, keyed by question_id for easy lookup.
     * Returns an associative array where you can just do $responses[$questionId]
     * instead of looping through a flat list. You're welcome.
     */
    public function getResponses($assessmentId) {
        $responses = $this->db->fetchAll(
            'SELECT * FROM vendor_assessment_responses WHERE assessment_id = :id',
            [':id' => $assessmentId]
        );

        // Re-index by question_id for O(1) lookups
        $result = [];
        foreach ($responses as $r) {
            $result[$r['question_id']] = $r;
        }

        return $result;
    }

    /**
     * Set of lower-cased column names on vendor_onboarding_requests. A question
     * whose field_name matches one of these is a BUILT-IN field (already exported
     * and returned via the request row); anything else with a field_name is a NEW
     * custom field. Cached for the request lifetime.
     */
    private function getVendorColumnSet() {
        static $cols = null;
        if ($cols !== null) return $cols;
        $cols = [];
        try {
            foreach ($this->db->fetchAll('SHOW COLUMNS FROM vendor_onboarding_requests') as $r) {
                $name = $r['Field'] ?? ($r['field'] ?? null);
                if ($name) $cols[strtolower($name)] = true;
            }
        } catch (Exception $e) {
            $cols = [];
        }
        return $cols;
    }

    /**
     * Custom onboarding fields for a vendor: questions in the vendor's
     * ONBOARDING-category assessment template(s) whose field_name is defined but
     * is NOT a real vendor_onboarding_requests column (i.e. a NEW custom field).
     * Values come from vendor_assessment_responses. Keyed by field_name; when the
     * same field_name appears in more than one assessment the newest one wins.
     *
     * Each entry: field_name, label, value, type, section, template_id,
     * template_name, assessment_id, question_id.
     *
     * @param int $vendorRequestId vendor_onboarding_requests.id
     * @return array<string,array>
     */
    /**
     * @param int        $vendorRequestId vendor_onboarding_requests.id
     * @param array|null $viewer When provided, role-gates the result so a viewer only
     *                           sees fields whose section AND question allow their role.
     *                           Shape: ['groups' => string[], 'super' => bool]. Null = no
     *                           gating (full data — used by trusted/internal callers).
     */
    public function getCustomOnboardingData($vendorRequestId, $viewer = null) {
        $vendorRequestId = (int)$vendorRequestId;
        if ($vendorRequestId <= 0) return [];

        $known = $this->getVendorColumnSet();
        $vg = $viewer['groups'] ?? [];
        $vs = !empty($viewer['super']);

        $assessments = $this->db->fetchAll(
            "SELECT va.id, va.template_id, t.name AS template_name
             FROM vendor_assessments va
             JOIN assessment_templates t ON va.template_id = t.id
             WHERE va.vendor_request_id = :rid AND t.category = 'onboarding'
             ORDER BY va.created_at DESC, va.id DESC",
            [':rid' => $vendorRequestId]
        );

        $out = [];
        foreach ($assessments as $a) {
            $responses = $this->getResponses($a['id']);
            $maps = $this->getOnboardingCustomMaps($a['template_id']);
            foreach ($this->getSections($a['template_id']) as $sec) {
                // Section-level role gate (only enforced when a viewer is supplied).
                $secCustom = isset($maps['sections'][(int)$sec['id']]);
                $secVisible = ($viewer === null) || self::roleCanSee($sec['visible_roles'] ?? null, $sec['editable_roles'] ?? null, $vg, $vs, $secCustom);
                foreach ($this->getQuestions($sec['id']) as $q) {
                    $fn = trim((string)($q['field_name'] ?? ''));
                    // Skip unmapped questions and built-in (real column) fields.
                    if ($fn === '' || isset($known[strtolower($fn)])) continue;
                    // Newest assessment already supplied this field_name.
                    if (isset($out[$fn])) continue;
                    // Question-level role gate (section AND question must pass). These are
                    // custom fields, so visibility is grant-only. Edit access implies visibility.
                    if ($viewer !== null && (!$secVisible || !self::roleCanSee($q['visible_roles'] ?? null, $q['editable_roles'] ?? null, $vg, $vs, true))) continue;

                    $resp = $responses[$q['id']] ?? null;
                    $val = $resp ? (string)$resp['response_value'] : '';
                    // Multi-select types (checkbox / button_group_multi) persist a JSON
                    // array. Expose both a flat selected-list (for editing) and a
                    // human-readable display string (for the read-only view).
                    $valList = [];
                    if ($val !== '' && in_array($q['question_type'] ?? '', ['checkbox', 'button_group_multi'], true)) {
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $valList = array_values(array_map('strval', $decoded));
                            $val = implode(', ', $valList);
                        }
                    }

                    $out[$fn] = [
                        'field_name'    => $fn,
                        'label'         => $q['question_text'] ?? $fn,
                        'value'         => $val,
                        'type'          => $q['question_type'] ?? 'text',
                        'section'       => $sec['name'] ?? '',
                        'template_id'   => (int)$a['template_id'],
                        'template_name' => $a['template_name'] ?? '',
                        'assessment_id' => (int)$a['id'],
                        'question_id'   => (int)$q['id'],
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Lower-cased set (field_name => true) of onboarding fields the viewer must NOT
     * see — i.e. questions mapped to a field_name (standard OR custom) whose section
     * or question role gate the viewer fails — across the vendor's onboarding
     * template(s). A field visible in ANY of the vendor's onboarding templates is
     * not hidden. Used to redact the Vendor tab, CSV export, and the API vendor row.
     * Super admins are never restricted (returns empty).
     *
     * @param int   $vendorRequestId vendor_onboarding_requests.id
     * @param array $viewerGroups    viewer's ACL group slugs
     * @param bool  $isSuperAdmin    viewer's is_super_admin flag
     * @return array<string,bool>
     */
    public function getHiddenOnboardingFieldNames($vendorRequestId, array $viewerGroups, $isSuperAdmin) {
        $vendorRequestId = (int)$vendorRequestId;
        if ($vendorRequestId <= 0 || $isSuperAdmin) return [];

        $assessments = $this->db->fetchAll(
            "SELECT DISTINCT va.template_id
             FROM vendor_assessments va
             JOIN assessment_templates t ON va.template_id = t.id
             WHERE va.vendor_request_id = :rid AND t.category = 'onboarding'",
            [':rid' => $vendorRequestId]
        );

        $visibleAnywhere = []; // field_name(lc) => bool (visible in at least one template)
        foreach ($assessments as $a) {
            $maps = $this->getOnboardingCustomMaps($a['template_id']);
            foreach ($this->getSections($a['template_id']) as $sec) {
                $secCustom = isset($maps['sections'][(int)$sec['id']]);
                $secVisible = self::roleCanSee($sec['visible_roles'] ?? null, $sec['editable_roles'] ?? null, $viewerGroups, $isSuperAdmin, $secCustom);
                foreach ($this->getQuestions($sec['id']) as $q) {
                    $fn = trim((string)($q['field_name'] ?? ''));
                    if ($fn === '') continue;
                    $fnl = strtolower($fn);
                    $qCustom = isset($maps['questions'][(int)$q['id']]);
                    $visible = $secVisible && self::roleCanSee($q['visible_roles'] ?? null, $q['editable_roles'] ?? null, $viewerGroups, $isSuperAdmin, $qCustom);
                    $visibleAnywhere[$fnl] = ($visibleAnywhere[$fnl] ?? false) || $visible;
                }
            }
        }

        $hidden = [];
        foreach ($visibleAnywhere as $fnl => $vis) {
            if (!$vis) $hidden[$fnl] = true;
        }
        return $hidden;
    }

    /**
     * Distinct custom field_names defined across ALL onboarding-category templates
     * (questions whose field_name is set but is not a real vendor column). Used to
     * build the CSV import template's custom:<field_name> columns so admins know
     * which custom fields they can populate.
     *
     * @return string[]
     */
    public function getOnboardingCustomFieldNames() {
        $known = $this->getVendorColumnSet();
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT q.field_name
             FROM assessment_questions q
             JOIN assessment_sections s ON q.section_id = s.id
             JOIN assessment_templates t ON s.template_id = t.id
             WHERE t.category = 'onboarding'
               AND q.field_name IS NOT NULL AND q.field_name <> ''
             ORDER BY q.field_name"
        );
        $out = [];
        foreach ($rows as $r) {
            $fn = trim((string)$r['field_name']);
            if ($fn !== '' && !isset($known[strtolower($fn)])) $out[] = $fn;
        }
        return $out;
    }

    /**
     * Editable view of a vendor's custom onboarding fields. Unlike
     * getCustomOnboardingData (which is value-driven and only surfaces fields the
     * vendor already has assessment responses for), this is TEMPLATE-driven: it
     * returns every custom field defined on the onboarding-category template(s),
     * with the vendor's current value (empty string when not yet answered or when
     * the vendor has no onboarding assessment at all). This powers the editable
     * Custom Data tab so a field shows up the moment it's added to the template,
     * even for onboarding requests created without an assessment.
     *
     * Role gating mirrors getCustomOnboardingData: when $viewer is supplied, any
     * section/question the viewer cannot see is omitted.
     *
     * @param int        $vendorRequestId vendor_onboarding_requests.id
     * @param array|null $viewer ['groups'=>string[], 'super'=>bool]; null = no gating
     * @return array field_name => [field_name,label,value,type,options,section,template_id,template_name,question_id]
     */
    public function getOnboardingCustomFields($vendorRequestId, $viewer = null) {
        $vendorRequestId = (int)$vendorRequestId;
        if ($vendorRequestId <= 0) return [];

        $known = $this->getVendorColumnSet();
        $vg = $viewer['groups'] ?? [];
        $vs = !empty($viewer['super']);

        // Newest assessment per onboarding template for this vendor (for values).
        $assessments = $this->db->fetchAll(
            "SELECT va.id, va.template_id
             FROM vendor_assessments va
             JOIN assessment_templates t ON va.template_id = t.id
             WHERE va.vendor_request_id = :rid AND t.category = 'onboarding'
             ORDER BY va.created_at DESC, va.id DESC",
            [':rid' => $vendorRequestId]
        );
        $tplAssessment = [];
        foreach ($assessments as $a) {
            if (!isset($tplAssessment[$a['template_id']])) $tplAssessment[$a['template_id']] = $a['id'];
        }

        // Display every onboarding-category template's custom fields, so a newly
        // added field appears even before the vendor has an assessment.
        $tplRows = $this->db->fetchAll(
            "SELECT id, name FROM assessment_templates WHERE category = 'onboarding' ORDER BY id"
        );

        $out = [];
        foreach ($tplRows as $t) {
            $tid = (int)$t['id'];
            $aid = $tplAssessment[$tid] ?? null;
            $responses = $aid ? $this->getResponses($aid) : [];
            $maps = $this->getOnboardingCustomMaps($tid);
            foreach ($this->getSections($tid) as $sec) {
                $secCustom = isset($maps['sections'][(int)$sec['id']]);
                $secVisible = ($viewer === null) || self::roleCanSee($sec['visible_roles'] ?? null, $sec['editable_roles'] ?? null, $vg, $vs, $secCustom);
                // Section-level edit grant (a viewer with section edit can edit all its questions).
                $secEditable = ($viewer === null) ? true : self::roleCanEdit($sec['editable_roles'] ?? null, $vg, $vs);
                foreach ($this->getQuestions($sec['id']) as $q) {
                    $fn = trim((string)($q['field_name'] ?? ''));
                    if ($fn === '' || isset($known[strtolower($fn)])) continue; // skip standard/unmapped
                    if (isset($out[$fn])) continue;                            // first template wins
                    // Custom fields: visibility is grant-only (isCustom=true).
                    if ($viewer !== null && (!$secVisible || !self::roleCanSee($q['visible_roles'] ?? null, $q['editable_roles'] ?? null, $vg, $vs, true))) continue;

                    $resp = $responses[$q['id']] ?? null;
                    $val = $resp ? (string)$resp['response_value'] : '';
                    // Multi-select types (checkbox / button_group_multi) persist a JSON
                    // array. Expose both a flat selected-list (for editing) and a
                    // human-readable display string (for the read-only view).
                    $valList = [];
                    if ($val !== '' && in_array($q['question_type'] ?? '', ['checkbox', 'button_group_multi'], true)) {
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $valList = array_values(array_map('strval', $decoded));
                            $val = implode(', ', $valList);
                        }
                    }

                    // Editable when viewer holds an explicit edit grant at the section OR
                    // question level (grant-only; empty editable_roles = nobody). When no
                    // viewer is supplied (internal/full access) everything is editable.
                    $canEdit = ($viewer === null) || $secEditable || self::roleCanEdit($q['editable_roles'] ?? null, $vg, $vs);

                    $out[$fn] = [
                        'field_name'    => $fn,
                        'label'         => $q['question_text'] ?? $fn,
                        'value'         => $val,
                        'value_list'    => $valList,
                        'type'          => $q['question_type'] ?? 'text',
                        'options'       => $q['options'] ?? null,
                        'section'       => $sec['name'] ?? '',
                        'template_id'   => $tid,
                        'template_name' => $t['name'] ?? '',
                        'question_id'   => (int)$q['id'],
                        'can_edit'      => (bool)$canEdit,
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Return the id of this vendor's onboarding assessment, creating a minimal
     * holder if none exists. Onboarding custom-field answers live in
     * vendor_assessment_responses, which require an assessment row; requests
     * created via the onboarding form/import never get one, so we create it
     * lazily on first save. The holder is status 'completed' (the onboarding
     * request itself owns the workflow) so it never appears as an outstanding
     * task. Returns 0 if no onboarding template exists.
     *
     * @param int      $vendorRequestId vendor_onboarding_requests.id
     * @param int|null $createdBy        user id to attribute the row to
     * @param int|null $templateId       specific onboarding template (defaults to the first)
     * @return int assessment id, or 0
     */
    public function ensureOnboardingAssessment($vendorRequestId, $createdBy = null, $templateId = null) {
        $vendorRequestId = (int)$vendorRequestId;
        if ($vendorRequestId <= 0) return 0;

        $sql = "SELECT va.id
                FROM vendor_assessments va
                JOIN assessment_templates t ON va.template_id = t.id
                WHERE va.vendor_request_id = :rid AND t.category = 'onboarding'";
        $params = [':rid' => $vendorRequestId];
        if ($templateId) { $sql .= " AND va.template_id = :tid"; $params[':tid'] = (int)$templateId; }
        $sql .= " ORDER BY va.created_at DESC, va.id DESC LIMIT 1";
        $existing = $this->db->fetchOne($sql, $params);
        if ($existing) return (int)$existing['id'];

        if (!$templateId) {
            $t = $this->db->fetchOne("SELECT id FROM assessment_templates WHERE category = 'onboarding' ORDER BY id LIMIT 1");
            if (!$t) return 0;
            $templateId = (int)$t['id'];
        }

        $req = $this->db->fetchOne(
            'SELECT vendor_name, primary_contact_email FROM vendor_onboarding_requests WHERE id = :id',
            [':id' => $vendorRequestId]
        );
        $vendorName  = ($req && $req['vendor_name'] !== null && $req['vendor_name'] !== '') ? $req['vendor_name'] : ('Vendor #' . $vendorRequestId);
        $vendorEmail = ($req && !empty($req['primary_contact_email'])) ? $req['primary_contact_email'] : '';

        return (int)$this->db->insert('vendor_assessments', [
            'uuid'              => $this->generateUUID(),
            'template_id'       => $templateId,
            'vendor_request_id' => $vendorRequestId,
            'vendor_name'       => $vendorName,
            'vendor_email'      => $vendorEmail,
            'status'            => 'completed',
            'created_by'        => $createdBy,
        ]);
    }

    /**
     * Write a single custom-field value for a vendor by upserting the response on
     * the matching question of the vendor's existing ONBOARDING assessment. Used by
     * the CSV importer. Does NOT create assessments — "update existing only": if the
     * vendor has no onboarding assessment containing a question with this field_name,
     * nothing is written and false is returned.
     *
     * @return bool true if a response row was inserted/updated, false if no target existed
     */
    public function setCustomOnboardingValue($vendorRequestId, $fieldName, $value) {
        $vendorRequestId = (int)$vendorRequestId;
        $fieldName = trim((string)$fieldName);
        if ($vendorRequestId <= 0 || $fieldName === '') return false;

        // Never let a built-in column masquerade as a custom field here.
        if (isset($this->getVendorColumnSet()[strtolower($fieldName)])) return false;

        $assessments = $this->db->fetchAll(
            "SELECT va.id, va.template_id
             FROM vendor_assessments va
             JOIN assessment_templates t ON va.template_id = t.id
             WHERE va.vendor_request_id = :rid AND t.category = 'onboarding'
             ORDER BY va.created_at DESC, va.id DESC",
            [':rid' => $vendorRequestId]
        );

        foreach ($assessments as $a) {
            $q = $this->db->fetchOne(
                "SELECT q.id
                 FROM assessment_questions q
                 JOIN assessment_sections s ON q.section_id = s.id
                 WHERE s.template_id = :tid AND q.field_name = :fn
                 ORDER BY s.sort_order, q.sort_order
                 LIMIT 1",
                [':tid' => $a['template_id'], ':fn' => $fieldName]
            );
            if (!$q) continue;

            // Upsert keyed by the (assessment_id, question_id) unique constraint.
            $this->db->query(
                "INSERT INTO vendor_assessment_responses (assessment_id, question_id, response_value)
                 VALUES (:aid, :qid, :val)
                 ON DUPLICATE KEY UPDATE response_value = VALUES(response_value), updated_at = current_timestamp()",
                [':aid' => $a['id'], ':qid' => $q['id'], ':val' => (string)$value]
            );
            return true;
        }
        return false;
    }

    /**
     * Gets a single response for a specific question. For when you just need
     * one answer and don't want to load the whole shebang.
     */
    public function getResponse($assessmentId, $questionId) {
        return $this->db->fetchOne(
            'SELECT * FROM vendor_assessment_responses WHERE assessment_id = :aid AND question_id = :qid',
            [':aid' => $assessmentId, ':qid' => $questionId]
        );
    }

    /**
     * Calculates how far along an assessment is as a percentage.
     *
     * Special case: if a certificate was uploaded, it's 100% done by definition.
     * Otherwise, we count how many required questions have been answered and
     * do the math. Also breaks it down per-section so you can show
     * "Section 3 is done but Section 5 needs work" in the UI.
     */
    public function getCompletionStatus($assessmentId) {
        $assessment = $this->getAssessmentById($assessmentId);
        if (!$assessment) return null;

        // Not opened since it was created: the vendor hasn't worked on it yet, so it
        // reports 0% regardless of any answers carried over by the re-assessment
        // pre-fill. Pre-filled drafts are a convenience for the vendor to edit -- they
        // are NOT vendor-confirmed progress, and must not make a never-touched
        // assessment look complete in the admin list. The vendor-facing page flips the
        // status to 'in_progress' before it ever calls this, so this only affects the
        // admin/reporting views of an untouched assessment.
        if (($assessment['status'] ?? '') === 'pending') {
            return [
                'total_questions' => 0,
                'answered_questions' => 0,
                'percentage' => 0,
                'sections' => [],
                'attestation_required' => true,
                'attestation_complete' => false,
            ];
        }

        $mode = $this->getEffectiveCertificateMode($assessment);

        // Minimal mode: the certificate is necessary but NOT sufficient -- the vendor
        // must also answer the selected subset of questions. Handled separately so we
        // never short-circuit to 100% on the certificate alone.
        if ($mode === 'minimal') {
            return $this->getMinimalCompletionStatus($assessment);
        }

        // Skip mode (and legacy): certificate uploaded = instant 100%. Get-out-of-jail-free.
        if ($assessment['certificate_uploaded']) {
            return $this->withAttestationRequirement($assessment, [
                'total_questions' => 0,
                'answered_questions' => 0,
                'percentage' => 100,
                'sections' => []
            ]);
        }

        $sections = $this->getSections($assessment['template_id']);
        $responses = $this->getResponses($assessmentId);

        $sectionStatus = [];
        $totalRequired = 0;
        $totalAnswered = 0;

        // Walk through each section and count required vs. answered questions.
        // Questions with unmet conditional dependencies are excluded from the count.
        foreach ($sections as $section) {
            $questions = $this->getQuestions($section['id']);
            $sectionRequired = 0;
            $sectionAnswered = 0;

            foreach ($questions as $q) {
                if ($q['is_required']) {
                    // Skip conditional questions whose dependency is not met
                    if (!$this->isQuestionVisible($q, $responses)) {
                        continue;
                    }

                    $sectionRequired++;
                    $totalRequired++;

                    // A question counts as "answered" if we have a non-empty response
                    if (isset($responses[$q['id']]) && !empty($responses[$q['id']]['response_value'])) {
                        $sectionAnswered++;
                        $totalAnswered++;
                    }
                }
            }

            $sectionStatus[$section['id']] = [
                'name' => $section['name'],
                'required' => $sectionRequired,
                'answered' => $sectionAnswered,
                'complete' => $sectionRequired === $sectionAnswered
            ];
        }

        return $this->withAttestationRequirement($assessment, [
            'total_questions' => $totalRequired,
            'answered_questions' => $totalAnswered,
            'percentage' => $totalRequired > 0 ? round(($totalAnswered / $totalRequired) * 100) : 0,
            'sections' => $sectionStatus
        ]);
    }

    /**
     * Folds the submitter attestation into a completion result as one extra
     * required item, so an assessment can never report 100% until the submitter
     * has attested (which the submit/certificate flows capture at final submission).
     * A row already marked 'completed' counts as attested, so historical completed
     * assessments still read 100% even if their attestation flag predates this rule.
     */
    private function withAttestationRequirement($assessment, array $result) {
        $attested = !empty($assessment['submitter_attested'])
            || ($assessment['status'] ?? '') === 'completed';

        $total = ($result['total_questions'] ?? 0) + 1;          // +1 for the attestation step
        $answered = ($result['answered_questions'] ?? 0) + ($attested ? 1 : 0);

        $result['total_questions'] = $total;
        $result['answered_questions'] = $answered;
        $result['percentage'] = $total > 0 ? (int)round(($answered / $total) * 100) : 0;
        $result['attestation_required'] = true;
        $result['attestation_complete'] = $attested;

        return $result;
    }

    /**
     * Completion status for a "minimal" certificate-mode assessment.
     *
     * Only questions flagged include_in_minimal count toward completion (conditional
     * visibility still applies), and the certificate upload itself counts as one
     * additional required item. Sections with no minimal questions are omitted so the
     * vendor only ever sees the relevant steps. Extra keys (minimal_mode /
     * certificate_required / certificate_uploaded) let the submit endpoint and the
     * vendor UI gate on the certificate as well as the questions.
     */
    private function getMinimalCompletionStatus($assessment) {
        $sections = $this->getSections($assessment['template_id']);
        $responses = $this->getResponses($assessment['id']);

        $sectionStatus = [];
        $totalRequired = 0;
        $totalAnswered = 0;

        foreach ($sections as $section) {
            $questions = $this->getQuestions($section['id']);
            $sectionRequired = 0;
            $sectionAnswered = 0;

            foreach ($questions as $q) {
                // Only the selected minimal questions count.
                if (empty($q['include_in_minimal'])) {
                    continue;
                }
                // Respect conditional visibility, same as the full flow.
                if (!$this->isQuestionVisible($q, $responses)) {
                    continue;
                }

                $sectionRequired++;
                $totalRequired++;
                if (isset($responses[$q['id']]) && !empty($responses[$q['id']]['response_value'])) {
                    $sectionAnswered++;
                    $totalAnswered++;
                }
            }

            // Only surface sections that actually carry minimal questions.
            if ($sectionRequired > 0) {
                $sectionStatus[$section['id']] = [
                    'name' => $section['name'],
                    'required' => $sectionRequired,
                    'answered' => $sectionAnswered,
                    'complete' => $sectionRequired === $sectionAnswered
                ];
            }
        }

        // The certificate is a required item in minimal mode.
        $certUploaded = !empty($assessment['certificate_uploaded']);
        $totalRequired++;
        if ($certUploaded) {
            $totalAnswered++;
        }

        return $this->withAttestationRequirement($assessment, [
            'total_questions' => $totalRequired,
            'answered_questions' => $totalAnswered,
            'percentage' => $totalRequired > 0 ? round(($totalAnswered / $totalRequired) * 100) : 0,
            'sections' => $sectionStatus,
            'minimal_mode' => true,
            'certificate_required' => true,
            'certificate_uploaded' => $certUploaded,
        ]);
    }

    /**
     * Checks if an assessment has passed its expiry date.
     * No expires_at = never expires (lucky vendor).
     */
    public function isExpired($assessment) {
        if (!$assessment['expires_at']) return false;
        return strtotime($assessment['expires_at']) < time();
    }

    /**
     * Determines if a vendor can still access/fill out this assessment.
     * Three strikes and you're out:
     * - Assessment doesn't exist? Nope.
     * - Expired? Sorry, too late.
     * - Already completed? The ship has sailed.
     */
    public function canAccess($assessment) {
        if (!$assessment) return false;
        if ($this->isExpired($assessment)) return false;
        // Allow access to completed assessments for re-editing via edit=1 flow
        return true;
    }

    /**
     * Generates a proper UUID v4 (random) for assessment tokens.
     * Uses random_bytes() for cryptographic randomness, then manually sets
     * the version (4) and variant bits per RFC 4122. It's like making a
     * snowflake, but with hexadecimal and hyphens.
     */
    private function generateUUID() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);  // Set version to 0100 (UUID v4)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);  // Set variant to 10xx (RFC 4122)
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Nukes an assessment and all its responses from orbit.
     * Deletes responses first (foreign key constraint), then the assessment itself.
     * There's no undo here -- make sure you mean it.
     */
    public function deleteAssessment($assessmentId) {
        // Responses must go first or the FK constraint will slap us
        $this->db->delete('vendor_assessment_responses', 'assessment_id = :id', [':id' => $assessmentId]);
        // Now the assessment itself
        return $this->db->delete('vendor_assessments', 'id = :id', [':id' => $assessmentId]);
    }

    /**
     * Gives an assessment a second chance -- resets it to "pending" and extends
     * the expiry date. Useful when a vendor missed the deadline and needs
     * another 30 days. The assessment data and responses are preserved.
     */
    public function resendAssessment($assessmentId, $expiresInDays = 30) {
        return $this->db->update('vendor_assessments', [
            'status' => 'pending',
            'expires_at' => date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days")),
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $assessmentId]);
    }
}
