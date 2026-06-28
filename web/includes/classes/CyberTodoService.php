<?php
declare(strict_types=1);

/**
 * Cyber To-Do Service - The brain behind the TPRM team's paranoid task manager
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Consolidates all the todo item assembly, CRUD operations, and filtering logic
 * that used to live inline in cyber-todo.php. Each "source" of todo items
 * (cert expirations, SRS rescores, score drops, annual reviews, unapproved vendors,
 * untiered vendors, and custom user todos) is its own method, making it possible
 * to test and extend without scrolling through 2400 lines of mixed PHP and HTML.
 */
class CyberTodoService
{
    private Database $db;
    private SRSService $srsService;

    public function __construct(Database $db, SRSService $srsService)
    {
        $this->db = $db;
        $this->srsService = $srsService;
    }

    // ========================================================================
    // Todo Item Assembly -- each method returns items in a consistent format
    // ========================================================================

    /**
     * Gather ALL todo items from every source. This is the main event.
     * Returns a flat array of todo items, each with a consistent shape.
     */
    public function gatherAllTodoItems(): array
    {
        $items = [];

        $items = array_merge($items, $this->getExpiringCertificates());
        $items = array_merge($items, $this->getVendorsNeedingRescore());
        $items = array_merge($items, $this->getSignificantScoreDrops());
        $items = array_merge($items, $this->getAnnualReviewsDue());
        $items = array_merge($items, $this->getUnapprovedVendors());
        $items = array_merge($items, $this->getUntiredVendors());
        $items = array_merge($items, $this->getCustomTodos());
        $items = array_merge($items, $this->getExpiringContracts());

        return $items;
    }

    /**
     * SOURCE 1: ISO 27001 Certificate Expirations
     * Find any vendor assessments with certificates expiring in the next 90 days
     * (or already expired). Nothing ruins your day like a lapsed cert.
     */
    public function getExpiringCertificates(): array
    {
        $items = [];
        $expiringCerts = $this->db->fetchAll("
            SELECT a.id, a.vendor_name, a.vendor_request_id, a.certificate_expiry, t.name as template_name,
                   DATEDIFF(a.certificate_expiry, NOW()) as days_until_expiry
            FROM vendor_assessments a
            JOIN assessment_templates t ON a.template_id = t.id
            WHERE a.certificate_expiry IS NOT NULL
              AND a.certificate_expiry <= DATE_ADD(NOW(), INTERVAL 90 DAY)
              AND a.status = 'completed'
            ORDER BY a.certificate_expiry ASC
            LIMIT 100
        ");

        foreach ($expiringCerts as $cert) {
            $isExpired = $cert['days_until_expiry'] < 0;
            $items[] = [
                'type' => 'cert_expiry',
                'type_label' => 'Certificate Expiry',
                'priority' => $isExpired ? 1 : 2,
                'title' => $cert['vendor_name'],
                'description' => $isExpired
                    ? $cert['template_name'] . ' certificate expired ' . abs($cert['days_until_expiry']) . ' days ago'
                    : $cert['template_name'] . ' certificate expires in ' . $cert['days_until_expiry'] . ' days',
                'link' => 'vendor-assessment-view.php?id=' . $cert['id'],
                'reference_type' => 'vendor_assessments',
                'reference_id' => $cert['id'],
                'badge' => $isExpired ? 'Expired' : 'Expiring Soon',
                'badge_color' => $isExpired ? '#dc2626' : '#f59e0b',
                'date' => $cert['certificate_expiry']
            ];
        }

        return $items;
    }

    /**
     * SOURCE 2: Outdated SRS Scores
     * Vendors whose security scores are stale based on their tier schedule.
     * Tier 1 = monthly, Tier 2 = quarterly, Tier 3 = annually.
     */
    public function getVendorsNeedingRescore(): array
    {
        $items = [];
        $scoringConfig = $this->srsService->getScoringConfig();
        $needsRescore = $this->srsService->getVendorsNeedingRescore(50);

        foreach ($needsRescore as $vendor) {
            $daysSinceScore = !empty($vendor['last_srs_score_at'])
                ? (int) floor((time() - strtotime($vendor['last_srs_score_at'])) / 86400)
                : null;
            $items[] = [
                'type' => 'srs_rescore',
                'type_label' => 'SRS Rescore',
                'priority' => 3,
                'title' => $vendor['vendor_name'],
                'description' => $daysSinceScore
                    ? 'SRS score is ' . $daysSinceScore . ' days old (Tier ' . ($vendor['vendor_tier'] ?? '?') . ' - every ' . ($scoringConfig['tier' . $vendor['vendor_tier'] . '_days'] ?? '?') . ' days)'
                    : 'No SRS score recorded yet',
                'link' => 'vendor-srs-details.php?id=' . $vendor['id'],
                'reference_type' => 'vendor_onboarding_requests',
                'reference_id' => $vendor['id'],
                'badge' => 'Needs Rescore',
                'badge_color' => '#f59e0b',
                'date' => $vendor['last_srs_score_at']
            ];
        }

        return $items;
    }

    /**
     * SOURCE 3: Significant SRS Score Drops
     * If a vendor's score dropped 10% or more between their last two checks,
     * that's a red flag worth investigating. Maybe they got breached, maybe they
     * just let their SSL cert expire. Either way, someone should look into it.
     */
    public function getSignificantScoreDrops(): array
    {
        $items = [];
        $scoreDrops = $this->db->fetchAll("
            SELECT
                r.id, r.vendor_name,
                curr.score as current_score, curr.scored_at as current_scored_at,
                prev.score as previous_score, prev.scored_at as previous_scored_at,
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
            LIMIT 100
        ");

        foreach ($scoreDrops as $drop) {
            $items[] = [
                'type' => 'score_drop',
                'type_label' => 'Score Drop',
                'priority' => 2,
                'title' => $drop['vendor_name'],
                'description' => 'SRS score dropped ' . $drop['percent_drop'] . '% (from ' . $drop['previous_score'] . ' to ' . $drop['current_score'] . ') on ' . formatLocalTime($drop['current_scored_at'], 'M j, Y'),
                'link' => 'vendor-srs-details.php?id=' . $drop['id'],
                'reference_type' => 'vendor_onboarding_requests',
                'reference_id' => $drop['id'],
                'badge' => '-' . $drop['percent_drop'] . '%',
                'badge_color' => '#dc2626',
                'date' => $drop['current_scored_at']
            ];
        }

        return $items;
    }

    /**
     * SOURCE 4: Annual Reviews Needed
     * Vendors that haven't had their annual review in over a year.
     * Compliance requires these, and auditors WILL ask about them.
     */
    public function getAnnualReviewsDue(): array
    {
        $items = [];
        $needsReview = $this->db->fetchAll("
            SELECT r.id, r.vendor_name, r.created_at, r.last_annual_review,
                   MAX(COALESCE(stakeholder_user.full_name, owner_user.full_name, creator.full_name)) as stakeholder_name,
                   MAX(COALESCE(stakeholder_user.email, owner_user.email, creator.email)) as stakeholder_email,
                   MAX(DATEDIFF(NOW(), COALESCE(r.last_annual_review, r.created_at))) as days_since_review
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
            LIMIT 100
        ");

        foreach ($needsReview as $vendor) {
            $lastReviewDate = $vendor['last_annual_review'] ?? $vendor['created_at'];
            $items[] = [
                'type' => 'annual_review',
                'type_label' => 'Annual Review',
                'priority' => 4,
                'title' => $vendor['vendor_name'],
                'description' => 'Annual review due (' . $vendor['days_since_review'] . ' days overdue). Stakeholder: ' . ($vendor['stakeholder_name'] ?? 'Unknown') . ($vendor['stakeholder_email'] ? ' (' . $vendor['stakeholder_email'] . ')' : ''),
                'link' => 'vendor-onboarding.php?id=' . $vendor['id'],
                'reference_type' => 'vendor_onboarding_requests',
                'reference_id' => $vendor['id'],
                'badge' => 'Annual Review',
                'badge_color' => '#8b5cf6',
                'date' => $lastReviewDate,
                'can_mark_reviewed' => true
            ];
        }

        return $items;
    }

    /**
     * SOURCE 5: Vendors Stuck in the Approval Pipeline
     * Vendors that are submitted, in review, or rejected but not yet resolved.
     * The longer these sit, the more someone in procurement is getting antsy.
     */
    public function getUnapprovedVendors(): array
    {
        $items = [];
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
            LIMIT 100
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
                'type_label' => 'Vendor Approval',
                'priority' => $vendor['status'] === 'rejected' ? 2 : 3,
                'title' => $vendor['vendor_name'],
                'description' => ucfirst(str_replace('_', ' ', $vendor['status'])) . ' for ' . $vendor['days_pending'] . ' days. Stakeholder: ' . ($vendor['stakeholder_name'] ?? 'Unknown'),
                'link' => 'vendor-onboarding.php?id=' . $vendor['id'],
                'reference_type' => 'vendor_onboarding_requests',
                'reference_id' => $vendor['id'],
                'badge' => $label[0],
                'badge_color' => $label[1],
                'date' => $vendor['submitted_at'] ?? $vendor['created_at']
            ];
        }

        return $items;
    }

    /**
     * SOURCE 6: Approved Vendors Without a Tier Assignment
     * These vendors slipped through the cracks -- approved but never tiered.
     * Without a tier they won't get auto-rescored, which defeats the whole purpose.
     */
    public function getUntiredVendors(): array
    {
        $items = [];
        $notTiered = $this->db->fetchAll("
            SELECT r.id, r.vendor_name, r.created_at, r.status, r.vendor_tier,
                   MAX(COALESCE(stakeholder_user.full_name, owner_user.full_name, creator.full_name)) as stakeholder_name,
                   MAX(COALESCE(stakeholder_user.email, owner_user.email, creator.email)) as stakeholder_email,
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
            GROUP BY r.id, r.vendor_name, r.created_at, r.status, r.vendor_tier
            ORDER BY r.created_at ASC
            LIMIT 100
        ");

        foreach ($notTiered as $vendor) {
            $items[] = [
                'type' => 'not_tiered',
                'type_label' => 'Needs Tier Assignment',
                'priority' => 1,
                'title' => $vendor['vendor_name'],
                'description' => 'Vendor approved ' . $vendor['days_since_created'] . ' days ago but has not been assigned a tier. Stakeholder: ' . ($vendor['stakeholder_name'] ?? 'Unknown') . ($vendor['stakeholder_email'] ? ' (' . $vendor['stakeholder_email'] . ')' : ''),
                'link' => 'vendor-srs-details.php?id=' . $vendor['id'],
                'reference_type' => 'vendor_onboarding_requests',
                'reference_id' => $vendor['id'],
                'badge' => 'No Tier',
                'badge_color' => '#dc2626',
                'date' => $vendor['created_at']
            ];
        }

        return $items;
    }

    /**
     * SOURCE 7: Custom/User-Created Todos
     * Sometimes you just need to add your own tasks. These are free-form items
     * that users created manually, optionally linked to a specific vendor.
     */
    public function getCustomTodos(): array
    {
        $items = [];
        $customTodos = $this->db->fetchAll("
            SELECT a.*, u.full_name as created_by_name, au.full_name as assigned_to_name, v.vendor_name, v.id as vendor_id
            FROM cyber_todo_activities a
            LEFT JOIN users u ON a.created_by = u.id
            LEFT JOIN users au ON a.assigned_to = au.id
            LEFT JOIN vendor_onboarding_requests v ON a.reference_type = 'vendor_onboarding_requests' AND a.reference_id = v.id
            WHERE a.todo_type = 'custom'
              AND a.status != 'closed'
            ORDER BY a.due_date ASC, a.created_at DESC
            LIMIT 200
        ");

        foreach ($customTodos as $todo) {
            $isOverdue = !empty($todo['due_date']) && strtotime($todo['due_date']) < strtotime('today');
            $displayTitle = !empty($todo['vendor_name']) ? $todo['vendor_name'] : (!empty($todo['title']) ? $todo['title'] : 'Custom Todo');
            $badgeText = !empty($todo['title']) ? $todo['title'] : 'Open';
            if ($isOverdue) {
                $badgeText = 'Overdue - ' . $badgeText;
            }
            $items[] = [
                'type' => 'custom',
                'type_label' => 'Custom Todo',
                'priority' => $isOverdue ? 1 : 3,
                'title' => $displayTitle,
                'description' => $todo['description'] . ' - Created by ' . ($todo['created_by_name'] ?? 'Unknown'),
                'link' => !empty($todo['vendor_name']) && $todo['vendor_id'] ? 'vendor-onboarding.php?id=' . $todo['vendor_id'] : null,
                'reference_type' => 'cyber_todo_activities',
                'reference_id' => $todo['id'],
                'badge' => $badgeText,
                'badge_color' => $isOverdue ? '#dc2626' : '#8b5cf6',
                'date' => $todo['due_date'] ?? $todo['created_at'],
                'due_date' => $todo['due_date'],
                'is_overdue' => $isOverdue,
                'custom_todo_id' => $todo['id'],
                'custom_todo_status' => $todo['status'],
                'vendor_id' => $todo['vendor_id'] ?? null,
                'vendor_name' => $todo['vendor_name'] ?? null,
                'original_reference_type' => $todo['reference_type'],
                'original_reference_id' => $todo['reference_id'],
                'assigned_to' => $todo['assigned_to'] ?? null,
                'assigned_to_name' => $todo['assigned_to_name'] ?? null
            ];
        }

        return $items;
    }

    /**
     * SOURCE 8: Expiring Contracts
     * Contracts from vendor_documents that are expiring within 90 days or already expired.
     * Procurement needs to know about these so they can get renewals rolling.
     */
    public function getExpiringContracts(): array
    {
        $items = [];
        $expiringContracts = $this->db->fetchAll("
            SELECT vd.id, vd.contract_name, vd.contract_type, vd.contract_expiration_date,
                   vd.vendor_request_id,
                   r.vendor_name,
                   DATEDIFF(vd.contract_expiration_date, NOW()) as days_until_expiry
            FROM vendor_documents vd
            JOIN vendor_onboarding_requests r ON vd.vendor_request_id = r.id
            WHERE vd.document_type = 'contract'
              AND vd.is_active = 1
              AND vd.contract_expiration_date IS NOT NULL
              AND vd.contract_expiration_date <= DATE_ADD(NOW(), INTERVAL 90 DAY)
            ORDER BY vd.contract_expiration_date ASC
            LIMIT 100
        ");

        foreach ($expiringContracts as $contract) {
            $isExpired = $contract['days_until_expiry'] < 0;
            $contractName = $contract['contract_name'] ?: 'Unnamed Contract';
            $items[] = [
                'type' => 'contract_expiry',
                'type_label' => 'Contract Expiry',
                'priority' => $isExpired ? 1 : 2,
                'title' => $contract['vendor_name'],
                'description' => $isExpired
                    ? $contractName . ' expired ' . abs($contract['days_until_expiry']) . ' days ago'
                    : $contractName . ' expires in ' . $contract['days_until_expiry'] . ' days',
                'link' => 'procurement-contracts.php',
                'reference_type' => 'vendor_onboarding_requests',
                'reference_id' => $contract['vendor_request_id'],
                'badge' => $isExpired ? 'Expired' : 'Expiring Soon',
                'badge_color' => $isExpired ? '#dc2626' : '#f59e0b',
                'date' => $contract['contract_expiration_date'],
                'contract_document_id' => $contract['id'],
                'contract_name' => $contractName,
                'contract_type' => $contract['contract_type'] ?? 'N/A',
            ];
        }

        return $items;
    }

    /**
     * Reassign a case (cyber_todo_activity) to a different user.
     * Returns the updated case data for email notification purposes.
     */
    public function reassignCase(int $activityId, int $newAssigneeId, int $userId): ?array
    {
        $this->db->query(
            "UPDATE cyber_todo_activities SET assigned_to = :assigned_to, updated_at = NOW() WHERE id = :id",
            [':assigned_to' => $newAssigneeId, ':id' => $activityId]
        );

        return $this->db->fetchOne(
            "SELECT a.*, u.full_name as assigned_to_name, u.email as assigned_to_email, v.vendor_name
             FROM cyber_todo_activities a
             LEFT JOIN users u ON a.assigned_to = u.id
             LEFT JOIN vendor_onboarding_requests v ON a.reference_type = 'vendor_onboarding_requests' AND a.reference_id = v.id
             WHERE a.id = :id",
            [':id' => $activityId]
        );
    }

    // ========================================================================
    // Activity & Status Enrichment
    // ========================================================================

    /**
     * For each todo item, pull its activity history and determine open/closed status.
     * Uses a batched query (one query for all items) instead of N+1.
     */
    public function enrichWithActivities(array &$todoItems): void
    {
        if (empty($todoItems)) return;

        // Collect unique (reference_type, reference_id) pairs
        $lookupKeys = [];
        foreach ($todoItems as $item) {
            $key = $item['reference_type'] . ':' . $item['reference_id'];
            $lookupKeys[$key] = [
                'type' => $item['reference_type'],
                'id' => $item['reference_id']
            ];
        }

        // Batch-fetch all activities for all todo items in one query
        $allActivities = [];
        try {
            // Group by reference_type to build efficient queries
            $byType = [];
            foreach ($lookupKeys as $info) {
                $byType[$info['type']][] = (int)$info['id'];
            }

            foreach ($byType as $refType => $refIds) {
                if (empty($refIds)) continue;
                $placeholders = implode(',', $refIds);
                $rows = $this->db->fetchAll(
                    "SELECT a.*, u.full_name as created_by_name, cu.full_name as closed_by_name, au.full_name as assigned_to_name
                     FROM cyber_todo_activities a
                     LEFT JOIN users u ON a.created_by = u.id
                     LEFT JOIN users cu ON a.closed_by = cu.id
                     LEFT JOIN users au ON a.assigned_to = au.id
                     WHERE a.reference_type = :ref_type AND a.reference_id IN ({$placeholders})
                     ORDER BY a.created_at DESC",
                    [':ref_type' => $refType]
                );
                foreach ($rows as $row) {
                    $key = $row['reference_type'] . ':' . $row['reference_id'];
                    $allActivities[$key][] = $row;
                }
            }
        } catch (\Exception $e) {
            // Fall through -- items will get empty activities below
        }

        // Map activities back to each todo item
        foreach ($todoItems as &$item) {
            $key = $item['reference_type'] . ':' . $item['reference_id'];
            $activities = $allActivities[$key] ?? [];
            $item['activities'] = $activities;
            $openActivities = array_filter($activities, function ($a) { return $a['status'] !== 'closed'; });
            $item['has_open_activities'] = !empty($openActivities);
            $item['activity_count'] = count($activities);
            $item['open_activity_count'] = count($openActivities);

            // Bubble up assignment from the most recent activity that has one
            if (!isset($item['assigned_to'])) {
                $item['assigned_to'] = null;
                $item['assigned_to_name'] = null;
                foreach ($activities as $act) {
                    if (!empty($act['assigned_to'])) {
                        $item['assigned_to'] = $act['assigned_to'];
                        $item['assigned_to_name'] = $act['assigned_to_name'];
                        break;
                    }
                }
            }
        }
        unset($item);
    }

    // ========================================================================
    // Filtering & Sorting -- the funnel from "all items" to "what you see"
    // ========================================================================

    /**
     * Apply all user filters and sort by priority then date.
     * Like running water through increasingly fine sieves.
     */
    public function filterAndSort(array $todoItems, string $typeFilter, string $statusFilter, string $priorityFilter, string $pastDueFilter, string $assignedFilter = ''): array
    {
        // Assigned-to filter -- show only items assigned to a specific user
        if (!empty($assignedFilter)) {
            $assignedId = (int) $assignedFilter;
            $todoItems = array_filter($todoItems, function ($item) use ($assignedId) {
                // Check direct assignment on the item (custom todos, enriched items)
                if (!empty($item['assigned_to']) && (int)$item['assigned_to'] === $assignedId) {
                    return true;
                }
                // Check activities for assignment
                if (!empty($item['activities'])) {
                    foreach ($item['activities'] as $act) {
                        if (!empty($act['assigned_to']) && (int)$act['assigned_to'] === $assignedId) {
                            return true;
                        }
                    }
                }
                return false;
            });
        }

        // Type filter
        if (!empty($typeFilter)) {
            $todoItems = array_filter($todoItems, function ($item) use ($typeFilter) {
                return $item['type'] === $typeFilter;
            });
        }

        // Priority filter -- "high" means priority 1 or 2
        if ($priorityFilter === 'high') {
            $todoItems = array_filter($todoItems, function ($item) {
                return $item['priority'] <= 2;
            });
        }

        // Past due filter
        if ($pastDueFilter === '1') {
            $todoItems = array_filter($todoItems, function ($item) {
                if ($item['type'] === 'custom') {
                    return !empty($item['is_overdue']);
                }
                return !empty($item['date']) && strtotime($item['date']) < strtotime('today');
            });
        }

        // Status filter (based on activities)
        if ($statusFilter === 'open') {
            $todoItems = array_filter($todoItems, function ($item) {
                return empty($item['activities']) || $item['has_open_activities'];
            });
        } elseif ($statusFilter === 'closed') {
            $todoItems = array_filter($todoItems, function ($item) {
                return !empty($item['activities']) && !$item['has_open_activities'];
            });
        }

        // Re-index and sort: priority first, then date within each priority
        $todoItems = array_values($todoItems);
        usort($todoItems, function ($a, $b) {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] - $b['priority'];
            }
            return strtotime($a['date'] ?? '1970-01-01') - strtotime($b['date'] ?? '1970-01-01');
        });

        return $todoItems;
    }

    /**
     * Count items by type for the sidebar filter badges (e.g. "SRS Rescore (12)").
     */
    public function countByType(array $todoItems): array
    {
        $typeCounts = [];
        foreach ($todoItems as $item) {
            $type = $item['type'];
            if (!isset($typeCounts[$type])) {
                $typeCounts[$type] = ['count' => 0, 'label' => $item['type_label']];
            }
            $typeCounts[$type]['count']++;
        }
        return $typeCounts;
    }

    // ========================================================================
    // Completed Actions -- for the "Completed Actions" tab
    // ========================================================================

    /**
     * Fetch completed/closed actions. By default shows last 30 days;
     * when searching, opens the floodgates and checks all history.
     */
    public function getCompletedActions(string $searchQuery = ''): array
    {
        $searchingAllHistory = !empty($searchQuery);

        $sql = "
            SELECT a.*,
                   u.full_name as created_by_name,
                   cu.full_name as closed_by_name,
                   COALESCE(v.vendor_name, v2.vendor_name) as vendor_name,
                   COALESCE(v.vendor_domain, v2.vendor_domain) as vendor_domain,
                   COALESCE(v.id, v2.id) as vendor_id,
                   COALESCE(stakeholder_user.full_name, owner_user.full_name, creator.full_name) as stakeholder_name,
                   COALESCE(stakeholder_user.email, owner_user.email, creator.email) as stakeholder_email
            FROM cyber_todo_activities a
            LEFT JOIN users u ON a.created_by = u.id
            LEFT JOIN users cu ON a.closed_by = cu.id
            LEFT JOIN vendor_onboarding_requests v
                ON a.reference_type = 'vendor_onboarding_requests' AND a.reference_id = v.id
            LEFT JOIN vendor_assessments va
                ON a.reference_type = 'vendor_assessments' AND a.reference_id = va.id
            LEFT JOIN vendor_onboarding_requests v2
                ON va.vendor_request_id = v2.id
            LEFT JOIN users creator ON COALESCE(v.created_by, v2.created_by) = creator.id
            LEFT JOIN vendor_onboarding_stakeholders vos_stakeholder
                ON COALESCE(v.id, v2.id) = vos_stakeholder.request_id AND vos_stakeholder.role = 'stakeholder'
            LEFT JOIN users stakeholder_user ON vos_stakeholder.user_id = stakeholder_user.id
            LEFT JOIN vendor_onboarding_stakeholders vos_owner
                ON COALESCE(v.id, v2.id) = vos_owner.request_id AND vos_owner.role = 'owner'
            LEFT JOIN users owner_user ON vos_owner.user_id = owner_user.id
            WHERE a.status = 'closed'";

        $params = [];

        if ($searchingAllHistory) {
            $sql .= " AND (
                LOWER(COALESCE(v.vendor_name, v2.vendor_name, '')) LIKE :search1
                OR LOWER(COALESCE(v.vendor_domain, v2.vendor_domain, '')) LIKE :search2
                OR LOWER(COALESCE(stakeholder_user.full_name, owner_user.full_name, creator.full_name, '')) LIKE :search3
                OR LOWER(COALESCE(stakeholder_user.email, owner_user.email, creator.email, '')) LIKE :search4
            )";
            $searchTerm = '%' . strtolower($searchQuery) . '%';
            $params[':search1'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
            $params[':search4'] = $searchTerm;
        } else {
            $sql .= " AND (
                          a.activity_type = 'status_change'
                          OR (a.todo_type = 'custom' AND a.status = 'closed')
                      )
                      AND a.closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        }

        $sql .= " ORDER BY a.closed_at DESC LIMIT 200";

        $completedActions = $this->db->fetchAll($sql, $params);

        // Enrich each completed action with its related activities
        foreach ($completedActions as &$action) {
            if (!empty($action['reference_type']) && !empty($action['reference_id'])) {
                $action['related_activities'] = $this->db->fetchAll(
                    "SELECT a.*, u.full_name as created_by_name
                     FROM cyber_todo_activities a
                     LEFT JOIN users u ON a.created_by = u.id
                     WHERE a.reference_type = :ref_type
                       AND a.reference_id = :ref_id
                       AND a.id != :current_id
                     ORDER BY a.created_at ASC",
                    [
                        ':ref_type' => $action['reference_type'],
                        ':ref_id' => $action['reference_id'],
                        ':current_id' => $action['id']
                    ]
                );
            } else {
                $action['related_activities'] = [];
            }
        }
        unset($action);

        return $completedActions;
    }

    /**
     * Get recent import activities for the "Import Audit Log" section.
     */
    public function getImportActivities(int $limit = 50): array
    {
        return $this->db->fetchAll("
            SELECT a.*, u.full_name as created_by_name
            FROM cyber_todo_activities a
            LEFT JOIN users u ON a.created_by = u.id
            WHERE a.activity_type = 'import'
              AND a.todo_type IN ('user_import', 'vendor_import')
            ORDER BY a.created_at DESC
            LIMIT " . $limit);
    }

    // ========================================================================
    // CRUD Operations -- the POST handler brain
    // ========================================================================

    /**
     * Add a new activity/note to a todo item.
     */
    public function addActivity(
        string $todoType,
        string $referenceType,
        int $referenceId,
        string $activityType,
        ?string $title,
        string $description,
        string $status,
        ?string $dueDate,
        int $createdBy,
        ?int $assignedTo = null
    ): void {
        $this->db->query(
            "INSERT INTO cyber_todo_activities (todo_type, reference_type, reference_id, activity_type, title, description, status, due_date, created_by, assigned_to)
             VALUES (:todo_type, :reference_type, :reference_id, :activity_type, :title, :description, :status, :due_date, :created_by, :assigned_to)",
            [
                ':todo_type' => $todoType,
                ':reference_type' => $referenceType,
                ':reference_id' => $referenceId,
                ':activity_type' => $activityType,
                ':title' => !empty($title) ? $title : null,
                ':description' => $description,
                ':status' => $status,
                ':due_date' => $dueDate,
                ':created_by' => $createdBy,
                ':assigned_to' => $assignedTo
            ]
        );
    }

    /**
     * Update the status of an activity.
     */
    public function updateActivityStatus(int $activityId, string $newStatus, int $userId): void
    {
        $closedAt = $newStatus === 'closed' ? gmdate('Y-m-d H:i:s') : null;
        $closedBy = $newStatus === 'closed' ? $userId : null;

        $this->db->query(
            "UPDATE cyber_todo_activities SET status = :status, closed_at = :closed_at, closed_by = :closed_by WHERE id = :id",
            [':status' => $newStatus, ':closed_at' => $closedAt, ':closed_by' => $closedBy, ':id' => $activityId]
        );
    }

    /**
     * Activity types that are free-form, user-authored entries and therefore
     * editable/deletable by their author. System-generated bookkeeping rows
     * (status_change, import, revert) are deliberately excluded -- those form
     * the audit trail and must not be rewritten or removed from the UI.
     */
    private const EDITABLE_ACTIVITY_TYPES = ['note', 'action', 'reminder'];

    /**
     * Edit the description of a note/comment. Authorization is enforced HERE,
     * not just in the controller: only the original author ($userId === created_by)
     * may edit, and only free-form note types -- never a status_change/import row.
     * A closed activity is frozen. Returns true only when a row was actually
     * updated; false signals "not found / not yours / not editable" so the
     * caller can surface a permission error instead of a silent success.
     */
    public function editActivity(int $activityId, string $newDescription, int $userId): bool
    {
        $activity = $this->db->fetchOne(
            "SELECT id, created_by, activity_type, status FROM cyber_todo_activities WHERE id = :id",
            [':id' => $activityId]
        );
        if (!$activity) return false;
        if ((int)$activity['created_by'] !== $userId) return false;
        if (!in_array($activity['activity_type'], self::EDITABLE_ACTIVITY_TYPES, true)) return false;
        if ($activity['status'] === 'closed') return false;

        // The created_by guard is repeated in the WHERE clause as defense in
        // depth -- the row cannot be mutated even if the check above is bypassed.
        $this->db->query(
            "UPDATE cyber_todo_activities SET description = :description, updated_at = NOW()
             WHERE id = :id AND created_by = :uid",
            [':description' => $newDescription, ':id' => $activityId, ':uid' => $userId]
        );
        return true;
    }

    /**
     * Delete a note/comment. Same ownership + type gate as editActivity().
     * Only the author may delete, and only free-form note rows -- audit-trail
     * entries (status_change/import/revert) are never deletable from here.
     * Returns true only when a row was actually removed.
     */
    public function deleteActivity(int $activityId, int $userId): bool
    {
        $activity = $this->db->fetchOne(
            "SELECT id, created_by, activity_type FROM cyber_todo_activities WHERE id = :id",
            [':id' => $activityId]
        );
        if (!$activity) return false;
        if ((int)$activity['created_by'] !== $userId) return false;
        if (!in_array($activity['activity_type'], self::EDITABLE_ACTIVITY_TYPES, true)) return false;

        $this->db->query(
            "DELETE FROM cyber_todo_activities WHERE id = :id AND created_by = :uid",
            [':id' => $activityId, ':uid' => $userId]
        );
        return true;
    }

    /**
     * Mark a vendor as having completed their annual review. Check that box.
     */
    public function markAnnualReview(int $vendorId): void
    {
        $this->db->query(
            "UPDATE vendor_onboarding_requests SET last_annual_review = UTC_TIMESTAMP() WHERE id = :id",
            [':id' => $vendorId]
        );
    }

    // ========================================================================
    // Scheduled Actions (Action Plan tab) -- shared executor used by BOTH the
    // "Run Onboarding Scheduled Actions" cron and the manual Create To-Do modal.
    // Each supported action is tied to a vendor and, when it fires, leaves a
    // Cyber To-Do trail linking back to that vendor's Action Plan tab.
    // ========================================================================

    /** Action keys understood by executeScheduledAction(). */
    public const SCHEDULED_ACTION_LABELS = [
        'contact_vendor'      => 'Contact Vendor',
        'contact_stakeholder' => 'Contact Stakeholder',
        'send_assessment'     => 'Send Assessment',
        'force_annual_review' => 'Force Annual Review',
    ];

    /**
     * Execute one action against a vendor. Used by the cron (for a due
     * vendor_scheduled_actions row) and by the cyber-todo modal (immediately).
     *
     * $params: request_id (int), action (string key above),
     *          assessment_template_id (int|null, send_assessment only),
     *          description (string, carried into the To-Do).
     * $actorUserId: the user the action is attributed to (cron passes a system/admin id).
     *
     * Returns ['result_type'=>?string, 'result_id'=>?int, 'error'=>?string].
     * A non-null 'error' means nothing was performed (caller should mark failed).
     */
    public function executeScheduledAction(array $params, int $actorUserId): array
    {
        $requestId   = (int)($params['request_id'] ?? 0);
        $action      = (string)($params['action'] ?? '');
        $description = trim((string)($params['description'] ?? ''));
        $templateId  = !empty($params['assessment_template_id']) ? (int)$params['assessment_template_id'] : null;
        $notify      = !empty($params['notify_assignees']);
        $notifyEmailsRaw = trim((string)($params['notify_emails'] ?? ''));

        // Explicit assignees (cyber_tprm users chosen on the Action Plan).
        $assigneeIds = [];
        if (!empty($params['assignees']) && is_array($params['assignees'])) {
            foreach ($params['assignees'] as $aid) {
                $aid = (int)$aid;
                if ($aid > 0) { $assigneeIds[$aid] = $aid; }
            }
            $assigneeIds = array_values($assigneeIds);
        }

        if (!isset(self::SCHEDULED_ACTION_LABELS[$action])) {
            return ['result_type' => null, 'result_id' => null, 'error' => 'Unknown action: ' . $action];
        }
        $label = self::SCHEDULED_ACTION_LABELS[$action];

        $vendor = $this->db->fetchOne(
            "SELECT * FROM vendor_onboarding_requests WHERE id = :id",
            [':id' => $requestId]
        );
        if (!$vendor) {
            return ['result_type' => null, 'result_id' => null, 'error' => 'Vendor not found (id ' . $requestId . ')'];
        }

        $vendorName  = $vendor['vendor_name'] ?? ('Vendor #' . $requestId);
        $stakeholder = $this->resolveScheduledActionStakeholder($requestId);
        $link        = baseUrl('vendor-onboarding.php?id=' . $requestId . '&tab=action_plan');

        // Who receives the generated To-Do (and the optional email): the chosen
        // assignees, or the resolved stakeholder when none were selected.
        $recipients = [];
        if (!empty($assigneeIds)) {
            $ph = implode(',', array_fill(0, count($assigneeIds), '?'));
            foreach ($this->db->fetchAll("SELECT id, full_name, email FROM users WHERE id IN ($ph)", $assigneeIds) as $u) {
                $recipients[] = ['id' => (int)$u['id'], 'name' => $u['full_name'] ?? null, 'email' => $u['email'] ?? null];
            }
        }
        if (empty($recipients)) {
            $recipients[] = ['id' => $stakeholder['user_id'], 'name' => $stakeholder['name'], 'email' => $stakeholder['email']];
        }

        // Every generated To-Do carries the configured description plus a deep
        // link back to the vendor's Action Plan tab.
        $base = ($description !== '' ? $description : $label);
        $todoDesc = $base . "\n\nVendor: " . $vendorName . "\nAction Plan: " . $link;

        try {
            $todoType    = 'custom';
            $todoDueDate = null;
            $effectNote  = '';
            $resultType  = 'cyber_todo_activities';
            $resultId    = null;

            // Perform the action's side effect ONCE, before fanning out To-Dos.
            if ($action === 'send_assessment') {
                if (!$templateId) {
                    return ['result_type' => null, 'result_id' => null, 'error' => 'No assessment template selected'];
                }
                $res = $this->dispatchSendAssessment($vendor, $templateId, $actorUserId);
                if (!empty($res['error'])) {
                    return ['result_type' => null, 'result_id' => null, 'error' => $res['error']];
                }
                $effectNote = ($res['email_success']
                    ? 'Assessment "' . $res['assessment_name'] . '" sent to ' . $res['sent_to'] . '.'
                    : 'Assessment "' . $res['assessment_name'] . '" created (email not sent — check Email Settings). Vendor link issued.') . "\n\n";
                $resultType = 'vendor_assessments';
                $resultId   = $res['assessment_id'];
            } elseif ($action === 'force_annual_review') {
                // Force the review DUE now -- NOT markAnnualReview(), which marks it complete.
                $this->db->query(
                    "UPDATE vendor_onboarding_requests SET last_annual_review_due = CURDATE() WHERE id = :id",
                    [':id' => $requestId]
                );
                $effectNote  = 'Annual review forced due. Stakeholder: ' . ($stakeholder['name'] ?? 'Unknown') . "\n\n";
                $todoType    = 'annual_review';
                $todoDueDate = date('Y-m-d');
                $resultType  = 'vendor_onboarding_requests';
                $resultId    = $requestId;
            }

            $fullDesc = $effectNote . $todoDesc;

            // One To-Do per recipient so each shows up in their assigned queue.
            $firstTodoId = null;
            foreach ($recipients as $rcpt) {
                $this->addActivity(
                    $todoType, 'vendor_onboarding_requests', $requestId, 'action',
                    $label, $fullDesc, 'open', $todoDueDate, $actorUserId, $rcpt['id']
                );
                if ($firstTodoId === null) { $firstTodoId = (int)$this->db->lastInsertId(); }
            }
            if ($resultType === 'cyber_todo_activities' && $resultId === null) {
                $resultId = $firstTodoId;
            }

            // Optionally email about the action. An explicit notify_emails list
            // overrides the assignees' account emails; otherwise fall back to them.
            if ($notify) {
                $emailList = [];
                if ($notifyEmailsRaw !== '') {
                    foreach (explode(',', $notifyEmailsRaw) as $e) {
                        $e = trim($e);
                        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) { $emailList[$e] = $e; }
                    }
                } else {
                    foreach ($recipients as $r) {
                        if (!empty($r['email']) && filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { $emailList[$r['email']] = $r['email']; }
                    }
                }
                if (!empty($emailList)) {
                    $this->notifyScheduledActionAssignees(array_values($emailList), $label, $vendorName, $actorUserId, $link);
                }
            }

            return ['result_type' => $resultType, 'result_id' => $resultId, 'error' => null];
        } catch (Exception $e) {
            return ['result_type' => null, 'result_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Email each recipient that has an address, reusing the case-assignment
     * template. Failures are logged, never fatal to the action.
     */
    private function notifyScheduledActionAssignees(array $emailList, string $label, string $vendorName, int $actorUserId, string $link = ''): void
    {
        $emails = [];
        foreach ($emailList as $e) {
            $e = trim((string)$e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) { $emails[$e] = $e; }
        }
        if (empty($emails)) { return; }

        require_once __DIR__ . '/EmailService.php';
        require_once __DIR__ . '/Encryption.php';
        try {
            $emailService = new EmailService($this->db, new Encryption());
            if (!$emailService->isEnabled()) { return; }
            $assigner = 'Vendor Remediation Schedule';
            $actor = $this->db->fetchOne("SELECT full_name FROM users WHERE id = :id", [':id' => $actorUserId]);
            if ($actor && !empty($actor['full_name'])) { $assigner = $actor['full_name']; }
            foreach ($emails as $email) {
                try {
                    $emailService->sendCaseAssignmentNotification(
                        ['title' => $label, 'vendor_name' => $vendorName, 'url' => $link], $email, $assigner
                    );
                } catch (Exception $e) {
                    error_log('Scheduled action assignee email failed: ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('Scheduled action notify setup failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolve the human a scheduled action should target/assign to, using the
     * same precedence as getAnnualReviewsDue(): assigned stakeholder, then owner,
     * then the record creator. Returns ['user_id'=>?int,'name'=>?string,'email'=>?string].
     */
    private function resolveScheduledActionStakeholder(int $requestId): array
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(su.id, ou.id, cu.id)               AS user_id,
                    COALESCE(su.full_name, ou.full_name, cu.full_name) AS name,
                    COALESCE(su.email, ou.email, cu.email)      AS email
             FROM vendor_onboarding_requests r
             LEFT JOIN users cu ON r.created_by = cu.id
             LEFT JOIN vendor_onboarding_stakeholders vs ON r.id = vs.request_id AND vs.role = 'stakeholder'
             LEFT JOIN users su ON vs.user_id = su.id
             LEFT JOIN vendor_onboarding_stakeholders vo ON r.id = vo.request_id AND vo.role = 'owner'
             LEFT JOIN users ou ON vo.user_id = ou.id
             WHERE r.id = :id
             ORDER BY vs.assigned_at DESC, vo.assigned_at DESC
             LIMIT 1",
            [':id' => $requestId]
        );
        return [
            'user_id' => ($row && !empty($row['user_id'])) ? (int)$row['user_id'] : null,
            'name'    => $row['name'] ?? null,
            'email'   => $row['email'] ?? null,
        ];
    }

    /**
     * Create + send a Vendor Assessment to the vendor's primary contact, mirroring
     * api/send-assessment-email.php. Returns
     * ['error'=>?string,'assessment_id'=>int,'assessment_name'=>string,'sent_to'=>string,'email_success'=>bool].
     */
    private function dispatchSendAssessment(array $vendor, int $templateId, int $actorUserId): array
    {
        require_once __DIR__ . '/VendorAssessmentService.php';
        require_once __DIR__ . '/EmailService.php';
        require_once __DIR__ . '/Encryption.php';

        $template = $this->db->fetchOne(
            "SELECT id, name FROM assessment_templates WHERE id = :id AND category = 'vendor_assessment' AND is_active = 1",
            [':id' => $templateId]
        );
        if (!$template) {
            return ['error' => 'Assessment template unavailable (id ' . $templateId . ')'];
        }

        $vendorName  = $vendor['vendor_name'] ?? '';
        $contactName = !empty($vendor['primary_contact_details']) ? $vendor['primary_contact_details'] : null;
        $vendorEmail = trim((string)($vendor['primary_contact_email'] ?? ''));
        if ($vendorEmail === '' || !filter_var($vendorEmail, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Vendor has no valid primary contact email'];
        }

        $vas = new VendorAssessmentService();
        $created = $vas->createAssessment(
            $templateId, $vendorName, $vendorEmail, $contactName, $vendorEmail,
            (int)$vendor['id'], $actorUserId, 30, false
        );
        $assessmentId = (int)$created['id'];
        $uuid         = $created['uuid'];
        $expiresAt    = $created['expires_at'] ?? null;
        $assessmentUrl = baseUrl("vendor-assessment.php?token={$uuid}");

        $emailSuccess = false;
        $emailService = new EmailService($this->db, new Encryption());
        if ($emailService->isEnabled()) {
            $sent = $emailService->sendAssessmentEmail(
                $vendorEmail, $vendorName, $assessmentUrl, $contactName,
                $templateId, $contactName, $expiresAt, $template['name']
            );
            $emailSuccess = !empty($sent['success']);
            if ($emailSuccess) {
                // Record in tracking table so the reminder cron won't re-send.
                try {
                    $expiresDate = $expiresAt ? date('Y-m-d', strtotime($expiresAt)) : date('Y-m-d', strtotime('+30 days'));
                    $this->db->query(
                        "INSERT INTO vendor_assessment_reminders (assessment_id, reminder_type, expires_at, sent_at, email_sent_to, status)
                         VALUES (?, 'initial', ?, NOW(), ?, 'sent')
                         ON DUPLICATE KEY UPDATE status = 'sent', sent_at = NOW(), email_sent_to = VALUES(email_sent_to)",
                        [$assessmentId, $expiresDate, $vendorEmail]
                    );
                } catch (Exception $e) {
                    error_log('Scheduled action: assessment reminder tracking insert failed: ' . $e->getMessage());
                }
            }
        }

        return [
            'error'           => null,
            'assessment_id'   => $assessmentId,
            'assessment_name' => $template['name'],
            'sent_to'         => $vendorEmail,
            'email_success'   => $emailSuccess,
        ];
    }

    /**
     * Close/complete a todo item by adding a closure activity.
     */
    public function closeTodo(string $todoType, string $referenceType, int $referenceId, string $closureNote, int $userId): void
    {
        if ($referenceType === 'cyber_todo_activities') {
            // Custom todos get directly updated
            $this->db->query(
                "UPDATE cyber_todo_activities SET status = 'closed', closed_at = UTC_TIMESTAMP(), closed_by = :closed_by, description = CONCAT(description, '\n\n--- Closed: ', :closure_note)
                 WHERE id = :id",
                [':closed_by' => $userId, ':closure_note' => $closureNote, ':id' => $referenceId]
            );
        } else {
            // Other types get a closure activity record
            $this->db->query(
                "INSERT INTO cyber_todo_activities (todo_type, reference_type, reference_id, activity_type, description, status, closed_at, created_by, closed_by)
                 VALUES (:todo_type, :reference_type, :reference_id, 'status_change', :description, 'closed', UTC_TIMESTAMP(), :created_by, :closed_by)",
                [
                    ':todo_type' => $todoType,
                    ':reference_type' => $referenceType,
                    ':reference_id' => $referenceId,
                    ':description' => $closureNote,
                    ':created_by' => $userId,
                    ':closed_by' => $userId
                ]
            );
            // Also close any open activities for this item
            $this->db->query(
                "UPDATE cyber_todo_activities SET status = 'closed', closed_at = UTC_TIMESTAMP(), closed_by = :closed_by
                 WHERE reference_type = :ref_type AND reference_id = :ref_id AND status != 'closed'",
                [':closed_by' => $userId, ':ref_type' => $referenceType, ':ref_id' => $referenceId]
            );
        }
    }

    /**
     * Reopen a closed todo item. Everyone deserves a second chance.
     */
    public function reopenTodo(string $todoType, string $referenceType, int $referenceId, int $userId): void
    {
        if ($referenceType === 'cyber_todo_activities') {
            $this->db->query(
                "UPDATE cyber_todo_activities SET status = 'open', closed_at = NULL, closed_by = NULL WHERE id = :id",
                [':id' => $referenceId]
            );
        } else {
            $this->db->query(
                "INSERT INTO cyber_todo_activities (todo_type, reference_type, reference_id, activity_type, description, status, created_by)
                 VALUES (:todo_type, :reference_type, :reference_id, 'status_change', 'Reopened', 'open', :created_by)",
                [
                    ':todo_type' => $todoType,
                    ':reference_type' => $referenceType,
                    ':reference_id' => $referenceId,
                    ':created_by' => $userId
                ]
            );
        }
    }

    /**
     * Revert a bulk import -- the "oh no, I imported the wrong file" escape hatch.
     * Uses metadata stored in the import activity record to know what to delete.
     *
     * @return array{reverted: int, already_deleted: int, errors: string[]}
     */
    public function revertImport(int $activityId, int $userId): array
    {
        $activity = $this->db->fetchOne(
            "SELECT * FROM cyber_todo_activities WHERE id = :id AND activity_type = 'import'",
            [':id' => $activityId]
        );

        if (!$activity) {
            throw new \Exception('Import activity not found.');
        }
        if ($activity['status'] === 'reverted') {
            throw new \Exception('This import has already been reverted.');
        }

        $metadata = json_decode($activity['metadata'], true);
        $revertedCount = 0;
        $alreadyDeletedCount = 0;
        $revertErrors = [];

        $this->db->beginTransaction();

        try {
            if ($activity['todo_type'] === 'user_import' && !empty($metadata['user_ids'])) {
                foreach ($metadata['user_ids'] as $uid) {
                    try {
                        $this->db->query("DELETE FROM user_acl_groups WHERE user_id = :user_id", [':user_id' => $uid]);
                        $stmt = $this->db->query("DELETE FROM users WHERE id = :user_id", [':user_id' => $uid]);
                        if ($stmt->rowCount() > 0) {
                            $revertedCount++;
                        } else {
                            $alreadyDeletedCount++;
                        }
                    } catch (\Exception $e) {
                        $revertErrors[] = "User ID {$uid}: " . $e->getMessage();
                    }
                }
            } elseif ($activity['todo_type'] === 'vendor_import' && !empty($metadata['vendor_ids'])) {
                foreach ($metadata['vendor_ids'] as $vid) {
                    try {
                        $this->db->query("DELETE FROM vendor_onboarding_stakeholders WHERE request_id = :id", [':id' => $vid]);
                        $stmt = $this->db->query("DELETE FROM vendor_onboarding_requests WHERE id = :id", [':id' => $vid]);
                        if ($stmt->rowCount() > 0) {
                            $revertedCount++;
                        } else {
                            $alreadyDeletedCount++;
                        }
                    } catch (\Exception $e) {
                        $revertErrors[] = "Vendor ID {$vid}: " . $e->getMessage();
                    }
                }
            }

            // Mark the import activity as reverted
            $revertNote = "Reverted on " . date('Y-m-d H:i:s') . ". {$revertedCount} records deleted.";
            if ($alreadyDeletedCount > 0) {
                $revertNote .= " {$alreadyDeletedCount} records were already deleted.";
            }
            if (!empty($revertErrors)) {
                $revertNote .= " Errors: " . implode('; ', $revertErrors);
            }

            $this->db->query(
                "UPDATE cyber_todo_activities SET status = 'reverted', closed_at = UTC_TIMESTAMP(), closed_by = :closed_by,
                 description = CONCAT(description, '\n\n--- ', :revert_note) WHERE id = :id",
                [':closed_by' => $userId, ':revert_note' => $revertNote, ':id' => $activityId]
            );

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        return [
            'reverted' => $revertedCount,
            'already_deleted' => $alreadyDeletedCount,
            'errors' => $revertErrors
        ];
    }
}
