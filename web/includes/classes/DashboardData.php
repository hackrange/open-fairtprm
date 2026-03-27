<?php
declare(strict_types=1);

/**
 * Dashboard Data Assembler - The stats-gathering engine behind Mission Control
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Consolidates all the dashboard queries that used to live inline in index.php.
 * Each method is a self-contained data source: SRS stats, cyber todo items,
 * annual review countdowns, request counts, etc. Everything returns nice clean
 * arrays ready for the template to chew on. Wrapped in try/catch everywhere
 * because tables might not exist yet during fresh installs, and we'd rather
 * show a dashboard with zeros than a stack trace.
 */
class DashboardData
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * SRS Stats for the dashboard cards.
     * Returns vendor count, average score, and rescore-needed count.
     * Wrapped in a safety blanket because the SRS tables might not exist yet.
     */
    public function getSRSStats(SRSService $srsService): array
    {
        $stats = ['vendors_with_scores' => 0, 'avg_score' => 0, 'needs_rescore' => 0];

        if (!$srsService->isAvailable()) {
            return $stats;
        }

        try {
            $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM vendor_onboarding_requests WHERE current_srs_score IS NOT NULL");
            $stats['vendors_with_scores'] = $result['count'] ?? 0;

            $result = $this->db->fetchOne("SELECT AVG(current_srs_score) as avg FROM vendor_onboarding_requests WHERE current_srs_score IS NOT NULL");
            $stats['avg_score'] = round((float)($result['avg'] ?? 0));

            $needsRescore = $srsService->getVendorsNeedingRescore(100);
            $stats['needs_rescore'] = count($needsRescore);
        } catch (\Exception $e) {
            // SRS tables may not exist yet -- no biggie
        }

        return $stats;
    }

    /**
     * Cyber To-Do items for the dashboard widget.
     * Same sources as the full Cyber To-Do page but with LIMIT 10 per source
     * because the dashboard is a summary, not a novel.
     */
    public function getCyberTodoItems(?SRSService $srsService = null): array
    {
        $items = [];

        try {
            // 1. ISO 27001 Certificate Expirations (60-day window)
            $expiringCerts = $this->db->fetchAll("
                SELECT a.id, a.vendor_name, a.certificate_expiry, t.name as template_name,
                       DATEDIFF(a.certificate_expiry, NOW()) as days_until_expiry
                FROM vendor_assessments a
                JOIN assessment_templates t ON a.template_id = t.id
                WHERE a.certificate_expiry IS NOT NULL
                  AND a.certificate_expiry <= DATE_ADD(NOW(), INTERVAL 60 DAY)
                  AND a.status = 'completed'
                ORDER BY a.certificate_expiry ASC
                LIMIT 10
            ");
            foreach ($expiringCerts as $cert) {
                $isExpired = $cert['days_until_expiry'] < 0;
                $items[] = [
                    'type' => 'cert_expiry',
                    'priority' => $isExpired ? 1 : 2,
                    'title' => $cert['vendor_name'],
                    'description' => $isExpired
                        ? $cert['template_name'] . ' certificate expired ' . abs($cert['days_until_expiry']) . ' days ago'
                        : $cert['template_name'] . ' certificate expires in ' . $cert['days_until_expiry'] . ' days',
                    'link' => 'vendor-assessment-view.php?id=' . $cert['id'],
                    'badge' => $isExpired ? 'Expired' : 'Expiring Soon',
                    'badge_color' => $isExpired ? '#dc2626' : '#f59e0b',
                    'reference_type' => 'vendor_assessments',
                    'reference_id' => $cert['id'],
                ];
            }

            // 2. Outdated SRS Scores
            if ($srsService) {
                $needsRescore = $srsService->getVendorsNeedingRescore(10);
                foreach ($needsRescore as $vendor) {
                    $daysSinceScore = !empty($vendor['last_srs_score_at'])
                        ? (int) floor((time() - strtotime($vendor['last_srs_score_at'])) / 86400)
                        : null;
                    $items[] = [
                        'type' => 'srs_rescore',
                        'priority' => 3,
                        'title' => $vendor['vendor_name'],
                        'description' => $daysSinceScore
                            ? 'SRS score is ' . $daysSinceScore . ' days old (Tier ' . ($vendor['vendor_tier'] ?? '?') . ')'
                            : 'No SRS score recorded yet',
                        'link' => 'vendor-srs-details.php?id=' . $vendor['id'],
                        'badge' => 'Needs Rescore',
                        'badge_color' => '#f59e0b',
                        'reference_type' => 'vendor_onboarding_requests',
                        'reference_id' => $vendor['id']
                    ];
                }
            }

            // 3. SRS Score Drops (10% or more decrease)
            $scoreDrops = $this->db->fetchAll("
                SELECT
                    r.id, r.vendor_name,
                    curr.score as current_score, prev.score as previous_score,
                    ROUND(((prev.score - curr.score) / prev.score * 100), 1) as percent_drop
                FROM vendor_onboarding_requests r
                JOIN vendor_srs_scores curr ON r.id = curr.vendor_onboarding_id
                JOIN vendor_srs_scores prev ON r.id = prev.vendor_onboarding_id
                WHERE curr.scored_at = (
                    SELECT MAX(scored_at) FROM vendor_srs_scores WHERE vendor_onboarding_id = r.id
                )
                AND prev.scored_at = (
                    SELECT MAX(scored_at) FROM vendor_srs_scores
                    WHERE vendor_onboarding_id = r.id AND scored_at < curr.scored_at
                )
                AND prev.score > 0
                AND ((prev.score - curr.score) / prev.score * 100) >= 10
                ORDER BY percent_drop DESC
                LIMIT 10
            ");
            foreach ($scoreDrops as $drop) {
                $items[] = [
                    'type' => 'score_drop',
                    'priority' => 2,
                    'title' => $drop['vendor_name'],
                    'description' => 'SRS score dropped ' . $drop['percent_drop'] . '% (from ' . $drop['previous_score'] . ' to ' . $drop['current_score'] . ')',
                    'link' => 'vendor-srs-details.php?id=' . $drop['id'],
                    'badge' => 'Score Drop',
                    'badge_color' => '#dc2626',
                    'reference_type' => 'vendor_onboarding_requests',
                    'reference_id' => $drop['id']
                ];
            }

            // 4. Annual Reviews Due
            $needsReview = $this->db->fetchAll("
                SELECT r.id, r.vendor_name, r.created_at, r.last_annual_review,
                       MAX(COALESCE(stakeholder_user.full_name, owner_user.full_name, creator.full_name)) as stakeholder_name,
                       DATEDIFF(NOW(), COALESCE(r.last_annual_review, r.created_at)) as days_since_review
                FROM vendor_onboarding_requests r
                LEFT JOIN users creator ON r.created_by = creator.id
                LEFT JOIN vendor_onboarding_stakeholders vos_stakeholder
                    ON r.id = vos_stakeholder.request_id AND vos_stakeholder.role = 'stakeholder'
                LEFT JOIN users stakeholder_user ON vos_stakeholder.user_id = stakeholder_user.id
                LEFT JOIN vendor_onboarding_stakeholders vos_owner
                    ON r.id = vos_owner.request_id AND vos_owner.role = 'owner'
                LEFT JOIN users owner_user ON vos_owner.user_id = owner_user.id
                WHERE r.status IN ('approved', 'submitted', 'in_review')
                  AND (
                      r.last_annual_review IS NULL AND r.created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
                      OR r.last_annual_review < DATE_SUB(NOW(), INTERVAL 1 YEAR)
                  )
                GROUP BY r.id, r.vendor_name, r.created_at, r.last_annual_review
                ORDER BY days_since_review DESC
                LIMIT 10
            ");
            foreach ($needsReview as $vendor) {
                $items[] = [
                    'type' => 'annual_review',
                    'priority' => 4,
                    'title' => $vendor['vendor_name'],
                    'description' => 'Annual review due (' . $vendor['days_since_review'] . ' days since last review). Stakeholder: ' . ($vendor['stakeholder_name'] ?? 'Unknown'),
                    'link' => 'vendor-onboarding.php?id=' . $vendor['id'],
                    'badge' => 'Annual Review',
                    'badge_color' => '#8b5cf6',
                    'reference_type' => 'vendor_onboarding_requests',
                    'reference_id' => $vendor['id']
                ];
            }

            // 5. Vendors NOT Approved
            $notApproved = $this->db->fetchAll("
                SELECT r.id, r.vendor_name, r.status, r.submitted_at, r.created_at,
                       MAX(COALESCE(stakeholder_user.full_name, owner_user.full_name, creator.full_name)) as stakeholder_name,
                       MAX(DATEDIFF(NOW(), COALESCE(r.submitted_at, r.created_at))) as days_pending
                FROM vendor_onboarding_requests r
                LEFT JOIN users creator ON r.created_by = creator.id
                LEFT JOIN vendor_onboarding_stakeholders vos_stakeholder
                    ON r.id = vos_stakeholder.request_id AND vos_stakeholder.role = 'stakeholder'
                LEFT JOIN users stakeholder_user ON vos_stakeholder.user_id = stakeholder_user.id
                LEFT JOIN vendor_onboarding_stakeholders vos_owner
                    ON r.id = vos_owner.request_id AND vos_owner.role = 'owner'
                LEFT JOIN users owner_user ON vos_owner.user_id = owner_user.id
                WHERE r.status IN ('submitted', 'in_review', 'rejected')
                GROUP BY r.id, r.vendor_name, r.status, r.submitted_at, r.created_at
                ORDER BY r.submitted_at ASC
                LIMIT 10
            ");
            foreach ($notApproved as $vendor) {
                $statusLabels = [
                    'submitted' => ['Pending Review', '#3b82f6'],
                    'in_review' => ['In Review', '#f59e0b'],
                    'rejected' => ['Rejected', '#dc2626']
                ];
                $label = $statusLabels[$vendor['status']] ?? ['Unknown', '#6b7280'];
                $items[] = [
                    'type' => 'not_approved',
                    'priority' => $vendor['status'] === 'rejected' ? 2 : 3,
                    'title' => $vendor['vendor_name'],
                    'description' => ucfirst(str_replace('_', ' ', $vendor['status'])) . ' for ' . $vendor['days_pending'] . ' days. Stakeholder: ' . ($vendor['stakeholder_name'] ?? 'Unknown'),
                    'link' => 'vendor-onboarding.php?id=' . $vendor['id'],
                    'badge' => $label[0],
                    'badge_color' => $label[1],
                    'reference_type' => 'vendor_onboarding_requests',
                    'reference_id' => $vendor['id']
                ];
            }

            // 6. Vendors Not Tiered
            $notTiered = $this->db->fetchAll("
                SELECT r.id, r.vendor_name, r.created_at,
                       MAX(COALESCE(stakeholder_user.full_name, owner_user.full_name, creator.full_name)) as stakeholder_name,
                       DATEDIFF(NOW(), r.created_at) as days_since_created
                FROM vendor_onboarding_requests r
                LEFT JOIN users creator ON r.created_by = creator.id
                LEFT JOIN vendor_onboarding_stakeholders vos_stakeholder
                    ON r.id = vos_stakeholder.request_id AND vos_stakeholder.role = 'stakeholder'
                LEFT JOIN users stakeholder_user ON vos_stakeholder.user_id = stakeholder_user.id
                LEFT JOIN vendor_onboarding_stakeholders vos_owner
                    ON r.id = vos_owner.request_id AND vos_owner.role = 'owner'
                LEFT JOIN users owner_user ON vos_owner.user_id = owner_user.id
                WHERE r.status = 'approved'
                  AND (r.vendor_tier IS NULL OR r.vendor_tier = 0 OR r.vendor_tier = '' OR TRIM(r.vendor_tier) = '')
                GROUP BY r.id, r.vendor_name, r.created_at
                ORDER BY r.created_at ASC
                LIMIT 10
            ");
            foreach ($notTiered as $vendor) {
                $items[] = [
                    'type' => 'not_tiered',
                    'priority' => 1,
                    'title' => $vendor['vendor_name'],
                    'description' => 'Vendor approved ' . $vendor['days_since_created'] . ' days ago but has not been assigned a tier. Stakeholder: ' . ($vendor['stakeholder_name'] ?? 'Unknown'),
                    'link' => 'vendor-srs-details.php?id=' . $vendor['id'],
                    'badge' => 'No Tier',
                    'badge_color' => '#dc2626',
                    'reference_type' => 'vendor_onboarding_requests',
                    'reference_id' => $vendor['id']
                ];
            }

            // Sort by priority -- most urgent stuff floats to the top like cream. Or problems.
            usort($items, function ($a, $b) {
                return $a['priority'] - $b['priority'];
            });
        } catch (\Exception $e) {
            error_log('Cyber To-Do error: ' . $e->getMessage());
        }

        return $items;
    }

    /**
     * Annual review countdown -- vendors due within 30 days or already overdue.
     * Because nothing says "compliance" like a big scary number on the dashboard.
     */
    public function getAnnualReviewCount(array $user, bool $canReadAll, bool $canReadAssigned): int
    {
        try {
            $today = date('Y-m-d');
            $thirtyDaysFromNow = date('Y-m-d', strtotime('+30 days'));

            $params = [':cutoff_due' => $thirtyDaysFromNow, ':status' => 'approved'];
            $whereConditions = ['r.status = :status'];

            if (!$canReadAll && $canReadAssigned) {
                $whereConditions[] = "EXISTS (
                    SELECT 1 FROM vendor_onboarding_stakeholders s
                    WHERE s.request_id = r.id AND s.user_id = :user_id
                )";
                $params[':user_id'] = $user['id'];
            }

            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

            $countQuery = "
                SELECT COUNT(*) as count
                FROM vendor_onboarding_requests r
                {$whereClause}
                AND (
                    (r.last_annual_review_due IS NOT NULL AND r.last_annual_review_due <= :cutoff_due)
                    OR
                    (r.last_annual_review_due IS NULL AND r.last_annual_review IS NOT NULL
                     AND DATE_ADD(r.last_annual_review, INTERVAL 365 DAY) <= :cutoff_review)
                    OR
                    (r.last_annual_review_due IS NULL AND r.last_annual_review IS NULL
                     AND r.submitted_at IS NOT NULL
                     AND DATE_ADD(r.submitted_at, INTERVAL 365 DAY) <= :cutoff_submit)
                )
            ";
            $params[':cutoff_review'] = $thirtyDaysFromNow;
            $params[':cutoff_submit'] = $thirtyDaysFromNow;

            $result = $this->db->fetchOne($countQuery, $params);
            return $result['count'] ?? 0;
        } catch (\Exception $e) {
            error_log('Annual review count error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Stakeholder-specific annual review count.
     * These folks only see vendors they're assigned to, not the whole enchilada.
     */
    public function getStakeholderAnnualReviewCount(int $userId): int
    {
        try {
            $thirtyDaysFromNow = date('Y-m-d', strtotime('+30 days'));

            $result = $this->db->fetchOne("
                SELECT COUNT(*) as count
                FROM vendor_onboarding_requests r
                WHERE r.status = :status
                AND EXISTS (
                    SELECT 1 FROM vendor_onboarding_stakeholders s
                    WHERE s.request_id = r.id AND s.user_id = :user_id
                )
                AND (
                    (r.last_annual_review_due IS NOT NULL AND r.last_annual_review_due <= :cutoff_due)
                    OR
                    (r.last_annual_review_due IS NULL AND r.last_annual_review IS NOT NULL
                     AND DATE_ADD(r.last_annual_review, INTERVAL 365 DAY) <= :cutoff_review)
                    OR
                    (r.last_annual_review_due IS NULL AND r.last_annual_review IS NULL
                     AND r.submitted_at IS NOT NULL
                     AND DATE_ADD(r.submitted_at, INTERVAL 365 DAY) <= :cutoff_submit)
                )
            ", [
                ':status' => 'approved',
                ':user_id' => $userId,
                ':cutoff_due' => $thirtyDaysFromNow,
                ':cutoff_review' => $thirtyDaysFromNow,
                ':cutoff_submit' => $thirtyDaysFromNow
            ]);
            return $result['count'] ?? 0;
        } catch (\Exception $e) {
            error_log('Stakeholder annual review count error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Expiring contracts count for the Procurement sidebar badge.
     * Returns the number of active contracts expiring within 90 days or already expired.
     */
    public function getExpiringContractsCount(): int
    {
        try {
            $result = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM vendor_documents
                 WHERE document_type = 'contract' AND is_active = 1
                 AND contract_expiration_date IS NOT NULL
                 AND contract_expiration_date <= DATE_ADD(NOW(), INTERVAL 90 DAY)"
            );
            return intval($result['count'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Request counts for sidebar badges.
     * Everyone loves badge counters. They're like unread emails
     * but for vendor risk management.
     */
    public function getRequestCounts(int $userId): array
    {
        $counts = [
            'pending' => 0,
            'drafts' => 0,
            'approved' => 0,
            'tasks' => 0
        ];

        try {
            $result = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM vendor_onboarding_requests WHERE created_by = :user_id AND status IN ('submitted', 'in_review')",
                [':user_id' => $userId]
            );
            $counts['pending'] = $result['count'] ?? 0;

            $result = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM vendor_onboarding_requests WHERE created_by = :user_id AND status = 'draft'",
                [':user_id' => $userId]
            );
            $counts['drafts'] = $result['count'] ?? 0;

            $result = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM vendor_onboarding_requests WHERE created_by = :user_id AND status = 'approved'",
                [':user_id' => $userId]
            );
            $counts['approved'] = $result['count'] ?? 0;
        } catch (\Exception $e) {
            // Tables may not exist yet
        }

        return $counts;
    }

    /**
     * Vendor assessment task count with permission-based filtering.
     * Determines what assessments the user can see based on ACL.
     */
    public function getVendorTaskCount(int $userId, bool $canReadAll, bool $canReadOwn, bool $canReadAssigned): int
    {
        try {
            $where = [];
            $params = [];

            if ($canReadAll) {
                // Can see everything -- living the dream
            } elseif ($canReadOwn && $canReadAssigned) {
                $where[] = "(va.created_by = :task_user_id OR EXISTS (
                    SELECT 1 FROM vendor_onboarding_stakeholders s
                    WHERE s.request_id = va.vendor_request_id AND s.user_id = :task_user_id2
                ))";
                $params[':task_user_id'] = $userId;
                $params[':task_user_id2'] = $userId;
            } elseif ($canReadOwn) {
                $where[] = "va.created_by = :task_user_id";
                $params[':task_user_id'] = $userId;
            } elseif ($canReadAssigned) {
                $where[] = "EXISTS (
                    SELECT 1 FROM vendor_onboarding_stakeholders s
                    INNER JOIN vendor_onboarding_requests vor ON s.request_id = vor.id
                    WHERE va.vendor_request_id = vor.id AND s.user_id = :task_user_id
                )";
                $params[':task_user_id'] = $userId;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM vendor_assessments va {$whereClause}", $params);
            return $result['count'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
