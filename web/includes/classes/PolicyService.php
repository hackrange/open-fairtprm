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

    /**
     * SQL predicate selecting the users who have to acknowledge a given policy.
     * Expects the policy to be aliased `p` and the user to be aliased with
     * whatever is passed in $userAlias.
     *
     * A policy with no rows in grc_policy_ack_groups is addressed to every
     * active user -- that is how policies behaved before audiences existed, so
     * existing policies keep their meaning. Otherwise the audience is the union
     * of the members of the selected groups.
     */
    private function audiencePredicate(string $userAlias): string {
        return "{$userAlias}.is_active = 1
                AND (
                    NOT EXISTS (SELECT 1 FROM grc_policy_ack_groups g0 WHERE g0.policy_id = p.id)
                    OR EXISTS (
                        SELECT 1 FROM grc_policy_ack_groups g1
                        JOIN user_acl_groups ug1 ON ug1.group_id = g1.group_id
                        WHERE g1.policy_id = p.id AND ug1.user_id = {$userAlias}.id
                    )
                )";
    }

    public function getPolicies(array $filters = []): array {
        // total_users is the size of each policy's own audience, not the whole
        // user table, so the acknowledged percentage means "of those who must".
        $audience = $this->audiencePredicate('u2');
        $sql = "SELECT p.*,
                       u.full_name as owner_name,
                       au.full_name as approver_name,
                       (SELECT COUNT(*) FROM grc_policy_acknowledgments pa WHERE pa.policy_id = p.id) as ack_count,
                       (SELECT COUNT(*) FROM users u2 WHERE {$audience}) as total_users
                FROM grc_policies p";
        $sql .= '
                LEFT JOIN users u ON u.id = p.owner_user_id
                LEFT JOIN users au ON au.id = p.approver_user_id';

        // Retired policies are hidden by default -- they are out of force, and
        // leaving them on the board is what made retirement feel like a dead
        // end. They come back when explicitly asked for, either via the "Show
        // Retired" toggle or by filtering on the retired status directly.
        $where = [];
        $params = [];

        $showRetired = !empty($filters['show_retired']) || (($filters['status'] ?? '') === 'retired');
        if (!$showRetired) {
            $where[] = 'p.is_active = 1';
        }

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

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
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

        // Only the policy's audience can be pending -- someone outside it was
        // never being asked in the first place.
        $audience = $this->audiencePredicate('u');
        $policy['pending_ack_users'] = $this->db->fetchAll(
            "SELECT u.id, u.full_name, u.email
             FROM users u
             CROSS JOIN grc_policies p
             WHERE p.id = :pid0
               AND {$audience}
               AND u.id NOT IN (
                   SELECT pa.user_id FROM grc_policy_acknowledgments pa
                   WHERE pa.policy_id = :pid AND pa.policy_version_id = (
                       SELECT MAX(pv.id) FROM grc_policy_versions pv
                       WHERE pv.policy_id = :pid2 AND pv.status = \"published\"
                   )
               )
             ORDER BY u.full_name",
            [':pid0' => $id, ':pid' => $id, ':pid2' => $id]
        );

        $policy['ack_groups']      = $this->getAckGroups($id);
        $policy['audience_size']   = $this->getAudienceSize($id);

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

            // Acknowledgment audience. No groups means every active user.
            foreach (array_unique(array_map('intval', $data['ack_groups'] ?? [])) as $gid) {
                if ($gid <= 0) continue;
                $group = $this->db->fetchOne('SELECT id FROM acl_groups WHERE id = :id AND is_active = 1', [':id' => $gid]);
                if ($group) {
                    $this->db->insert('grc_policy_ack_groups', [
                        'policy_id' => $policyId,
                        'group_id'  => (int)$group['id'],
                    ]);
                }
            }

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
        // Someone outside the audience was never asked, so their signature would
        // be noise in the compliance record.
        if (!$this->isInAudience($policyId, $userId)) return false;

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
        // Only chase this user for policies actually addressed to them.
        $audience = $this->audiencePredicate('u');
        return $this->db->fetchAll(
            "SELECT p.id, p.policy_ref, p.title, p.category,
                    pv.version_number, pv.published_at,
                    p.acknowledgment_deadline_days,
                    DATE_ADD(pv.published_at, INTERVAL p.acknowledgment_deadline_days DAY) as deadline
             FROM grc_policies p
             JOIN grc_policy_versions pv ON pv.policy_id = p.id AND pv.status = \"published\"
             JOIN users u ON u.id = :uid0
             WHERE p.is_active = 1
               AND p.requires_acknowledgment = 1
               AND p.status = \"published\"
               AND {$audience}
               AND NOT EXISTS (
                   SELECT 1 FROM grc_policy_acknowledgments pa
                   WHERE pa.policy_id = p.id AND pa.policy_version_id = pv.id AND pa.user_id = :uid
               )
             ORDER BY pv.published_at DESC",
            [':uid0' => $userId, ':uid' => $userId]
        );
    }

    /**
     * Delete a policy outright. Two things are never deletable:
     *
     *   - System policies (is_system = 1). These ship with the product; an admin
     *     who does not want one retires it, but it stays in the table.
     *   - Anything a user has acknowledged. The acknowledgment is a digital
     *     signature attesting somebody read the thing, and an auditor will ask
     *     for it long after the policy itself stops mattering. Retire instead.
     *
     * What is left is a user-created policy nobody is tied to -- a typo, a
     * duplicate, an experiment -- and removing it loses nothing. The child rows
     * (versions, acknowledgments, review schedule) are ON DELETE CASCADE, so the
     * single delete takes them with it and leaves nothing dangling.
     *
     * Returns '' on success, or a reason code the caller maps to a message:
     * 'not_found', 'system', 'acknowledged'.
     */
    public function deletePolicy(int $id): string {
        $policy = $this->db->fetchOne(
            'SELECT id, is_system FROM grc_policies WHERE id = :id',
            [':id' => $id]
        );
        if (!$policy) return 'not_found';
        if ((int)$policy['is_system'] === 1) return 'system';

        $acks = (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_policy_acknowledgments WHERE policy_id = :id',
            [':id' => $id]
        )['c'] ?? 0);
        if ($acks > 0) return 'acknowledged';

        $this->db->delete('grc_policies', 'id = :id', [':id' => $id]);
        return '';
    }

    /**
     * The ACL groups a policy is addressed to, with their display names.
     * An empty array means the policy is addressed to every active user.
     */
    public function getAckGroups(int $policyId): array {
        return $this->db->fetchAll(
            'SELECT g.id, g.group_name, g.display_name
             FROM grc_policy_ack_groups pag
             JOIN acl_groups g ON g.id = pag.group_id
             WHERE pag.policy_id = :pid
             ORDER BY g.display_name',
            [':pid' => $policyId]
        );
    }

    /**
     * Replace a policy's acknowledgment audience with the given group ids.
     * Passing an empty array addresses the policy to every active user.
     *
     * Existing acknowledgments are deliberately left alone. If someone
     * acknowledged a policy and is then no longer in its audience, their
     * signature stays on file -- it was true when they gave it, and deleting
     * signed attestations because an audience changed would be exactly the kind
     * of quiet evidence loss the rest of this module works to prevent.
     */
    public function setAckGroups(int $policyId, array $groupIds): string {
        $policy = $this->db->fetchOne('SELECT id FROM grc_policies WHERE id = :id', [':id' => $policyId]);
        if (!$policy) return 'not_found';

        // Only real, active groups -- a stale id would silently shrink the audience.
        $valid = [];
        foreach (array_unique(array_map('intval', $groupIds)) as $gid) {
            if ($gid <= 0) continue;
            $row = $this->db->fetchOne('SELECT id FROM acl_groups WHERE id = :id AND is_active = 1', [':id' => $gid]);
            if ($row) $valid[] = (int)$row['id'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->delete('grc_policy_ack_groups', 'policy_id = :pid', [':pid' => $policyId]);
            foreach ($valid as $gid) {
                $this->db->insert('grc_policy_ack_groups', [
                    'policy_id' => $policyId,
                    'group_id'  => $gid,
                ]);
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        return '';
    }

    /**
     * Active groups that can be picked as an acknowledgment audience.
     */
    public function getSelectableGroups(): array {
        return $this->db->fetchAll(
            'SELECT g.id, g.group_name, g.display_name,
                    (SELECT COUNT(*) FROM user_acl_groups ug
                      JOIN users u ON u.id = ug.user_id
                      WHERE ug.group_id = g.id AND u.is_active = 1) as member_count
             FROM acl_groups g
             WHERE g.is_active = 1
             ORDER BY g.display_name'
        );
    }

    /**
     * How many active users are actually required to acknowledge this policy.
     */
    public function getAudienceSize(int $policyId): int {
        $audience = $this->audiencePredicate('u');
        return (int)($this->db->fetchOne(
            "SELECT COUNT(*) as c FROM users u CROSS JOIN grc_policies p
             WHERE p.id = :pid AND {$audience}",
            [':pid' => $policyId]
        )['c'] ?? 0);
    }

    /**
     * Whether a user is in a policy's acknowledgment audience.
     */
    public function isInAudience(int $policyId, int $userId): bool {
        $audience = $this->audiencePredicate('u');
        return (bool)$this->db->fetchOne(
            "SELECT 1 as ok FROM users u CROSS JOIN grc_policies p
             WHERE p.id = :pid AND u.id = :uid AND {$audience}",
            [':pid' => $policyId, ':uid' => $userId]
        );
    }

    /**
     * Retire a policy: it stops being live, but every record it produced stays.
     * This is the end-of-life route for a policy people have acknowledged --
     * the attestations remain queryable, the policy drops off the default list
     * (getPolicies filters on is_active), stops appearing in anyone's pending
     * acknowledgments, and stops generating review reminders.
     *
     * Retiring a system policy is allowed and expected. is_system means "cannot
     * be destroyed", not "must stay in force".
     *
     * Returns '' on success, or 'not_found' / 'already_retired'.
     */
    public function retirePolicy(int $id): string {
        $policy = $this->db->fetchOne('SELECT id, status FROM grc_policies WHERE id = :id', [':id' => $id]);
        if (!$policy) return 'not_found';
        if ($policy['status'] === 'retired') return 'already_retired';

        $this->db->update('grc_policies', [
            'status'    => 'retired',
            'is_active' => 0,
        ], 'id = :id', [':id' => $id]);

        return '';
    }

    /**
     * Put a retired policy back into circulation. Retirement is reversible on
     * purpose -- an irreversible one would push people toward force delete,
     * which is the thing we least want them reaching for.
     *
     * The pre-retirement status is not recorded anywhere, so it is derived: a
     * policy with a published version goes back to published, anything else to
     * draft.
     *
     * Returns '' on success, or 'not_found' / 'not_retired'.
     */
    public function reinstatePolicy(int $id): string {
        $policy = $this->db->fetchOne('SELECT id, status FROM grc_policies WHERE id = :id', [':id' => $id]);
        if (!$policy) return 'not_found';
        if ($policy['status'] !== 'retired') return 'not_retired';

        $published = $this->db->fetchOne(
            'SELECT id FROM grc_policy_versions WHERE policy_id = :pid AND status = "published" LIMIT 1',
            [':pid' => $id]
        );

        $this->db->update('grc_policies', [
            'status'    => $published ? 'published' : 'draft',
            'is_active' => 1,
        ], 'id = :id', [':id' => $id]);

        return '';
    }

    /**
     * Destroy a policy that users have acknowledged, along with those
     * acknowledgments. Administrators only, and deliberately not the easy path:
     * an acknowledgment is signed evidence that a named person read a named
     * version on a date, which is usually the most valuable thing attached to a
     * policy. retirePolicy() is the answer almost every time.
     *
     * is_system is still absolute here. Force delete overrides the
     * acknowledgment guard, never the "ships with the product" guard.
     *
     * Before deleting, the policy and every acknowledgment it carries are
     * written to audit_log, so the fact that signed attestations existed -- and
     * who destroyed them -- outlives the rows themselves.
     *
     * Returns '' on success, or 'not_found' / 'system'.
     */
    public function forceDeletePolicy(int $id, int $userId, string $ipAddress, string $userAgent): string {
        $policy = $this->db->fetchOne('SELECT * FROM grc_policies WHERE id = :id', [':id' => $id]);
        if (!$policy) return 'not_found';
        if ((int)$policy['is_system'] === 1) return 'system';

        $acks = $this->db->fetchAll(
            'SELECT pa.user_id, u.full_name, u.email, pa.policy_version_id,
                    pa.acknowledged_at, pa.signature_hash
             FROM grc_policy_acknowledgments pa
             LEFT JOIN users u ON u.id = pa.user_id
             WHERE pa.policy_id = :pid
             ORDER BY pa.acknowledged_at',
            [':pid' => $id]
        );

        try {
            $this->db->insert('audit_log', [
                'user_id'    => $userId,
                'action'     => 'policy_force_delete',
                'table_name' => 'grc_policies',
                'record_id'  => $id,
                'old_values' => json_encode([
                    'policy'                => $policy,
                    'acknowledgments'       => $acks,
                    'acknowledgment_count'  => count($acks),
                ]),
                'new_values' => null,
                'ip_address' => $ipAddress,
                'user_agent' => substr($userAgent, 0, 500),
            ]);
        } catch (\Exception $e) {
            // An unwritable audit trail is a reason not to destroy the evidence.
            error_log('policy force delete aborted, audit write failed: ' . $e->getMessage());
            return 'audit_failed';
        }

        $this->db->delete('grc_policies', 'id = :id', [':id' => $id]);
        return '';
    }

    /**
     * How many acknowledgments a policy carries -- i.e. how many signed
     * attestations a force delete would destroy.
     */
    public function countAcknowledgments(int $id): int {
        return (int)($this->db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_policy_acknowledgments WHERE policy_id = :id',
            [':id' => $id]
        )['c'] ?? 0);
    }

    /**
     * Whether the delete control should be offered for a policy row. Accepts
     * either shape the pages carry: a detail row (with an 'acknowledgments'
     * array) or a list row (with an 'ack_count'). This decides what is drawn --
     * deletePolicy() re-checks both conditions against the database before
     * anything is removed.
     */
    public function canDeletePolicy(array $policy): bool {
        if (!empty($policy['is_system'])) return false;
        if (isset($policy['acknowledgments'])) return empty($policy['acknowledgments']);
        return (int)($policy['ack_count'] ?? 0) === 0;
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
