<?php
/**
 * GRC Unified Compliance Engine - Core Service
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The beating heart of the GRC module. Handles frameworks, internal controls,
 * requirement mappings, evidence, crosswalking between frameworks, and
 * compliance analytics. Think of it as Vanta + Drata + Apptega having a baby
 * that actually understands your database schema.
 *
 * The key architectural insight: a single Internal Control can map to multiple
 * Framework Requirements. Complete an audit for SOC 2 and those same controls
 * carry over when you start ISO 27001. No re-entering data, no duplicate work.
 */

class GRCService {
    private static $instance = null;
    private $db;
    private $encryption;

    private function __construct() {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================================
    // FRAMEWORKS
    // =========================================================================

    public function getFrameworks(bool $activeOnly = true): array {
        $sql = 'SELECT * FROM grc_frameworks';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, name';
        return $this->db->fetchAll($sql);
    }

    public function getFramework(int $id): ?array {
        return $this->db->fetchOne(
            'SELECT * FROM grc_frameworks WHERE id = :id',
            [':id' => $id]
        ) ?: null;
    }

    public function getFrameworkByCode(string $code): ?array {
        return $this->db->fetchOne(
            'SELECT * FROM grc_frameworks WHERE code = :code',
            [':code' => $code]
        ) ?: null;
    }

    public function deleteFramework(int $id): array {
        $fw = $this->getFramework($id);
        if (!$fw) return ['success' => false, 'error' => 'Framework not found.'];

        // Prevent deletion of catalog-based frameworks
        if (!empty($fw['generated_from'])) {
            return ['success' => false, 'error' => 'Template-based frameworks cannot be deleted. Use "Re-populate" to reset requirements.'];
        }
        // Also check if code matches a known catalog
        $catalogDir = __DIR__ . '/../data/catalog';
        foreach (glob($catalogDir . '/*.php') as $f) {
            $d = include $f;
            if (is_array($d) && ($d['code'] ?? '') === $fw['code']) {
                return ['success' => false, 'error' => 'This framework matches a catalog template. Use "Re-populate" instead of delete.'];
            }
        }

        // Delete in order: control mappings -> requirements -> snapshots -> framework
        $this->db->query(
            'DELETE cm FROM grc_control_requirement_map cm
             INNER JOIN grc_framework_requirements r ON cm.requirement_id = r.id
             WHERE r.framework_id = :fid',
            [':fid' => $id]
        );
        $this->db->query('DELETE FROM grc_framework_requirements WHERE framework_id = :fid', [':fid' => $id]);
        try { $this->db->query('DELETE FROM grc_compliance_snapshots WHERE framework_id = :fid', [':fid' => $id]); } catch (\Exception $e) {}
        $this->db->query('DELETE FROM grc_frameworks WHERE id = :id', [':id' => $id]);
        return ['success' => true, 'name' => $fw['name']];
    }

    public function regenerateFramework(int $id): array {
        $fw = $this->getFramework($id);
        if (!$fw) return ['success' => false, 'error' => 'Framework not found.'];

        // Try generated_from first, then fall back to framework code for catalog match
        $catalogCode = !empty($fw['generated_from']) ? $fw['generated_from'] : $fw['code'];
        if (empty($catalogCode)) return ['success' => false, 'error' => 'No catalog template associated with this framework.'];

        // Find catalog file by code
        $catalogDir = __DIR__ . '/../data/catalog';
        $data = null;
        foreach (glob($catalogDir . '/*.php') as $f) {
            $d = include $f;
            if (is_array($d) && ($d['code'] ?? '') === $catalogCode) {
                $data = $d;
                break;
            }
        }
        if (!$data || empty($data['requirements'])) return ['success' => false, 'error' => 'Catalog template not found for code: ' . $catalogCode];

        try {
            $this->db->beginTransaction();

            // Delete existing requirements and mappings
            $this->db->query(
                'DELETE cm FROM grc_control_requirement_map cm
                 INNER JOIN grc_framework_requirements r ON cm.requirement_id = r.id
                 WHERE r.framework_id = :fid',
                [':fid' => $id]
            );
            $this->db->query('DELETE FROM grc_framework_requirements WHERE framework_id = :fid', [':fid' => $id]);

            // Re-insert from catalog (same logic as generateFromCatalog)
            $count = 0;
            foreach ($data['requirements'] as $req) {
                $this->db->insert('grc_framework_requirements', [
                    'framework_id'    => $id,
                    'requirement_ref' => $req['ref'],
                    'title'           => $req['title'],
                    'description'     => $req['description'] ?? null,
                    'guidance'        => $req['guidance'] ?? null,
                    'maturity_level'  => $req['maturity_level'] ?? null,
                    'is_required'     => $req['is_required'] ?? 1,
                    'sort_order'      => $req['sort_order'] ?? $count,
                ]);
                $count++;
            }

            // Ensure generated_from is set for future lookups
            if (empty($fw['generated_from'])) {
                $this->db->update('grc_frameworks', ['generated_from' => $catalogCode], 'id = :id', [':id' => $id]);
            }

            $this->db->commit();
            return ['success' => true, 'count' => $count];
        } catch (\Exception $e) {
            $this->db->rollback();
            error_log('GRC framework regeneration failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // FRAMEWORK REQUIREMENTS
    // =========================================================================

    public function getRequirements(int $frameworkId, ?int $parentId = null): array {
        if ($parentId === null) {
            return $this->db->fetchAll(
                'SELECT * FROM grc_framework_requirements
                 WHERE framework_id = :fid AND parent_id IS NULL
                 ORDER BY sort_order, requirement_ref',
                [':fid' => $frameworkId]
            );
        }
        return $this->db->fetchAll(
            'SELECT * FROM grc_framework_requirements
             WHERE framework_id = :fid AND parent_id = :pid
             ORDER BY sort_order, requirement_ref',
            [':fid' => $frameworkId, ':pid' => $parentId]
        );
    }

    public function getAllRequirements(int $frameworkId): array {
        return $this->db->fetchAll(
            'SELECT fr.*,
                    COUNT(DISTINCT crm.control_id) as mapped_controls,
                    GROUP_CONCAT(DISTINCT ic.control_ref ORDER BY ic.control_ref SEPARATOR ", ") as control_refs
             FROM grc_framework_requirements fr
             LEFT JOIN grc_control_requirement_map crm ON crm.requirement_id = fr.id
             LEFT JOIN grc_internal_controls ic ON ic.id = crm.control_id AND ic.is_active = 1
             WHERE fr.framework_id = :fid
             GROUP BY fr.id
             ORDER BY fr.sort_order, fr.requirement_ref',
            [':fid' => $frameworkId]
        );
    }

    public function getRequirement(int $id): ?array {
        return $this->db->fetchOne(
            'SELECT fr.*, f.code as framework_code, f.name as framework_name
             FROM grc_framework_requirements fr
             JOIN grc_frameworks f ON f.id = fr.framework_id
             WHERE fr.id = :id',
            [':id' => $id]
        ) ?: null;
    }

    // =========================================================================
    // INTERNAL CONTROLS
    // =========================================================================

    public function getControls(array $filters = []): array {
        $sql = 'SELECT ic.*,
                       u.full_name as owner_name,
                       COUNT(DISTINCT crm.requirement_id) as mapped_requirements,
                       COUNT(DISTINCT ecm.evidence_id) as evidence_count,
                       GROUP_CONCAT(DISTINCT f.code ORDER BY f.code SEPARATOR ", ") as frameworks
                FROM grc_internal_controls ic
                LEFT JOIN users u ON u.id = ic.owner_user_id
                LEFT JOIN grc_control_requirement_map crm ON crm.control_id = ic.id
                LEFT JOIN grc_framework_requirements fr ON fr.id = crm.requirement_id
                LEFT JOIN grc_frameworks f ON f.id = fr.framework_id
                LEFT JOIN grc_evidence_control_map ecm ON ecm.control_id = ic.id';

        $where = ['ic.is_active = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'ic.implementation_status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['framework_id'])) {
            $where[] = 'fr.framework_id = :fid';
            $params[':fid'] = $filters['framework_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(ic.control_ref LIKE :search OR ic.title LIKE :search2)';
            $params[':search'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['type'])) {
            $where[] = 'ic.control_type = :type';
            $params[':type'] = $filters['type'];
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' GROUP BY ic.id ORDER BY ic.control_ref';

        return $this->db->fetchAll($sql, $params);
    }

    public function getControl(int $id): ?array {
        $control = $this->db->fetchOne(
            'SELECT ic.*, u.full_name as owner_name
             FROM grc_internal_controls ic
             LEFT JOIN users u ON u.id = ic.owner_user_id
             WHERE ic.id = :id',
            [':id' => $id]
        );
        if (!$control) return null;

        // Get mapped requirements with framework info
        $control['requirements'] = $this->db->fetchAll(
            'SELECT crm.*, fr.requirement_ref, fr.title as requirement_title,
                    f.code as framework_code, f.name as framework_name, crm.coverage
             FROM grc_control_requirement_map crm
             JOIN grc_framework_requirements fr ON fr.id = crm.requirement_id
             JOIN grc_frameworks f ON f.id = fr.framework_id
             WHERE crm.control_id = :cid
             ORDER BY f.code, fr.requirement_ref',
            [':cid' => $id]
        );

        // Get linked evidence
        $control['evidence'] = $this->db->fetchAll(
            'SELECT e.id, e.evidence_ref, e.title, e.evidence_type, e.status,
                    e.collected_at, e.valid_until,
                    (e.valid_until IS NOT NULL AND e.valid_until < NOW()) AS is_expired
             FROM grc_evidence e
             JOIN grc_evidence_control_map ecm ON ecm.evidence_id = e.id
             WHERE ecm.control_id = :cid
             ORDER BY e.collected_at DESC',
            [':cid' => $id]
        );

        // Get implementation details per framework
        $control['implementations'] = $this->db->fetchAll(
            'SELECT ci.*, f.code as framework_code, f.name as framework_name,
                    f.has_maturity_levels, f.max_maturity_level
             FROM grc_control_implementations ci
             JOIN grc_frameworks f ON f.id = ci.framework_id
             WHERE ci.control_id = :cid
             ORDER BY f.sort_order',
            [':cid' => $id]
        );

        return $control;
    }

    public function createControl(array $data, int $userId): int {
        $ref = $this->generateNextRef('IC', 'grc_internal_controls', 'control_ref');
        $this->db->insert('grc_internal_controls', [
            'control_ref' => $ref,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'control_type' => $data['control_type'] ?? 'preventive',
            'control_category' => $data['control_category'] ?? 'technical',
            'implementation_status' => $data['implementation_status'] ?? 'planned',
            'owner_user_id' => $data['owner_user_id'] ?? null,
            'frequency' => $data['frequency'] ?? 'ad_hoc',
            'risk_level' => $data['risk_level'] ?? 'medium',
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateControl(int $id, array $data): bool {
        $allowed = [
            'title', 'description', 'control_type', 'control_category',
            'implementation_status', 'effectiveness', 'owner_user_id',
            'frequency', 'risk_level', 'notes', 'last_tested_at', 'next_test_due',
        ];
        $update = array_intersect_key($data, array_flip($allowed));
        if (empty($update)) return false;

        $this->db->update('grc_internal_controls', $update, 'id = :id', [':id' => $id]);
        return true;
    }

    // =========================================================================
    // CONTROL-REQUIREMENT MAPPING
    // =========================================================================

    public function mapControlToRequirement(int $controlId, int $requirementId, array $data = [], ?int $userId = null): bool {
        $existing = $this->db->fetchOne(
            'SELECT id FROM grc_control_requirement_map WHERE control_id = :cid AND requirement_id = :rid',
            [':cid' => $controlId, ':rid' => $requirementId]
        );
        if ($existing) return false;

        $this->db->insert('grc_control_requirement_map', [
            'control_id' => $controlId,
            'requirement_id' => $requirementId,
            'coverage' => $data['coverage'] ?? 'full',
            'mapping_notes' => $data['mapping_notes'] ?? null,
            'mapped_by' => $userId,
        ]);
        return true;
    }

    public function unmapControlFromRequirement(int $controlId, int $requirementId): bool {
        $this->db->delete('grc_control_requirement_map',
            'control_id = :cid AND requirement_id = :rid',
            [':cid' => $controlId, ':rid' => $requirementId]
        );
        return true;
    }

    // =========================================================================
    // CROSSWALK - The "Apptega" Feature
    // =========================================================================

    /**
     * Find all requirements in a target framework that share controls with
     * a given source framework. This is the magic of crosswalking:
     * complete SOC 2, and you already have partial coverage for ISO 27001.
     */
    public function getCrosswalk(int $sourceFrameworkId, int $targetFrameworkId): array {
        return $this->db->fetchAll(
            'SELECT
                sfr.requirement_ref AS source_ref,
                sfr.title AS source_title,
                tfr.requirement_ref AS target_ref,
                tfr.title AS target_title,
                ic.control_ref,
                ic.title AS control_title,
                ic.implementation_status,
                scrm.coverage AS source_coverage,
                tcrm.coverage AS target_coverage
             FROM grc_control_requirement_map scrm
             JOIN grc_framework_requirements sfr ON sfr.id = scrm.requirement_id
             JOIN grc_internal_controls ic ON ic.id = scrm.control_id AND ic.is_active = 1
             JOIN grc_control_requirement_map tcrm ON tcrm.control_id = ic.id
             JOIN grc_framework_requirements tfr ON tfr.id = tcrm.requirement_id
             WHERE sfr.framework_id = :sfid
               AND tfr.framework_id = :tfid
             ORDER BY sfr.requirement_ref, tfr.requirement_ref',
            [':sfid' => $sourceFrameworkId, ':tfid' => $targetFrameworkId]
        );
    }

    /**
     * Get compliance status for a framework considering controls shared
     * from other frameworks that are already implemented.
     */
    public function getFrameworkComplianceStatus(int $frameworkId): array {
        $framework = $this->getFramework($frameworkId);
        if (!$framework) return [];

        $requirements = $this->getAllRequirements($frameworkId);
        $total = count($requirements);
        $implemented = 0;
        $partial = 0;
        $planned = 0;
        $inProgress = 0;
        $notApplicable = 0;
        $noControl = 0;

        foreach ($requirements as $req) {
            if ((int)$req['mapped_controls'] === 0) {
                $noControl++;
                continue;
            }
            // Check the best implementation status across all mapped controls
            $bestStatus = $this->db->fetchOne(
                'SELECT ic.implementation_status, crm.coverage
                 FROM grc_control_requirement_map crm
                 JOIN grc_internal_controls ic ON ic.id = crm.control_id AND ic.is_active = 1
                 WHERE crm.requirement_id = :rid
                 ORDER BY FIELD(ic.implementation_status, "implemented", "in_progress", "planned", "not_applicable") ASC
                 LIMIT 1',
                [':rid' => $req['id']]
            );

            if (!$bestStatus) {
                $noControl++;
            } elseif ($bestStatus['implementation_status'] === 'implemented') {
                if ($bestStatus['coverage'] === 'full') $implemented++;
                else $partial++;
            } elseif ($bestStatus['implementation_status'] === 'in_progress') {
                $inProgress++;
            } elseif ($bestStatus['implementation_status'] === 'planned') {
                $planned++;
            } elseif ($bestStatus['implementation_status'] === 'not_applicable') {
                $notApplicable++;
            }
        }

        $applicable = $total - $notApplicable;
        $percentage = $applicable > 0 ? round(($implemented / $applicable) * 100, 2) : 0;

        return [
            'framework' => $framework,
            'total_requirements' => $total,
            'implemented' => $implemented,
            'partial' => $partial,
            'in_progress' => $inProgress,
            'planned' => $planned,
            'not_applicable' => $notApplicable,
            'no_control' => $noControl,
            'compliance_percentage' => $percentage,
        ];
    }

    // =========================================================================
    // COMPLIANCE DASHBOARD ANALYTICS
    // =========================================================================

    public function getDashboardStats(): array {
        $stats = [];

        // Framework compliance overview
        $stats['frameworks'] = $this->db->fetchAll(
            'SELECT * FROM view_grc_compliance_status ORDER BY framework_code'
        );

        // Controls summary
        $stats['controls_total'] = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_internal_controls WHERE is_active = 1'
        )['c'] ?? 0);

        $stats['controls_by_status'] = $this->db->fetchAll(
            'SELECT implementation_status, COUNT(*) as count
             FROM grc_internal_controls WHERE is_active = 1
             GROUP BY implementation_status'
        );

        // Evidence stats
        $stats['evidence_current'] = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_evidence WHERE status = "current"'
        )['c'] ?? 0);

        $stats['evidence_expiring_30d'] = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_evidence
             WHERE status = "current" AND valid_until IS NOT NULL
               AND valid_until BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)'
        )['c'] ?? 0);

        $stats['evidence_expired'] = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_evidence
             WHERE status = "current" AND valid_until IS NOT NULL AND valid_until < NOW()'
        )['c'] ?? 0);

        // Open audit findings
        $stats['open_findings'] = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_audit_findings WHERE status IN ("open", "in_remediation")'
        )['c'] ?? 0);

        // Policies needing review
        $stats['policies_due_review'] = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_policies
             WHERE is_active = 1 AND next_review_date IS NOT NULL AND next_review_date <= CURDATE()'
        )['c'] ?? 0);

        // Overdue remediation plans
        $stats['overdue_remediation'] = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_remediation_plans
             WHERE status IN ("open", "in_progress")
               AND planned_completion IS NOT NULL AND planned_completion < CURDATE()'
        )['c'] ?? 0);

        // Monitor health
        $stats['monitors_passing'] = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_continuous_monitors WHERE is_enabled = 1 AND last_result = "pass"'
        )['c'] ?? 0);

        $stats['monitors_failing'] = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_continuous_monitors WHERE is_enabled = 1 AND last_result IN ("fail", "error")'
        )['c'] ?? 0);

        return $stats;
    }

    /**
     * Take a point-in-time compliance snapshot for dashboard trending.
     */
    public function takeSnapshot(int $frameworkId): void {
        $status = $this->getFrameworkComplianceStatus($frameworkId);
        if (empty($status)) return;

        $today = date('Y-m-d');
        $existing = $this->db->fetchOne(
            'SELECT id FROM grc_dashboard_snapshots WHERE framework_id = :fid AND snapshot_date = :d',
            [':fid' => $frameworkId, ':d' => $today]
        );

        $data = [
            'framework_id' => $frameworkId,
            'snapshot_date' => $today,
            'total_requirements' => $status['total_requirements'],
            'implemented_count' => $status['implemented'],
            'partial_count' => $status['partial'],
            'planned_count' => $status['planned'],
            'not_applicable_count' => $status['not_applicable'],
            'compliance_percentage' => $status['compliance_percentage'],
            'evidence_current' => $this->db->fetchOne(
                'SELECT COUNT(DISTINCT e.id) as c FROM grc_evidence e
                 JOIN grc_evidence_control_map ecm ON ecm.evidence_id = e.id
                 JOIN grc_control_requirement_map crm ON crm.control_id = ecm.control_id
                 JOIN grc_framework_requirements fr ON fr.id = crm.requirement_id
                 WHERE fr.framework_id = :fid AND e.status = "current"',
                [':fid' => $frameworkId]
            )['c'] ?? 0,
            'snapshot_data' => json_encode($status),
        ];

        if ($existing) {
            $this->db->update('grc_dashboard_snapshots', $data,
                'id = :id', [':id' => $existing['id']]);
        } else {
            $this->db->insert('grc_dashboard_snapshots', $data);
        }
    }

    public function getSnapshotTrend(int $frameworkId, int $days = 90): array {
        return $this->db->fetchAll(
            'SELECT snapshot_date, compliance_percentage, implemented_count,
                    total_requirements, open_findings
             FROM grc_dashboard_snapshots
             WHERE framework_id = :fid AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             ORDER BY snapshot_date',
            [':fid' => $frameworkId, ':days' => $days]
        );
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function getScopes(bool $activeOnly = true): array {
        $sql = 'SELECT * FROM grc_scopes';
        if ($activeOnly) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY sort_order, name';
        return $this->db->fetchAll($sql);
    }

    public function createScope(string $name, ?string $description, ?string $color, int $sortOrder, ?int $userId): int {
        $this->db->insert('grc_scopes', [
            'name' => $name,
            'description' => $description,
            'color' => $color ?: '#6B7280',
            'sort_order' => $sortOrder,
            'created_by' => $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateScope(int $id, string $name, ?string $description, ?string $color, int $sortOrder): bool {
        return $this->db->update('grc_scopes', [
            'name' => $name,
            'description' => $description,
            'color' => $color ?: '#6B7280',
            'sort_order' => $sortOrder,
        ], 'id = :id', [':id' => $id]) !== false;
    }

    public function deleteScope(int $id): array {
        // Check if any frameworks use this scope
        $count = $this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_frameworks WHERE scope_id = :id',
            [':id' => $id]
        );
        if ((int)($count['c'] ?? 0) > 0) {
            return ['success' => false, 'error' => 'Cannot delete scope: ' . (int)$count['c'] . ' framework(s) are assigned to it.'];
        }
        $this->db->query('DELETE FROM grc_scopes WHERE id = :id', [':id' => $id]);
        return ['success' => true];
    }

    public function getScope(int $id): ?array {
        return $this->db->fetchOne('SELECT * FROM grc_scopes WHERE id = :id', [':id' => $id]) ?: null;
    }

    // =========================================================================
    // FRAMEWORK CATALOG (template generation)
    // =========================================================================

    /**
     * Get list of available framework templates from PHP data files.
     * @return array [ ['code' => 'ISO27001', 'name' => '...', 'requirement_count' => 93, ...], ... ]
     */
    public function getAvailableCatalogTemplates(): array {
        $catalogDir = __DIR__ . '/../data/catalog';
        $templates = [];

        foreach (glob($catalogDir . '/*.php') as $file) {
            $basename = basename($file, '.php');
            if ($basename === 'crosswalk') continue;

            $data = include $file;
            if (!is_array($data) || empty($data['code'])) continue;

            $templates[] = [
                'code'              => $data['code'],
                'name'              => $data['name'] ?? $data['code'],
                'version'           => $data['version'] ?? null,
                'description'       => $data['description'] ?? '',
                'category'          => $data['category'] ?? 'security',
                'requirement_count' => count($data['requirements'] ?? []),
                'already_generated' => $this->getFrameworkByCode($data['code']) !== null,
            ];
        }

        usort($templates, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $templates;
    }

    /**
     * Generate a framework and all its requirements from a catalog template.
     *
     * @param string   $catalogCode  Template code (e.g., 'ISO27001')
     * @param int|null $scopeId      Optional scope to assign
     * @param int|null $userId       User who triggered generation
     * @return array{ success: bool, framework_id: ?int, count: int, error: ?string }
     */
    public function generateFromCatalog(string $catalogCode, ?int $scopeId = null, ?int $userId = null): array {
        // Load the catalog file
        $catalogDir = __DIR__ . '/../data/catalog';
        $file = null;
        foreach (glob($catalogDir . '/*.php') as $f) {
            $data = include $f;
            if (is_array($data) && ($data['code'] ?? '') === $catalogCode) {
                $file = $f;
                break;
            }
        }

        if (!$file || !is_array($data) || empty($data['requirements'])) {
            return ['success' => false, 'framework_id' => null, 'count' => 0, 'error' => 'Catalog template not found: ' . $catalogCode];
        }

        // Check if framework already exists
        $existing = $this->getFrameworkByCode($catalogCode);
        if ($existing) {
            return ['success' => false, 'framework_id' => (int)$existing['id'], 'count' => 0, 'error' => 'Framework "' . $data['name'] . '" already exists. Delete it first to regenerate.'];
        }

        try {
            $this->db->beginTransaction();

            // Create the framework
            $frameworkData = [
                'code'               => $data['code'],
                'name'               => $data['name'],
                'version'            => $data['version'] ?? null,
                'description'        => $data['description'] ?? null,
                'framework_url'      => $data['url'] ?? null,
                'has_maturity_levels' => !empty($data['has_maturity_levels']) ? 1 : 0,
                'max_maturity_level' => $data['max_maturity_level'] ?? null,
                'generated_from'     => $catalogCode,
                'is_active'          => 1,
                'created_by'         => $userId,
            ];
            if ($scopeId) {
                $frameworkData['scope_id'] = $scopeId;
            }

            $this->db->insert('grc_frameworks', $frameworkData);
            $frameworkId = (int)$this->db->lastInsertId();

            // Insert all requirements
            $count = 0;
            foreach ($data['requirements'] as $req) {
                $reqData = [
                    'framework_id'   => $frameworkId,
                    'requirement_ref' => $req['ref'],
                    'title'          => $req['title'],
                    'description'    => $req['description'] ?? null,
                    'guidance'       => $req['guidance'] ?? null,
                    'maturity_level' => $req['maturity_level'] ?? null,
                    'is_required'    => $req['is_required'] ?? 1,
                    'sort_order'     => $req['sort_order'] ?? $count,
                ];
                $this->db->insert('grc_framework_requirements', $reqData);
                $count++;
            }

            $this->db->commit();
            return ['success' => true, 'framework_id' => $frameworkId, 'count' => $count, 'error' => null];

        } catch (\Exception $e) {
            $this->db->rollback();
            error_log('GRC catalog generation failed: ' . $e->getMessage());
            return ['success' => false, 'framework_id' => null, 'count' => 0, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Get crosswalk mappings for a framework's requirements.
     * Returns which requirements in OTHER frameworks are equivalent.
     *
     * @param int $frameworkId The framework to get crosswalk data for
     * @return array Mapped requirements grouped by target framework
     */
    public function getCatalogCrosswalk(int $frameworkId): array {
        $framework = $this->getFramework($frameworkId);
        if (!$framework) return [];

        $sourceCode = $framework['code'];
        $crosswalkFile = __DIR__ . '/../data/catalog/crosswalk.php';
        if (!file_exists($crosswalkFile)) return [];

        $allMappings = include $crosswalkFile;
        if (!is_array($allMappings)) return [];

        // Find mappings where this framework is source or target
        $relevant = [];
        foreach ($allMappings as $m) {
            $srcParts = explode(':', $m['source'], 2);
            $tgtParts = explode(':', $m['target'], 2);
            if (count($srcParts) !== 2 || count($tgtParts) !== 2) continue;

            if ($srcParts[0] === $sourceCode) {
                $relevant[] = [
                    'source_ref'      => $srcParts[1],
                    'target_code'     => $tgtParts[0],
                    'target_ref'      => $tgtParts[1],
                    'strength'        => $m['strength'] ?? 'related',
                ];
            } elseif ($tgtParts[0] === $sourceCode) {
                $relevant[] = [
                    'source_ref'      => $tgtParts[1],
                    'target_code'     => $srcParts[0],
                    'target_ref'      => $srcParts[1],
                    'strength'        => $m['strength'] ?? 'related',
                ];
            }
        }

        return $relevant;
    }

    /**
     * Auto-map controls to equivalent requirements in other frameworks
     * based on crosswalk data. When a control is mapped to requirement A
     * in framework X, this finds equivalent requirement B in framework Y
     * and creates the mapping if both frameworks exist.
     *
     * @param int $controlId   The control that was just mapped
     * @param int $requirementId The requirement it was mapped to
     * @return int Number of additional mappings created
     */
    public function autoMapCrosswalk(int $controlId, int $requirementId): int {
        $req = $this->getRequirement($requirementId);
        if (!$req) return 0;

        $crosswalk = $this->getCatalogCrosswalk((int)$req['framework_id']);
        if (empty($crosswalk)) return 0;

        $sourceRef = $req['requirement_ref'];
        $mapped = 0;

        foreach ($crosswalk as $cw) {
            if ($cw['source_ref'] !== $sourceRef) continue;
            if (!in_array($cw['strength'], ['exact', 'strong'])) continue;

            // Find the target framework and requirement in our DB
            $targetFw = $this->getFrameworkByCode($cw['target_code']);
            if (!$targetFw) continue;

            $targetReq = $this->db->fetchOne(
                'SELECT id FROM grc_framework_requirements WHERE framework_id = :fid AND requirement_ref = :ref',
                [':fid' => $targetFw['id'], ':ref' => $cw['target_ref']]
            );
            if (!$targetReq) continue;

            // Check if mapping already exists
            $exists = $this->db->fetchOne(
                'SELECT id FROM grc_control_requirement_map WHERE control_id = :cid AND requirement_id = :rid',
                [':cid' => $controlId, ':rid' => $targetReq['id']]
            );
            if ($exists) continue;

            // Create the cross-framework mapping
            $this->db->insert('grc_control_requirement_map', [
                'control_id'    => $controlId,
                'requirement_id' => (int)$targetReq['id'],
            ]);
            $mapped++;
        }

        return $mapped;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function generateNextRef(string $prefix, string $table, string $column): string {
        $last = $this->db->fetchOne(
            "SELECT {$column} FROM {$table} WHERE {$column} LIKE :prefix ORDER BY id DESC LIMIT 1",
            [':prefix' => $prefix . '-%']
        );
        if ($last) {
            $num = (int)substr($last[$column], strlen($prefix) + 1);
            return $prefix . '-' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
        }
        return $prefix . '-001';
    }
}
