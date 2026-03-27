<?php
/**
 * Policy Management Service - The "Living Document" Engine
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Manages the complete policy lifecycle: draft, review, approve, publish, and retire.
 * Tracks version history with full Markdown content, employee acknowledgments
 * (digital signatures), and annual review schedules. Because auditors love
 * seeing that your acceptable use policy was actually read by someone.
 *
 * Policies link to frameworks and controls, so when an auditor asks
 * "show me your access control policy," you can also show every framework
 * requirement it satisfies and every control that implements it.
 */

class PolicyService {
    private static $instance = null;
    private $db;

    private function __construct() {
        $this->db = Database::getInstance();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPolicies(array $filters = []): array {
        $sql = 'SELECT p.*,
                       u.full_name as owner_name,
                       au.full_name as approver_name,
                       (SELECT COUNT(*) FROM grc_policy_acknowledgments pa WHERE pa.policy_id = p.id) as ack_count,
                       (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_users
                FROM grc_policies p
                LEFT JOIN users u ON u.id = p.owner_user_id
                LEFT JOIN users au ON au.id = p.approver_user_id';

        $where = ['p.is_active = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'p.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'p.category = :category';
            $params[':category'] = $filters['category'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.title LIKE :s OR p.policy_ref LIKE :s2)';
            $params[':s'] = '%' . $filters['search'] . '%';
            $params[':s2'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['needs_review'])) {
            $where[] = 'p.next_review_date <= CURDATE()';
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY p.policy_ref';
        return $this->db->fetchAll($sql, $params);
    }

    public function getPolicy(int $id): ?array {
        $policy = $this->db->fetchOne(
            'SELECT p.*, u.full_name as owner_name, au.full_name as approver_name
             FROM grc_policies p
             LEFT JOIN users u ON u.id = p.owner_user_id
             LEFT JOIN users au ON au.id = p.approver_user_id
             WHERE p.id = :id',
            [':id' => $id]
        );
        if (!$policy) return null;

        $policy['versions'] = $this->db->fetchAll(
            'SELECT pv.*, u.full_name as created_by_name, au.full_name as approved_by_name
             FROM grc_policy_versions pv
             LEFT JOIN users u ON u.id = pv.created_by
             LEFT JOIN users au ON au.id = pv.approved_by
             WHERE pv.policy_id = :pid
             ORDER BY pv.version_number DESC',
            [':pid' => $id]
        );

        $policy['acknowledgments'] = $this->db->fetchAll(
            'SELECT pa.*, u.full_name, u.email
             FROM grc_policy_acknowledgments pa
             JOIN users u ON u.id = pa.user_id
             WHERE pa.policy_id = :pid
             ORDER BY pa.acknowledged_at DESC',
            [':pid' => $id]
        );

        $policy['pending_ack_users'] = $this->db->fetchAll(
            'SELECT u.id, u.full_name, u.email
             FROM users u
             WHERE u.is_active = 1
               AND u.id NOT IN (
                   SELECT pa.user_id FROM grc_policy_acknowledgments pa
                   WHERE pa.policy_id = :pid AND pa.policy_version_id = (
                       SELECT MAX(pv.id) FROM grc_policy_versions pv
                       WHERE pv.policy_id = :pid2 AND pv.status = "published"
                   )
               )
             ORDER BY u.full_name',
            [':pid' => $id, ':pid2' => $id]
        );

        return $policy;
    }

    public function createPolicy(array $data, int $userId): int {
        $ref = $this->generateNextRef();

        $this->db->beginTransaction();
        try {
            $this->db->insert('grc_policies', [
                'policy_ref' => $ref,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? 'security',
                'status' => 'draft',
                'owner_user_id' => $data['owner_user_id'] ?? $userId,
                'review_frequency_months' => $data['review_frequency_months'] ?? 12,
                'requires_acknowledgment' => $data['requires_acknowledgment'] ?? 1,
                'acknowledgment_deadline_days' => $data['acknowledgment_deadline_days'] ?? 30,
                'related_frameworks' => $data['related_frameworks'] ?? null,
                'related_controls' => $data['related_controls'] ?? null,
                'created_by' => $userId,
            ]);

            $policyId = (int)$this->db->lastInsertId();

            // Create initial version
            $this->db->insert('grc_policy_versions', [
                'policy_id' => $policyId,
                'version_number' => 1,
                'content_markdown' => $data['content'] ?? '# ' . $data['title'] . "\n\n*Draft policy content*",
                'change_summary' => 'Initial draft',
                'status' => 'draft',
                'created_by' => $userId,
            ]);

            $this->db->commit();
            return $policyId;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function createVersion(int $policyId, string $content, string $changeSummary, int $userId): int {
        $policy = $this->getPolicy($policyId);
        if (!$policy) throw new \RuntimeException('Policy not found');

        $nextVersion = ((int)$policy['current_version']) + 1;

        $this->db->insert('grc_policy_versions', [
            'policy_id' => $policyId,
            'version_number' => $nextVersion,
            'content_markdown' => $content,
            'change_summary' => $changeSummary,
            'status' => 'draft',
            'created_by' => $userId,
        ]);

        $this->db->update('grc_policies',
            ['current_version' => $nextVersion],
            'id = :id', [':id' => $policyId]
        );

        return (int)$this->db->lastInsertId();
    }

    public function approveVersion(int $versionId, int $approverId): void {
        $this->db->update('grc_policy_versions', [
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', [':id' => $versionId]);

        $version = $this->db->fetchOne('SELECT policy_id FROM grc_policy_versions WHERE id = :id', [':id' => $versionId]);
        if ($version) {
            $this->db->update('grc_policies', [
                'status' => 'approved',
                'approver_user_id' => $approverId,
                'approved_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', [':id' => $version['policy_id']]);
        }
    }

    public function publishVersion(int $versionId): void {
        $version = $this->db->fetchOne('SELECT * FROM grc_policy_versions WHERE id = :id', [':id' => $versionId]);
        if (!$version) throw new \RuntimeException('Version not found');

        // Supersede previous published versions
        $this->db->query(
            'UPDATE grc_policy_versions SET status = "superseded"
             WHERE policy_id = :pid AND status = "published" AND id != :vid',
            [':pid' => $version['policy_id'], ':vid' => $versionId]
        );

        $now = date('Y-m-d H:i:s');
        $this->db->update('grc_policy_versions', [
            'status' => 'published',
            'published_at' => $now,
        ], 'id = :id', [':id' => $versionId]);

        $reviewMonths = $this->db->fetchOne(
            'SELECT review_frequency_months FROM grc_policies WHERE id = :id',
            [':id' => $version['policy_id']]
        )['review_frequency_months'] ?? 12;

        $this->db->update('grc_policies', [
            'status' => 'published',
            'published_at' => $now,
            'last_reviewed_at' => $now,
            'next_review_date' => date('Y-m-d', strtotime("+{$reviewMonths} months")),
        ], 'id = :id', [':id' => $version['policy_id']]);
    }

    public function acknowledgePolicy(int $policyId, int $userId, string $ipAddress, string $userAgent): bool {
        $publishedVersion = $this->db->fetchOne(
            'SELECT id FROM grc_policy_versions
             WHERE policy_id = :pid AND status = "published"
             ORDER BY version_number DESC LIMIT 1',
            [':pid' => $policyId]
        );
        if (!$publishedVersion) return false;

        $existing = $this->db->fetchOne(
            'SELECT id FROM grc_policy_acknowledgments
             WHERE user_id = :uid AND policy_id = :pid AND policy_version_id = :vid',
            [':uid' => $userId, ':pid' => $policyId, ':vid' => $publishedVersion['id']]
        );
        if ($existing) return true;

        $signatureData = $userId . ':' . $policyId . ':' . $publishedVersion['id'] . ':' . date('c');
        $signatureHash = hash('sha512', $signatureData);

        $this->db->insert('grc_policy_acknowledgments', [
            'policy_id' => $policyId,
            'policy_version_id' => $publishedVersion['id'],
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => substr($userAgent, 0, 500),
            'signature_hash' => $signatureHash,
        ]);

        return true;
    }

    public function getPendingAcknowledgments(int $userId): array {
        return $this->db->fetchAll(
            'SELECT p.id, p.policy_ref, p.title, p.category,
                    pv.version_number, pv.published_at,
                    p.acknowledgment_deadline_days,
                    DATE_ADD(pv.published_at, INTERVAL p.acknowledgment_deadline_days DAY) as deadline
             FROM grc_policies p
             JOIN grc_policy_versions pv ON pv.policy_id = p.id AND pv.status = "published"
             WHERE p.is_active = 1
               AND p.requires_acknowledgment = 1
               AND p.status = "published"
               AND NOT EXISTS (
                   SELECT 1 FROM grc_policy_acknowledgments pa
                   WHERE pa.policy_id = p.id AND pa.policy_version_id = pv.id AND pa.user_id = :uid
               )
             ORDER BY pv.published_at DESC',
            [':uid' => $userId]
        );
    }

    private function generateNextRef(): string {
        $last = $this->db->fetchOne(
            "SELECT policy_ref FROM grc_policies WHERE policy_ref LIKE 'POL-%' ORDER BY id DESC LIMIT 1"
        );
        if ($last) {
            $num = (int)substr($last['policy_ref'], 4);
            return 'POL-' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
        }
        return 'POL-001';
    }
}
