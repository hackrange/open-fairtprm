<?php
/**
 * FairScore Unified Assessment Engine - Core Service
 *
 * Author: Tim Rice - Hack Range
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Handles the unified assessment lifecycle: creating assessments, recording
 * responses, calculating FairScore maturity ratings, managing assignments
 * and tasks, detecting gaps, and integrating with the risk register.
 */

class UnifiedAssessmentService {
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
    // SECURITY DOMAINS
    // =========================================================================

    public function getDomains(bool $activeOnly = true): array {
        $sql = 'SELECT * FROM grc_security_domains';
        if ($activeOnly) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY sort_order, name';
        return $this->db->fetchAll($sql);
    }

    public function getDomain(int $id): ?array {
        return $this->db->fetchOne('SELECT * FROM grc_security_domains WHERE id = :id', [':id' => $id]) ?: null;
    }

    public function getDomainByCode(string $code): ?array {
        return $this->db->fetchOne('SELECT * FROM grc_security_domains WHERE domain_code = :c', [':c' => $code]) ?: null;
    }

    // =========================================================================
    // UNIFIED QUESTIONS
    // =========================================================================

    public function getQuestions(?int $domainId = null, bool $requiredOnly = false): array {
        $sql = 'SELECT q.*, d.domain_code, d.name as domain_name
                FROM grc_unified_questions q
                JOIN grc_security_domains d ON d.id = q.domain_id
                WHERE 1=1';
        $params = [];
        if ($domainId) {
            $sql .= ' AND q.domain_id = :did';
            $params[':did'] = $domainId;
        }
        if ($requiredOnly) {
            $sql .= ' AND q.is_required = 1';
        }
        $sql .= ' ORDER BY d.sort_order, q.sort_order';
        return $this->db->fetchAll($sql, $params);
    }

    public function getQuestion(int $id): ?array {
        return $this->db->fetchOne(
            'SELECT q.*, d.domain_code, d.name as domain_name
             FROM grc_unified_questions q
             JOIN grc_security_domains d ON d.id = q.domain_id
             WHERE q.id = :id',
            [':id' => $id]
        ) ?: null;
    }

    public function getQuestionByRef(string $ref): ?array {
        return $this->db->fetchOne(
            'SELECT q.*, d.domain_code, d.name as domain_name
             FROM grc_unified_questions q
             JOIN grc_security_domains d ON d.id = q.domain_id
             WHERE q.question_ref = :ref',
            [':ref' => $ref]
        ) ?: null;
    }

    public function getQuestionFrameworkMappings(int $questionId): array {
        return $this->db->fetchAll(
            'SELECT qfm.*, f.code as framework_code, f.name as framework_name,
                    fr.requirement_ref, fr.title as requirement_title
             FROM grc_question_framework_map qfm
             JOIN grc_frameworks f ON f.id = qfm.framework_id
             JOIN grc_framework_requirements fr ON fr.id = qfm.requirement_id
             WHERE qfm.question_id = :qid
             ORDER BY f.sort_order, fr.sort_order',
            [':qid' => $questionId]
        );
    }

    public function getQuestionCount(): int {
        $row = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM grc_unified_questions');
        return (int)($row['cnt'] ?? 0);
    }

    // =========================================================================
    // ASSESSMENTS
    // =========================================================================

    public function getAssessments(?string $status = null): array {
        $sql = 'SELECT a.*, u.full_name as lead_auditor_name, cu.full_name as created_by_name
                FROM grc_assessments a
                LEFT JOIN users u ON u.id = a.lead_auditor_id
                LEFT JOIN users cu ON cu.id = a.created_by
                WHERE 1=1';
        $params = [];
        if ($status) {
            $sql .= ' AND a.status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY a.created_at DESC';
        return $this->db->fetchAll($sql, $params);
    }

    public function getAssessment(int $id): ?array {
        return $this->db->fetchOne(
            'SELECT a.*, u.full_name as lead_auditor_name, cu.full_name as created_by_name
             FROM grc_assessments a
             LEFT JOIN users u ON u.id = a.lead_auditor_id
             LEFT JOIN users cu ON cu.id = a.created_by
             WHERE a.id = :id',
            [':id' => $id]
        ) ?: null;
    }

    public function createAssessment(array $data, int $userId): array {
        // Generate ref
        $last = $this->db->fetchOne("SELECT assessment_ref FROM grc_assessments WHERE assessment_ref LIKE 'FA-%' ORDER BY id DESC LIMIT 1");
        $ref = $last ? 'FA-' . str_pad((int)substr($last['assessment_ref'], 3) + 1, 3, '0', STR_PAD_LEFT) : 'FA-001';

        $this->db->insert('grc_assessments', [
            'assessment_ref' => $ref,
            'title' => trim($data['title']),
            'description' => trim($data['description'] ?? '') ?: null,
            'assessment_type' => $data['assessment_type'] ?? 'initial',
            'scope' => trim($data['scope'] ?? '') ?: null,
            'scope_id' => !empty($data['scope_id']) && $data['scope_id'] !== '__new__' ? (int)$data['scope_id'] : null,
            'status' => 'draft',
            'lead_auditor_id' => !empty($data['lead_auditor_id']) ? (int)$data['lead_auditor_id'] : null,
            'planned_start' => !empty($data['planned_start']) ? $data['planned_start'] : null,
            'planned_end' => !empty($data['planned_end']) ? $data['planned_end'] : null,
            'created_by' => $userId,
        ]);
        $assessmentId = (int)$this->db->lastInsertId();

        // Pre-populate response rows for all active questions
        $questions = $this->getQuestions(null, false);
        foreach ($questions as $q) {
            $this->db->insert('grc_assessment_responses', [
                'assessment_id' => $assessmentId,
                'question_id' => (int)$q['id'],
                'conformity_status' => 'not_assessed',
                'validation_status' => 'pending',
            ]);
        }

        return ['success' => true, 'id' => $assessmentId, 'ref' => $ref];
    }

    public function updateAssessment(int $id, array $data): array {
        $assessment = $this->getAssessment($id);
        if (!$assessment) return ['success' => false, 'error' => 'Assessment not found.'];

        $updateData = [
            'title' => trim($data['title'] ?? $assessment['title']),
            'description' => trim($data['description'] ?? '') ?: null,
            'assessment_type' => $data['assessment_type'] ?? $assessment['assessment_type'],
            'scope' => trim($data['scope'] ?? '') ?: null,
            'scope_id' => !empty($data['scope_id']) && $data['scope_id'] !== '__new__' ? (int)$data['scope_id'] : null,
            'status' => $data['status'] ?? $assessment['status'],
            'lead_auditor_id' => !empty($data['lead_auditor_id']) ? (int)$data['lead_auditor_id'] : null,
            'planned_start' => !empty($data['planned_start']) ? $data['planned_start'] : null,
            'planned_end' => !empty($data['planned_end']) ? $data['planned_end'] : null,
        ];

        // Auto-set actual dates
        if ($data['status'] === 'in_progress' && empty($assessment['actual_start'])) {
            $updateData['actual_start'] = date('Y-m-d');
        }
        if ($data['status'] === 'completed' && empty($assessment['actual_end'])) {
            $updateData['actual_end'] = date('Y-m-d');
        }

        $this->db->update('grc_assessments', $updateData, 'id = :id', [':id' => $id]);
        return ['success' => true];
    }

    public function deleteAssessment(int $id): array {
        $assessment = $this->getAssessment($id);
        if (!$assessment) return ['success' => false, 'error' => 'Assessment not found.'];
        if ($assessment['status'] === 'completed') return ['success' => false, 'error' => 'Cannot delete completed assessments.'];

        $this->db->query('DELETE FROM grc_assessments WHERE id = :id', [':id' => $id]);
        return ['success' => true];
    }

    // =========================================================================
    // ASSESSMENT RESPONSES
    // =========================================================================

    public function getResponses(int $assessmentId, ?int $domainId = null): array {
        $sql = 'SELECT r.*, q.question_ref, q.question_text, q.guidance, q.control_examples,
                       q.maturity_1_desc, q.maturity_2_desc, q.maturity_3_desc, q.maturity_4_desc,
                       d.domain_code, d.name as domain_name, d.id as domain_id,
                       au.full_name as assessor_name, vu.full_name as validator_name
                FROM grc_assessment_responses r
                JOIN grc_unified_questions q ON q.id = r.question_id
                JOIN grc_security_domains d ON d.id = q.domain_id
                LEFT JOIN users au ON au.id = r.assessor_user_id
                LEFT JOIN users vu ON vu.id = r.validated_by
                WHERE r.assessment_id = :aid';
        $params = [':aid' => $assessmentId];
        if ($domainId) {
            $sql .= ' AND d.id = :did';
            $params[':did'] = $domainId;
        }
        $sql .= ' ORDER BY d.sort_order, q.sort_order';
        return $this->db->fetchAll($sql, $params);
    }

    public function getResponse(int $assessmentId, int $questionId): ?array {
        return $this->db->fetchOne(
            'SELECT r.*, q.question_ref, q.question_text, q.guidance, q.control_examples,
                    q.maturity_1_desc, q.maturity_2_desc, q.maturity_3_desc, q.maturity_4_desc,
                    d.domain_code, d.name as domain_name
             FROM grc_assessment_responses r
             JOIN grc_unified_questions q ON q.id = r.question_id
             JOIN grc_security_domains d ON d.id = q.domain_id
             WHERE r.assessment_id = :aid AND r.question_id = :qid',
            [':aid' => $assessmentId, ':qid' => $questionId]
        ) ?: null;
    }

    public function saveResponse(int $assessmentId, int $questionId, array $data, int $userId): array {
        $existing = $this->db->fetchOne(
            'SELECT * FROM grc_assessment_responses WHERE assessment_id = :aid AND question_id = :qid',
            [':aid' => $assessmentId, ':qid' => $questionId]
        );

        // Only update fields that were explicitly sent — preserve existing values
        $maturityRating = $existing ? $existing['maturity_rating'] : null;
        $conformity = $existing ? $existing['conformity_status'] : 'not_assessed';

        if (array_key_exists('maturity_rating', $data)) {
            $maturityRating = ($data['maturity_rating'] !== '' && $data['maturity_rating'] !== null) ? (int)$data['maturity_rating'] : null;
        }
        if (array_key_exists('conformity_status', $data)) {
            $conformity = $data['conformity_status'];
        }

        // Auto-derive conformity from maturity if conformity wasn't explicitly sent
        if (array_key_exists('maturity_rating', $data) && !array_key_exists('conformity_status', $data) && $maturityRating !== null) {
            if ($maturityRating >= 3) $conformity = 'conforming';
            elseif ($maturityRating === 2) $conformity = 'partial';
            elseif ($maturityRating === 1) $conformity = 'non_conforming';
        }

        $updateData = [
            'maturity_rating' => $maturityRating,
            'conformity_status' => $conformity,
            'assessor_user_id' => $userId,
            'assessed_at' => date('Y-m-d H:i:s'),
        ];

        // Only update notes if explicitly sent
        if (array_key_exists('notes', $data)) {
            $updateData['notes'] = trim($data['notes'] ?? '') ?: null;
        }

        if ($existing) {
            $this->db->update('grc_assessment_responses', $updateData, 'id = :id', [':id' => $existing['id']]);
        } else {
            $updateData['assessment_id'] = $assessmentId;
            $updateData['question_id'] = $questionId;
            $updateData['validation_status'] = 'pending';
            if (!array_key_exists('notes', $updateData)) {
                $updateData['notes'] = null;
            }
            $this->db->insert('grc_assessment_responses', $updateData);
        }

        // Auto-gap detection: if non-conforming or partial, create risk register entry
        if (in_array($conformity, ['non_conforming', 'partial'])) {
            $this->autoLogGap($assessmentId, $questionId, $conformity, $userId);
        }

        return ['success' => true, 'conformity_status' => $conformity, 'maturity_rating' => $maturityRating];
    }

    public function validateResponse(int $responseId, string $status, ?string $notes, int $userId): array {
        $validStatuses = ['validated', 'rejected', 'needs_review'];
        if (!in_array($status, $validStatuses)) return ['success' => false, 'error' => 'Invalid validation status.'];

        $this->db->update('grc_assessment_responses', [
            'validation_status' => $status,
            'validated_by' => $userId,
            'validated_at' => date('Y-m-d H:i:s'),
            'validation_notes' => $notes,
        ], 'id = :id', [':id' => $responseId]);

        return ['success' => true];
    }

    // =========================================================================
    // GAP DETECTION & RISK REGISTER INTEGRATION
    // =========================================================================

    private function autoLogGap(int $assessmentId, int $questionId, string $conformity, int $userId): void {
        $question = $this->getQuestion($questionId);
        if (!$question) return;

        // Check if gap already logged for this assessment+question
        $existing = $this->db->fetchOne(
            "SELECT id FROM grc_risk_register WHERE title LIKE :title AND status != 'closed'",
            [':title' => '%[' . $question['question_ref'] . ']%']
        );
        if ($existing) return;

        // Generate risk ref
        $last = $this->db->fetchOne("SELECT risk_ref FROM grc_risk_register WHERE risk_ref LIKE 'RISK-%' ORDER BY id DESC LIMIT 1");
        $riskRef = $last ? 'RISK-' . str_pad((int)substr($last['risk_ref'], 5) + 1, 3, '0', STR_PAD_LEFT) : 'RISK-001';

        $severity = ($conformity === 'non_conforming') ? 'likely' : 'possible';
        $impact = ($conformity === 'non_conforming') ? 'major' : 'moderate';

        // Get cross-framework impact
        $mappings = $this->getQuestionFrameworkMappings($questionId);
        $frameworkList = [];
        foreach ($mappings as $m) {
            $frameworkList[] = $m['framework_code'] . ':' . $m['requirement_ref'];
        }
        $frameworkImpact = implode(', ', $frameworkList);

        $this->db->insert('grc_risk_register', [
            'risk_ref' => $riskRef,
            'title' => 'Gap Detected [' . $question['question_ref'] . ']: ' . substr($question['question_text'], 0, 200),
            'description' => 'Auto-detected gap from FairScore Assessment. Conformity: ' . ucfirst(str_replace('_', ' ', $conformity))
                . ". Cross-framework impact: " . $frameworkImpact,
            'risk_category' => 'compliance',
            'likelihood' => $severity,
            'impact' => $impact,
            'risk_treatment' => 'mitigate',
            'status' => 'identified',
            'created_by' => $userId,
        ]);
    }

    public function getGapAnalysis(int $assessmentId): array {
        $gaps = $this->db->fetchAll(
            "SELECT r.*, q.question_ref, q.question_text, d.domain_code, d.name as domain_name
             FROM grc_assessment_responses r
             JOIN grc_unified_questions q ON q.id = r.question_id
             JOIN grc_security_domains d ON d.id = q.domain_id
             WHERE r.assessment_id = :aid AND r.conformity_status IN ('non_conforming', 'partial')
             ORDER BY r.conformity_status, d.sort_order, q.sort_order",
            [':aid' => $assessmentId]
        );

        // Enrich with framework impact
        foreach ($gaps as &$gap) {
            $gap['framework_impact'] = $this->getQuestionFrameworkMappings((int)$gap['question_id']);
        }

        return $gaps;
    }

    // =========================================================================
    // FAIRSCORE CALCULATION
    // =========================================================================

    public function calculateDomainScores(int $assessmentId): array {
        $domains = $this->getDomains();
        $scores = [];

        foreach ($domains as $domain) {
            $responses = $this->db->fetchAll(
                "SELECT r.maturity_rating, r.conformity_status
                 FROM grc_assessment_responses r
                 JOIN grc_unified_questions q ON q.id = r.question_id
                 WHERE r.assessment_id = :aid AND q.domain_id = :did",
                [':aid' => $assessmentId, ':did' => $domain['id']]
            );

            $total = count($responses);
            $answered = 0;
            $sum = 0;
            $conforming = 0;
            $partial = 0;
            $nonConforming = 0;
            $na = 0;

            foreach ($responses as $r) {
                if ($r['conformity_status'] === 'not_applicable') {
                    $na++;
                    continue;
                }
                if ($r['maturity_rating'] !== null) {
                    $answered++;
                    $sum += (int)$r['maturity_rating'];
                }
                if ($r['conformity_status'] === 'conforming') $conforming++;
                elseif ($r['conformity_status'] === 'partial') $partial++;
                elseif ($r['conformity_status'] === 'non_conforming') $nonConforming++;
            }

            $avgScore = $answered > 0 ? round($sum / $answered, 2) : null;

            // Upsert domain score
            $existing = $this->db->fetchOne(
                'SELECT id FROM grc_assessment_domain_scores WHERE assessment_id = :aid AND domain_id = :did',
                [':aid' => $assessmentId, ':did' => $domain['id']]
            );

            $scoreData = [
                'average_score' => $avgScore,
                'questions_total' => $total,
                'questions_answered' => $answered,
                'conforming_count' => $conforming,
                'partial_count' => $partial,
                'non_conforming_count' => $nonConforming,
                'not_applicable_count' => $na,
            ];

            if ($existing) {
                $this->db->update('grc_assessment_domain_scores', $scoreData, 'id = :id', [':id' => $existing['id']]);
            } else {
                $scoreData['assessment_id'] = $assessmentId;
                $scoreData['domain_id'] = $domain['id'];
                $this->db->insert('grc_assessment_domain_scores', $scoreData);
            }

            $scores[] = array_merge($scoreData, [
                'domain_code' => $domain['domain_code'],
                'domain_name' => $domain['name'],
            ]);
        }

        // Calculate overall FairScore
        $totalAnswered = 0;
        $totalSum = 0;
        foreach ($scores as $s) {
            if ($s['average_score'] !== null) {
                $totalAnswered += $s['questions_answered'];
                $totalSum += $s['average_score'] * $s['questions_answered'];
            }
        }
        $overallScore = $totalAnswered > 0 ? round($totalSum / $totalAnswered, 2) : null;

        // Calculate overall compliance percentage
        $totalQ = array_sum(array_column($scores, 'questions_total'));
        $totalNA = array_sum(array_column($scores, 'not_applicable_count'));
        $totalConforming = array_sum(array_column($scores, 'conforming_count'));
        $applicableQ = $totalQ - $totalNA;
        $compliancePct = $applicableQ > 0 ? round(($totalConforming / $applicableQ) * 100, 2) : null;

        // Update assessment header
        $this->db->update('grc_assessments', [
            'overall_fairscore' => $overallScore,
            'overall_compliance_pct' => $compliancePct,
        ], 'id = :id', [':id' => $assessmentId]);

        return [
            'domain_scores' => $scores,
            'overall_fairscore' => $overallScore,
            'overall_compliance_pct' => $compliancePct,
        ];
    }

    public function getDomainScores(int $assessmentId): array {
        return $this->db->fetchAll(
            'SELECT ds.*, d.domain_code, d.name as domain_name
             FROM grc_assessment_domain_scores ds
             JOIN grc_security_domains d ON d.id = ds.domain_id
             WHERE ds.assessment_id = :aid
             ORDER BY d.sort_order',
            [':aid' => $assessmentId]
        );
    }

    // =========================================================================
    // FRAMEWORK COMPLIANCE SCORES
    // =========================================================================

    public function calculateFrameworkCompliance(int $assessmentId): array {
        $frameworks = $this->db->fetchAll(
            'SELECT DISTINCT f.id, f.code, f.name
             FROM grc_question_framework_map qfm
             JOIN grc_frameworks f ON f.id = qfm.framework_id
             WHERE f.is_active = 1
             ORDER BY f.sort_order'
        );

        $results = [];
        foreach ($frameworks as $fw) {
            // Get all mapped questions for this framework
            $mappedResponses = $this->db->fetchAll(
                "SELECT r.maturity_rating, r.conformity_status, qfm.mapping_strength
                 FROM grc_question_framework_map qfm
                 JOIN grc_assessment_responses r ON r.question_id = qfm.question_id AND r.assessment_id = :aid
                 WHERE qfm.framework_id = :fid",
                [':aid' => $assessmentId, ':fid' => $fw['id']]
            );

            $total = count($mappedResponses);
            $conforming = 0;
            $partial = 0;
            $nonConforming = 0;
            $na = 0;
            $maturitySum = 0;
            $maturityCount = 0;

            foreach ($mappedResponses as $mr) {
                if ($mr['conformity_status'] === 'conforming') $conforming++;
                elseif ($mr['conformity_status'] === 'partial') $partial++;
                elseif ($mr['conformity_status'] === 'non_conforming') $nonConforming++;
                elseif ($mr['conformity_status'] === 'not_applicable') $na++;
                if ($mr['maturity_rating'] !== null) {
                    $maturitySum += (int)$mr['maturity_rating'];
                    $maturityCount++;
                }
            }

            $applicable = $total - $na;
            $pct = $applicable > 0 ? round((($conforming + $partial * 0.5) / $applicable) * 100, 2) : null;
            $avgMaturity = $maturityCount > 0 ? round($maturitySum / $maturityCount, 2) : null;

            $results[] = [
                'framework_code' => $fw['code'],
                'framework_name' => $fw['name'],
                'total_mapped' => $total,
                'conforming' => $conforming,
                'partial' => $partial,
                'non_conforming' => $nonConforming,
                'not_applicable' => $na,
                'compliance_pct' => $pct,
                'avg_maturity' => $avgMaturity,
                'maturity_count' => $maturityCount,
            ];
        }

        return $results;
    }

    // =========================================================================
    // ASSIGNMENTS
    // =========================================================================

    public function createAssignment(int $assessmentId, int $assignedTo, int $assignedBy, array $data): array {
        $this->db->insert('grc_assessment_assignments', [
            'assessment_id' => $assessmentId,
            'question_id' => !empty($data['question_id']) ? (int)$data['question_id'] : null,
            'domain_id' => !empty($data['domain_id']) ? (int)$data['domain_id'] : null,
            'assigned_to' => $assignedTo,
            'assigned_by' => $assignedBy,
            'role' => $data['role'] ?? 'assessor',
            'due_date' => !empty($data['due_date']) ? $data['due_date'] : null,
            'notes' => trim($data['notes'] ?? '') ?: null,
        ]);
        return ['success' => true, 'id' => (int)$this->db->lastInsertId()];
    }

    public function getAssignments(int $assessmentId): array {
        return $this->db->fetchAll(
            'SELECT aa.*, u.full_name as assigned_to_name, ab.full_name as assigned_by_name,
                    d.domain_code, d.name as domain_name, q.question_ref
             FROM grc_assessment_assignments aa
             LEFT JOIN users u ON u.id = aa.assigned_to
             LEFT JOIN users ab ON ab.id = aa.assigned_by
             LEFT JOIN grc_security_domains d ON d.id = aa.domain_id
             LEFT JOIN grc_unified_questions q ON q.id = aa.question_id
             WHERE aa.assessment_id = :aid
             ORDER BY aa.created_at DESC',
            [':aid' => $assessmentId]
        );
    }

    public function getMyAssignments(int $userId): array {
        return $this->db->fetchAll(
            "SELECT aa.*, a.title as assessment_title, a.assessment_ref, a.status as assessment_status,
                    d.domain_code, d.name as domain_name, q.question_ref
             FROM grc_assessment_assignments aa
             JOIN grc_assessments a ON a.id = aa.assessment_id
             LEFT JOIN grc_security_domains d ON d.id = aa.domain_id
             LEFT JOIN grc_unified_questions q ON q.id = aa.question_id
             WHERE aa.assigned_to = :uid AND aa.status IN ('assigned', 'in_progress')
             ORDER BY aa.due_date ASC, aa.created_at DESC",
            [':uid' => $userId]
        );
    }

    // =========================================================================
    // TASKS
    // =========================================================================

    public function createTask(int $assessmentId, array $data, int $userId): array {
        $last = $this->db->fetchOne("SELECT task_ref FROM grc_assessment_tasks WHERE task_ref LIKE 'TSK-%' ORDER BY id DESC LIMIT 1");
        $ref = $last ? 'TSK-' . str_pad((int)substr($last['task_ref'], 4) + 1, 3, '0', STR_PAD_LEFT) : 'TSK-001';

        $this->db->insert('grc_assessment_tasks', [
            'task_ref' => $ref,
            'assessment_id' => $assessmentId,
            'question_id' => !empty($data['question_id']) ? (int)$data['question_id'] : null,
            'title' => trim($data['title']),
            'description' => trim($data['description'] ?? '') ?: null,
            'task_type' => $data['task_type'] ?? 'evidence_request',
            'priority' => $data['priority'] ?? 'medium',
            'assigned_to' => !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null,
            'assigned_by' => $userId,
            'due_date' => !empty($data['due_date']) ? $data['due_date'] : null,
        ]);

        return ['success' => true, 'id' => (int)$this->db->lastInsertId(), 'ref' => $ref];
    }

    public function getTasks(int $assessmentId): array {
        return $this->db->fetchAll(
            'SELECT t.*, u.full_name as assigned_to_name, ab.full_name as assigned_by_name,
                    q.question_ref
             FROM grc_assessment_tasks t
             LEFT JOIN users u ON u.id = t.assigned_to
             LEFT JOIN users ab ON ab.id = t.assigned_by
             LEFT JOIN grc_unified_questions q ON q.id = t.question_id
             WHERE t.assessment_id = :aid
             ORDER BY FIELD(t.priority, "critical", "high", "medium", "low"), t.due_date ASC',
            [':aid' => $assessmentId]
        );
    }

    public function getMyTasks(int $userId): array {
        return $this->db->fetchAll(
            "SELECT t.*, a.title as assessment_title, a.assessment_ref, q.question_ref
             FROM grc_assessment_tasks t
             JOIN grc_assessments a ON a.id = t.assessment_id
             LEFT JOIN grc_unified_questions q ON q.id = t.question_id
             WHERE t.assigned_to = :uid AND t.status IN ('open', 'in_progress')
             ORDER BY FIELD(t.priority, 'critical', 'high', 'medium', 'low'), t.due_date ASC",
            [':uid' => $userId]
        );
    }

    public function updateTask(int $taskId, array $data, int $userId): array {
        $update = [];
        if (isset($data['status'])) {
            $update['status'] = $data['status'];
            if ($data['status'] === 'completed') {
                $update['completed_at'] = date('Y-m-d H:i:s');
                $update['completed_by'] = $userId;
            }
        }
        if (isset($data['notes'])) $update['notes'] = trim($data['notes']) ?: null;
        if (isset($data['priority'])) $update['priority'] = $data['priority'];

        if (!empty($update)) {
            $this->db->update('grc_assessment_tasks', $update, 'id = :id', [':id' => $taskId]);
        }
        return ['success' => true];
    }

    // =========================================================================
    // MATURITY HISTORY & TARGETS
    // =========================================================================

    public function createSnapshot(int $assessmentId): array {
        $scores = $this->calculateDomainScores($assessmentId);
        $fwScores = $this->calculateFrameworkCompliance($assessmentId);

        $domainJson = [];
        foreach ($scores['domain_scores'] as $ds) {
            $domainJson[$ds['domain_code']] = $ds['average_score'];
        }
        $fwJson = [];
        foreach ($fwScores as $fs) {
            $fwJson[$fs['framework_code']] = $fs['compliance_pct'];
        }

        $this->db->insert('grc_maturity_history', [
            'assessment_id' => $assessmentId,
            'snapshot_date' => date('Y-m-d'),
            'overall_score' => $scores['overall_fairscore'],
            'domain_scores' => json_encode($domainJson),
            'framework_scores' => json_encode($fwJson),
        ]);

        return ['success' => true, 'overall_score' => $scores['overall_fairscore']];
    }

    public function getMaturityHistory(int $assessmentId): array {
        return $this->db->fetchAll(
            'SELECT * FROM grc_maturity_history WHERE assessment_id = :aid ORDER BY snapshot_date DESC',
            [':aid' => $assessmentId]
        );
    }

    public function getMaturityTargets(): array {
        return $this->db->fetchAll(
            'SELECT mt.*, d.domain_code, d.name as domain_name, u.full_name as assigned_to_name
             FROM grc_maturity_targets mt
             JOIN grc_security_domains d ON d.id = mt.domain_id
             LEFT JOIN users u ON u.id = mt.assigned_to
             ORDER BY d.sort_order'
        );
    }

    public function setMaturityTarget(int $domainId, array $data, int $userId): array {
        $existing = $this->db->fetchOne(
            'SELECT id FROM grc_maturity_targets WHERE domain_id = :did',
            [':did' => $domainId]
        );

        $targetData = [
            'target_score' => (float)$data['target_score'],
            'current_score' => !empty($data['current_score']) ? (float)$data['current_score'] : null,
            'target_date' => !empty($data['target_date']) ? $data['target_date'] : null,
            'action_plan' => trim($data['action_plan'] ?? '') ?: null,
            'status' => $data['status'] ?? 'not_started',
            'assigned_to' => !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null,
        ];

        if ($existing) {
            $this->db->update('grc_maturity_targets', $targetData, 'id = :id', [':id' => $existing['id']]);
        } else {
            $targetData['domain_id'] = $domainId;
            $targetData['created_by'] = $userId;
            $this->db->insert('grc_maturity_targets', $targetData);
        }

        return ['success' => true];
    }

    // =========================================================================
    // RECOMMENDED CONTROLS CATALOG
    // =========================================================================

    public function getRecommendedControls(?string $category = null): array {
        $sql = 'SELECT * FROM grc_recommended_controls WHERE is_active = 1';
        $params = [];
        if ($category) {
            $sql .= ' AND category = :cat';
            $params[':cat'] = $category;
        }
        $sql .= ' ORDER BY category, sort_order, control_name';
        return $this->db->fetchAll($sql, $params);
    }

    public function getRecommendedControlCategories(): array {
        return $this->db->fetchAll(
            'SELECT DISTINCT category FROM grc_recommended_controls WHERE is_active = 1 ORDER BY category'
        );
    }

    // =========================================================================
    // SEED QUESTIONS FROM CATALOG
    // =========================================================================

    public function seedQuestionsFromCatalog(): array {
        $catalogFile = __DIR__ . '/../data/catalog/unified_questions.php';
        if (!file_exists($catalogFile)) return ['success' => false, 'error' => 'Question catalog file not found.'];

        $catalog = include $catalogFile;
        if (!is_array($catalog)) return ['success' => false, 'error' => 'Invalid question catalog format.'];

        $inserted = 0;
        $skipped = 0;

        foreach ($catalog as $domainCode => $questions) {
            $domain = $this->getDomainByCode($domainCode);
            if (!$domain) continue;

            foreach ($questions as $q) {
                // Skip if already exists
                $existing = $this->db->fetchOne(
                    'SELECT id FROM grc_unified_questions WHERE question_ref = :ref',
                    [':ref' => $q['ref']]
                );
                if ($existing) {
                    $skipped++;
                    continue;
                }

                $this->db->insert('grc_unified_questions', [
                    'question_ref' => $q['ref'],
                    'domain_id' => (int)$domain['id'],
                    'question_text' => $q['question'],
                    'guidance' => $q['guidance'] ?? null,
                    'maturity_1_desc' => $q['maturity_1'] ?? null,
                    'maturity_2_desc' => $q['maturity_2'] ?? null,
                    'maturity_3_desc' => $q['maturity_3'] ?? null,
                    'maturity_4_desc' => $q['maturity_4'] ?? null,
                    'control_examples' => $q['control_examples'] ?? null,
                    'is_required' => 1,
                    'sort_order' => $q['sort_order'] ?? 0,
                ]);
                $inserted++;
            }
        }

        return ['success' => true, 'inserted' => $inserted, 'skipped' => $skipped];
    }

    public function seedFrameworkMappings(): array {
        $mappingsFile = __DIR__ . '/../data/catalog/unified_question_mappings.php';
        if (!file_exists($mappingsFile)) return ['success' => false, 'error' => 'Mappings catalog file not found.'];

        $mappings = include $mappingsFile;
        if (!is_array($mappings)) return ['success' => false, 'error' => 'Invalid mappings format.'];

        // Catalog codes may differ from DB codes — map aliases
        $codeAliases = [
            'NIST800171' => ['NIST800171', 'NIST-171', 'NIST800-171'],
            'PCI_DSS'    => ['PCI_DSS', 'PCI-DSS', 'PCI'],
            'CSF'        => ['CSF', 'NIST-CSF', 'NIST_CSF'],
            'HIPAA'      => ['HIPAA'],
            'CIS'        => ['CIS'],
        ];

        // Ensure HIPAA and CIS frameworks exist
        $missingFrameworks = [
            'HIPAA' => ['name' => 'HIPAA Security Rule', 'version' => '2013', 'description' => 'Health Insurance Portability and Accountability Act'],
            'CIS'   => ['name' => 'CIS Controls v8', 'version' => 'v8', 'description' => 'Center for Internet Security Critical Security Controls'],
        ];
        foreach ($missingFrameworks as $code => $info) {
            $exists = $this->db->fetchOne('SELECT id FROM grc_frameworks WHERE code = :c', [':c' => $code]);
            if (!$exists) {
                $this->db->insert('grc_frameworks', [
                    'code' => $code, 'name' => $info['name'],
                    'version' => $info['version'], 'description' => $info['description'],
                    'is_active' => 1,
                ]);
            }
        }

        // Build framework cache: catalog_code => framework DB row
        $fwCache = [];
        $allFrameworks = $this->db->fetchAll('SELECT id, code FROM grc_frameworks');
        $fwByCode = [];
        foreach ($allFrameworks as $fw) { $fwByCode[$fw['code']] = $fw; }

        $inserted = 0;
        $skipped = 0;
        $errors = 0;
        $reqCreated = 0;

        foreach ($mappings as $questionRef => $maps) {
            $question = $this->getQuestionByRef($questionRef);
            if (!$question) { $errors++; continue; }

            foreach ($maps as $m) {
                $fwCode = $m['f'] ?? $m['framework'] ?? null;
                $reqRef = $m['r'] ?? $m['ref'] ?? null;
                $strength = $m['s'] ?? $m['strength'] ?? 'strong';

                if (!$fwCode || !$reqRef) { $errors++; continue; }

                // Resolve framework via aliases
                $fw = null;
                $aliases = $codeAliases[$fwCode] ?? [$fwCode];
                foreach ($aliases as $alias) {
                    if (isset($fwByCode[$alias])) { $fw = $fwByCode[$alias]; break; }
                }
                if (!$fw) { $skipped++; continue; }

                // Look up requirement — auto-create if missing
                $req = $this->db->fetchOne(
                    'SELECT id FROM grc_framework_requirements WHERE framework_id = :fid AND requirement_ref = :ref',
                    [':fid' => $fw['id'], ':ref' => $reqRef]
                );
                if (!$req) {
                    // Auto-create the requirement stub
                    $this->db->insert('grc_framework_requirements', [
                        'framework_id' => (int)$fw['id'],
                        'requirement_ref' => $reqRef,
                        'title' => $reqRef,
                        'is_required' => 1,
                    ]);
                    $reqId = (int)$this->db->lastInsertId();
                    $req = ['id' => $reqId];
                    $reqCreated++;
                }

                // Check if already mapped
                $existing = $this->db->fetchOne(
                    'SELECT id FROM grc_question_framework_map WHERE question_id = :qid AND requirement_id = :rid',
                    [':qid' => $question['id'], ':rid' => $req['id']]
                );
                if ($existing) { $skipped++; continue; }

                $this->db->insert('grc_question_framework_map', [
                    'question_id' => (int)$question['id'],
                    'framework_id' => (int)$fw['id'],
                    'requirement_id' => (int)$req['id'],
                    'mapping_strength' => $strength,
                ]);
                $inserted++;
            }
        }

        return ['success' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors, 'requirements_created' => $reqCreated];
    }

    // =========================================================================
    // PROGRESS STATS
    // =========================================================================

    public function getAssessmentProgress(int $assessmentId): array {
        $total = $this->db->fetchOne(
            'SELECT COUNT(*) as cnt FROM grc_assessment_responses WHERE assessment_id = :aid',
            [':aid' => $assessmentId]
        );
        $answered = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM grc_assessment_responses
             WHERE assessment_id = :aid AND conformity_status != 'not_assessed'",
            [':aid' => $assessmentId]
        );
        $validated = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM grc_assessment_responses
             WHERE assessment_id = :aid AND validation_status = 'validated'",
            [':aid' => $assessmentId]
        );

        $totalCount = (int)($total['cnt'] ?? 0);
        $answeredCount = (int)($answered['cnt'] ?? 0);
        $validatedCount = (int)($validated['cnt'] ?? 0);

        return [
            'total_questions' => $totalCount,
            'answered' => $answeredCount,
            'pending' => $totalCount - $answeredCount,
            'validated' => $validatedCount,
            'progress_pct' => $totalCount > 0 ? round(($answeredCount / $totalCount) * 100, 1) : 0,
            'validation_pct' => $answeredCount > 0 ? round(($validatedCount / $answeredCount) * 100, 1) : 0,
        ];
    }
}
