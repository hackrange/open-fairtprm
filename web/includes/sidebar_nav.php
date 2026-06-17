<?php
/**
 * Shared Sidebar Navigation
 *
 * Renders the consistent left vertical navigation across all pages.
 * Uses <details>/<summary> elements for collapsible sections matching index.php.
 *
 * Usage: Set $currentPage before including this file, e.g.:
 *   $currentPage = 'srs';
 *   include __DIR__ . '/includes/sidebar_nav.php';
 *
 * Valid $currentPage values:
 *   dashboard, vendor_onboarding, vendor_onboarding_tasks, new_request,
 *   annual_reviews, open_cases, assigned_to_me_cases, closed_cases,
 *   expiring_contracts_cases, cyber_todo, cyber_todo_assigned, contracts,
 *   fair, srs, vendor_domains, 4th_party, cve_search, shadow_saas,
 *   assessments, assessments_pending, assessments_in_progress,
 *   assessments_completed, reports, profile, admin
 */

// Ensure we have the base variables
if (!isset($currentPage)) $currentPage = '';

// Get core instances if not already set
if (!isset($auth)) $auth = Auth::getInstance();
if (!isset($user)) $user = $auth->getUser();
if (!isset($theme)) $theme = getUserTheme();
if (!isset($acl)) $acl = ACL::getInstance();
$db = Database::getInstance();

// Role checks
if (!isset($isAdmin)) $isAdmin = (Session::getInstance()->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator'));
if (!isset($isProcurement)) $isProcurement = hasGroup('procurement');
if (!isset($isCyberTPRM)) $isCyberTPRM = hasGroup('cyber_tprm');
if (!isset($isAuditor)) $isAuditor = hasGroup('auditor');
if (!isset($isCyberGRC)) $isCyberGRC = hasGroup('cyber_grc');
if (!isset($isStakeholderOnly)) {
    $isStakeholderGroup = hasGroup('stakeholder');
    $isStakeholderOnly = $isStakeholderGroup && !$isAdmin && !$isProcurement && !$isCyberTPRM && !$isAuditor && !$isCyberGRC;
}
if (!isset($isProcurementOnly)) {
    $isProcurementOnly = $isProcurement && !$isAdmin && !$isCyberTPRM;
}
// GRC module visibility
if (!isset($showGRCModule)) {
    $showGRCModule = $isCyberGRC || $isAdmin || $isAuditor;
}

// Module visibility
if (!isset($showOnboarding)) {
    $showOnboarding = hasPermission('onboarding.create') ||
                      hasPermission('onboarding.read') ||
                      hasPermission('onboarding.read_own') ||
                      hasPermission('onboarding.read_assigned') ||
                      hasGroup('stakeholder') || hasGroup('procurement');
}
if (!isset($showFairModule)) {
    $showFairModule = (hasPermission('analysis.create') ||
                      hasPermission('analysis.read') ||
                      $isCyberTPRM ||
                      $isAdmin) && !$isStakeholderOnly && !$isProcurementOnly && !$isAuditor;
}
if (!isset($showSRSModule)) {
    $showSRSModule = ($isCyberTPRM || $isAdmin || $isAuditor) && !$isStakeholderOnly;
}

// SRS / Vendor Domains config
if (!isset($showVendorDomains)) {
    $showVendorDomains = false;
    $vendorDomainsDisplayName = '';
    if ($showSRSModule) {
        try {
            if (!class_exists('SRSService')) {
                require_once __DIR__ . '/classes/SRSService.php';
            }
            $_sidebarSrs = new SRSService();
            if ($_sidebarSrs->isVendorDomainsEnabled()) {
                $showVendorDomains = true;
                $_srsConfig = $_sidebarSrs->getScoringConfig();
                $vendorDomainsDisplayName = ($_srsConfig['display_name'] ?? 'UpGuard') . ' Vendor Domains';
            }
        } catch (Exception $e) {}
    }
}
if (!isset($vendorDomainsDisplayName)) $vendorDomainsDisplayName = '';

// Badge counts -- compute only if not already set
if (!isset($_sidebarDataLoaded)) {
    $_sidebarDataLoaded = true;
    $dashboardData = new DashboardData($db);

    // SRS stats for badge
    if (!isset($srsStats)) {
        $srsStats = ['vendors_with_scores' => 0, 'avg_score' => 0, 'needs_rescore' => 0];
        if ($showSRSModule) {
            try {
                if (!class_exists('SRSService')) require_once __DIR__ . '/classes/SRSService.php';
                $_srs = isset($_sidebarSrs) ? $_sidebarSrs : new SRSService();
                if ($_srs->isAvailable()) {
                    $srsStats = $dashboardData->getSRSStats($_srs);
                }
            } catch (Exception $e) {}
        }
    }

    // Cyber todo count
    if (!isset($cyberTodoItems)) {
        $cyberTodoItems = [];
        if ($showSRSModule) {
            try {
                if (!class_exists('SRSService')) require_once __DIR__ . '/classes/SRSService.php';
                $_srs = isset($_sidebarSrs) ? $_sidebarSrs : new SRSService();
                $cyberTodoItems = $dashboardData->getCyberTodoItems($_srs);
            } catch (Exception $e) {}
        }
    }

    // Annual review count
    if (!isset($annualReviewCount)) {
        $annualReviewCount = 0;
        if ($showOnboarding || $isCyberTPRM || $isAdmin) {
            $canReadAll = hasPermission('annual_review.read');
            $canReadAssigned = hasPermission('annual_review.read_assigned');
            $annualReviewCount = $dashboardData->getAnnualReviewCount($user, $canReadAll, $canReadAssigned);
        }
    }

    // Stakeholder annual review count
    if (!isset($stakeholderAnnualReviewCount)) {
        $stakeholderAnnualReviewCount = 0;
        if ($isStakeholderOnly && (hasPermission('annual_review.read_assigned') || hasGroup('stakeholder'))) {
            $stakeholderAnnualReviewCount = $dashboardData->getStakeholderAnnualReviewCount($user['id']);
        }
    }

    // Request counts (for stakeholder badges)
    if (!isset($pendingRequestCount)) {
        $pendingRequestCount = 0;
        $vendorTaskCount = 0;
        if ($showOnboarding) {
            $counts = $dashboardData->getRequestCounts($user['id']);
            $pendingRequestCount = $counts['pending'];
            $canReadAll = hasPermission('onboarding.read') || hasGroup('administrator') || hasGroup('cyber_tprm');
            $canReadOwn = hasPermission('onboarding.read_own') || hasGroup('stakeholder') || hasGroup('procurement');
            $canReadAssigned = hasPermission('onboarding.read_assigned') || hasGroup('stakeholder');
            $vendorTaskCount = $dashboardData->getVendorTaskCount($user['id'], $canReadAll, $canReadOwn, $canReadAssigned);
        }
    }
    if (!isset($vendorTaskCount)) $vendorTaskCount = 0;

    // Expiring contracts count
    if (!isset($expiringContractsCount)) {
        $expiringContractsCount = 0;
        if ($isAdmin || $isProcurement || $isCyberTPRM || $isAuditor) {
            $expiringContractsCount = $dashboardData->getExpiringContractsCount();
        }
    }

    // Cyber-todo assigned-to-me count
    if (!isset($cyberTodoAssignedCount)) {
        $cyberTodoAssignedCount = 0;
        if ($isCyberTPRM || $isAdmin || $isAuditor) {
            try {
                $cyberTodoAssignedCount = intval($db->fetchOne(
                    "SELECT COUNT(DISTINCT CONCAT(a.reference_type, ':', a.reference_id)) as c
                     FROM cyber_todo_activities a
                     WHERE a.assigned_to = :uid AND a.status IN ('open','in_progress')",
                    [':uid' => $user['id']]
                )['c'] ?? 0);
            } catch (Exception $e) {}
        }
    }

    // Case management counts
    if (!isset($openCasesCount)) {
        $openCasesCount = 0;
        $closedCasesCount = 0;
        $assignedToMeCasesCount = 0;
        try {
            if ($isAdmin || $isProcurement || $isCyberTPRM) {
                $openCasesCount = intval($db->fetchOne(
                    "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND status IN ('open','in_progress') AND (parent_id IS NULL OR parent_id = 0)"
                )['c'] ?? 0);
                $closedCasesCount = intval($db->fetchOne(
                    "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND status='closed' AND (parent_id IS NULL OR parent_id = 0)"
                )['c'] ?? 0);
            } elseif ($isStakeholderOnly) {
                $stakeholderVendorIds = $db->fetchAll(
                    "SELECT request_id FROM vendor_onboarding_stakeholders WHERE user_id = :uid",
                    [':uid' => $user['id']]
                );
                if (!empty($stakeholderVendorIds)) {
                    $ids = array_column($stakeholderVendorIds, 'request_id');
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $openCasesCount = intval($db->fetchOne(
                        "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND reference_id IN ($placeholders) AND status IN ('open','in_progress') AND (parent_id IS NULL OR parent_id = 0)", $ids
                    )['c'] ?? 0);
                    $closedCasesCount = intval($db->fetchOne(
                        "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND reference_id IN ($placeholders) AND status='closed' AND (parent_id IS NULL OR parent_id = 0)", $ids
                    )['c'] ?? 0);
                }
            }
            $assignedToMeCasesCount = intval($db->fetchOne(
                "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND assigned_to = :uid AND status IN ('open','in_progress') AND (parent_id IS NULL OR parent_id = 0)",
                [':uid' => $user['id']]
            )['c'] ?? 0);
        } catch (Exception $e) {}
    }
    if (!isset($closedCasesCount)) $closedCasesCount = 0;
    if (!isset($assignedToMeCasesCount)) $assignedToMeCasesCount = 0;

    // Shadow SaaS pending count
    if (!isset($shadowSaasCount)) {
        $shadowSaasCount = 0;
        if ($isAdmin || $isCyberTPRM || $isAuditor) {
            try {
                $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM shadow_saas WHERE status = 'pending'");
                $shadowSaasCount = (int)($r['cnt'] ?? 0);
            } catch (Exception $e) {
                // Auto-create table if missing
                try {
                    $db->query("CREATE TABLE IF NOT EXISTS shadow_saas (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        vendor_name VARCHAR(500) NOT NULL,
                        vendor_domain VARCHAR(255) DEFAULT NULL,
                        relationship_manager VARCHAR(255) DEFAULT NULL,
                        number_of_users INT UNSIGNED DEFAULT NULL,
                        current_srs_score INT DEFAULT NULL,
                        last_srs_score_at DATETIME DEFAULT NULL,
                        current_shodan_score INT DEFAULT NULL,
                        last_shodan_score_at DATETIME DEFAULT NULL,
                        rescore_status VARCHAR(30) DEFAULT NULL,
                        rescore_started_at DATETIME DEFAULT NULL,
                        rescore_result TEXT DEFAULT NULL,
                        status ENUM('pending', 'onboarded', 'dismissed') DEFAULT 'pending',
                        onboarded_vendor_id INT UNSIGNED DEFAULT NULL,
                        onboarded_at DATETIME DEFAULT NULL,
                        onboarded_by INT UNSIGNED DEFAULT NULL,
                        created_by INT UNSIGNED NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_status (status),
                        INDEX idx_vendor_name (vendor_name),
                        INDEX idx_vendor_domain (vendor_domain),
                        INDEX idx_created_by (created_by)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                } catch (Exception $e2) {}
            }
        }
    }

    // Breach alert count
    if (!isset($breachAlertCount)) {
        $breachAlertCount = 0;
        if ($isAdmin || $isCyberTPRM || $isAuditor) {
            try {
                $r = $db->fetchOne("SELECT COUNT(*) as cnt FROM cyber_breach_alerts WHERE status = 'new'");
                $breachAlertCount = (int)($r['cnt'] ?? 0);
            } catch (Exception $e) {}
        }
    }

    // GRC task count for current user
    if (!isset($grcMyTaskCount)) {
        $grcMyTaskCount = 0;
        try {
            $r = $db->fetchOne(
                "SELECT COUNT(*) as c FROM grc_assessment_tasks WHERE assigned_to = :uid AND status IN ('open', 'in_progress')",
                [':uid' => $user['id']]
            );
            $grcMyTaskCount = (int)($r['c'] ?? 0);
        } catch (Exception $e) {}
    }

    // GRC counts
    if (!isset($grcOpenFindings)) {
        $grcOpenFindings = 0;
        $grcPoliciesDueReview = 0;
        $grcEvidenceExpiring = 0;
        $grcMonitorsFailing = 0;
        if ($showGRCModule) {
            try {
                $r = $db->fetchOne("SELECT COUNT(*) as c FROM grc_audit_findings WHERE status IN ('open', 'in_remediation')");
                $grcOpenFindings = (int)($r['c'] ?? 0);
            } catch (Exception $e) {}
            try {
                $r = $db->fetchOne("SELECT COUNT(*) as c FROM grc_policies WHERE is_active = 1 AND next_review_date IS NOT NULL AND next_review_date <= CURDATE()");
                $grcPoliciesDueReview = (int)($r['c'] ?? 0);
            } catch (Exception $e) {}
            try {
                $r = $db->fetchOne("SELECT COUNT(*) as c FROM grc_evidence WHERE status = 'current' AND valid_until IS NOT NULL AND valid_until BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)");
                $grcEvidenceExpiring = (int)($r['c'] ?? 0);
            } catch (Exception $e) {}
            try {
                $r = $db->fetchOne("SELECT COUNT(*) as c FROM grc_continuous_monitors WHERE is_enabled = 1 AND last_result IN ('fail', 'error')");
                $grcMonitorsFailing = (int)($r['c'] ?? 0);
            } catch (Exception $e) {}
        }
    }

    // GRC gap count (non-conforming/partial responses from the latest active assessment)
    if (!isset($grcGapCount)) {
        $grcGapCount = 0;
        if ($showGRCModule) {
            try {
                $latestAssessment = $db->fetchOne("SELECT id FROM grc_assessments WHERE status = 'active' ORDER BY created_at DESC LIMIT 1");
                if ($latestAssessment) {
                    $r = $db->fetchOne(
                        "SELECT COUNT(*) as c FROM grc_assessment_responses WHERE assessment_id = :aid AND conformity_status IN ('non_conforming', 'partial')",
                        [':aid' => $latestAssessment['id']]
                    );
                    $grcGapCount = (int)($r['c'] ?? 0);
                }
            } catch (Exception $e) {}
        }
    }

    // Expiring contract cases count
    if (!isset($expiringContractCasesCount)) {
        $expiringContractCasesCount = 0;
        try {
            if ($isAdmin || $isProcurement || $isCyberTPRM) {
                $expiringContractCasesCount = intval($db->fetchOne(
                    "SELECT COUNT(*) as c FROM vendor_documents
                     WHERE document_type = 'contract' AND is_active = 1
                       AND contract_expiration_date IS NOT NULL
                       AND contract_expiration_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"
                )['c'] ?? 0);
            }
        } catch (Exception $e) {}
    }
}

// Helper to check active state
$_isActive = function($page) use ($currentPage) {
    if (is_array($page)) return in_array($currentPage, $page);
    return $currentPage === $page;
};
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <a href="index.php">
            <img src="<?php echo e($theme['logo_url']); ?>" alt="Logo"/>
        </a>
        <div class="brand-title"><?php echo e(t('nav.brand')); ?></div>
    </div>

    <div class="sidebar-content">
    <?php if ($isStakeholderOnly): ?>
    <!-- Simplified sidebar for stakeholder-only users -->
    <details class="sidebar-section" open>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.vendor_onboarding')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="index.php"<?php echo $_isActive('dashboard') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/home-03.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.dashboard')); ?></span>
                </a>
            </li>
            <li>
                <a href="vendor-onboarding-list.php"<?php echo $_isActive('vendor_onboarding') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/book-open-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.my_vendors')); ?></span>
                    <?php if ($pendingRequestCount > 0): ?>
                    <span class="badge"><?php echo $pendingRequestCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="vendor-onboarding-list.php?view=tasks"<?php echo $_isActive('vendor_onboarding_tasks') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/book-open-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.vendor_tasks')); ?></span>
                    <?php if ($vendorTaskCount > 0): ?>
                    <span class="badge"><?php echo $vendorTaskCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php if (hasPermission('annual_review.read_assigned') || hasGroup('stakeholder')): ?>
            <li>
                <a href="vendor-annual-reviews-list.php?filter=overdue"<?php echo $_isActive('annual_reviews') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/calendar-check-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.required_annual_review')); ?></span>
                    <?php if ($stakeholderAnnualReviewCount > 0): ?>
                    <span class="badge"><?php echo $stakeholderAnnualReviewCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <li>
                <a href="vendor-onboarding.php"<?php echo $_isActive('new_request') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/plus-square.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.new_request')); ?></span>
                </a>
            </li>
        </ul>
    </details>

    <details class="sidebar-section"<?php echo $_isActive(['open_cases', 'assigned_to_me_cases', 'closed_cases', 'expiring_contracts_cases', 'cyber_todo', 'cyber_todo_assigned']) ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.case_management')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="vendor-onboarding-list.php?view=open_cases"<?php echo $_isActive('open_cases') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/edit-04.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.opened_cases')); ?></span>
                    <?php if ($openCasesCount > 0): ?>
                    <span class="badge"><?php echo $openCasesCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="vendor-onboarding-list.php?view=assigned_to_me"<?php echo $_isActive('assigned_to_me_cases') ? ' class="active"' : ''; ?> style="padding-left: 32px;">
                    <span class="icon"><img src="app/icons/user-left-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.assigned_to_me')); ?></span>
                    <?php if ($assignedToMeCasesCount > 0): ?>
                    <span class="badge"><?php echo $assignedToMeCasesCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="vendor-onboarding-list.php?view=closed_cases"<?php echo $_isActive('closed_cases') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/check-square-broken.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.closed_cases')); ?></span>
                    <?php if ($closedCasesCount > 0): ?>
                    <span class="badge"><?php echo $closedCasesCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php if ($isAdmin || $isProcurement || $isCyberTPRM || $isAuditor): ?>
            <li>
                <a href="vendor-onboarding-list.php?view=expiring_contracts"<?php echo $_isActive('expiring_contracts_cases') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/file-06.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.expiring_contracts')); ?></span>
                    <?php if ($expiringContractCasesCount > 0): ?>
                    <span class="badge"><?php echo $expiringContractCasesCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($isCyberTPRM || $isAdmin || $isAuditor): ?>
            <li>
                <a href="cyber-todo.php"<?php echo $_isActive('cyber_todo') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/check-square-broken.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.cyber_todo')); ?></span>
                    <?php if (!empty($cyberTodoItems)): ?>
                    <span class="badge"><?php echo count($cyberTodoItems); ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="cyber-todo.php?assigned=<?php echo $user['id']; ?>"<?php echo $_isActive('cyber_todo_assigned') ? ' class="active"' : ''; ?> style="padding-left: 32px;">
                    <span class="icon"><img src="app/icons/user-left-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.assigned_to_me')); ?></span>
                    <?php if ($cyberTodoAssignedCount > 0): ?>
                    <span class="badge"><?php echo $cyberTodoAssignedCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($grcMyTaskCount > 0): ?>
            <li>
                <a href="grc-tasks.php"<?php echo $_isActive('grc_tasks') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/calendar-check-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.grc_task_inbox')); ?></span>
                    <span class="badge"><?php echo $grcMyTaskCount; ?></span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </details>

    <details class="sidebar-section"<?php echo $_isActive('profile') ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.account')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="profile.php"<?php echo $_isActive('profile') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/image-user.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.my_profile')); ?></span>
                </a>
            </li>
        </ul>
    </details>
    <?php else: ?>
    <!-- Full sidebar for admins, procurement, cyber tprm, cyber grc -->
    <style>
    .sidebar-module + .sidebar-module {
        border-top: 2px solid var(--theme-button-color, #3c3c39);
    }
    .sidebar-module { border-top: 1px solid rgba(255,255,255,0.08); margin-top: 4px; }
    .sidebar-module:first-of-type { margin-top: 0; border-top: none; }
    .sidebar-module > summary.sidebar-module-header { display: flex; align-items: center; padding: 12px 20px 8px; cursor: pointer; list-style: none !important; list-style-type: none !important; user-select: none; }
    .sidebar-module > summary.sidebar-module-header::-webkit-details-marker { display: none !important; }
    .sidebar-module > summary.sidebar-module-header::marker { display: none !important; content: none !important; font-size: 0; }
    .sidebar-module > summary.sidebar-module-header::before,
    .sidebar-module > summary.sidebar-module-header::after { content: none !important; display: none !important; }
    .sidebar-module-header .module-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--theme-button-color, #ff6543); opacity: 1; transition: opacity 0.2s; }
    .sidebar-module-header:hover .module-label { opacity: 0.8; }
    .sidebar-module-body { padding-top: 0; padding-bottom: 4px; position: relative; }
    .sidebar-module-body::before { content: ""; display: block; height: 2px; background: var(--theme-button-color, #ff6543); margin: 0 20px 8px; opacity: 0.5; border-radius: 1px; }
    .sidebar-module .sidebar-nav li a { font-size: 12px; padding-top: 9px; padding-bottom: 9px; }
    .sidebar-module .sidebar-section { margin-bottom: 16px; }
    .sidebar-module .sidebar-section:first-child { margin-top: 4px; }
    .sidebar-module .sidebar-section-title { font-size: 9px; }
    .sidebar-nav li a .badge-danger { background: #dc3545; color: #fff; }
    </style>

    <!-- ═══════════ TPRM Module (collapsible) ═══════════ -->
    <details class="sidebar-module" id="module-tprm" open>
        <summary class="sidebar-module-header">
            <span class="module-label"><?php echo e(t('nav.module.tprm')); ?></span>
            <?php if ($breachAlertCount > 0): ?>
            <span style="background:#dc2626;color:#fff;font-size:10px;padding:2px 7px;border-radius:10px;margin-left:6px;font-weight:600;"><?php echo $breachAlertCount; ?> Breach<?php echo $breachAlertCount > 1 ? 'es' : ''; ?></span>
            <?php endif; ?>
        </summary>
        <div class="sidebar-module-body">

    <!-- Breach / Cyber Alerts (prominent, above Stakeholders) -->
    <?php if ($isAdmin || $isCyberTPRM || $isAuditor): ?>
    <details class="sidebar-section"<?php echo $_isActive('breach_alerts') || $breachAlertCount > 0 ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.breach_alerts')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="breach-alerts.php"<?php echo $_isActive('breach_alerts') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/announcement-03.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.breach_alerts')); ?></span>
                    <?php if ($breachAlertCount > 0): ?>
                    <span class="badge" style="background:#dc2626;"><?php echo $breachAlertCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </details>
    <?php endif; ?>

    <?php if ($showOnboarding): ?>
    <details class="sidebar-section"<?php echo $_isActive(['vendor_onboarding', 'vendor_onboarding_tasks', 'new_request']) ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.stakeholders')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="vendor-onboarding-list.php"<?php echo $_isActive('vendor_onboarding') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/book-open-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.vendor_onboarding')); ?></span>
                </a>
            </li>
            <?php if (!$isAuditor || $isStakeholderGroup): ?>
            <li>
                <a href="vendor-onboarding.php"<?php echo $_isActive('new_request') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/plus-square.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.new_request')); ?></span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </details>
    <?php endif; ?>

    <!-- Annual Reviews Section -->
    <?php if ($showOnboarding || $isCyberTPRM || $isAdmin): ?>
    <details class="sidebar-section"<?php echo $_isActive('annual_reviews') ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.annual_reviews')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="vendor-annual-reviews-list.php"<?php echo $_isActive('annual_reviews') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/calendar-check-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.vendor_reviews')); ?></span>
                    <?php if ($annualReviewCount > 0): ?>
                    <span class="badge"><?php echo $annualReviewCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </details>
    <?php endif; ?>

    <details class="sidebar-section"<?php echo $_isActive(['open_cases', 'assigned_to_me_cases', 'closed_cases', 'expiring_contracts_cases', 'cyber_todo', 'cyber_todo_assigned']) ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.case_management')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="vendor-onboarding-list.php?view=open_cases"<?php echo $_isActive('open_cases') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/edit-04.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.opened_cases')); ?></span>
                    <?php if ($openCasesCount > 0): ?>
                    <span class="badge"><?php echo $openCasesCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="vendor-onboarding-list.php?view=assigned_to_me"<?php echo $_isActive('assigned_to_me_cases') ? ' class="active"' : ''; ?> style="padding-left: 32px;">
                    <span class="icon"><img src="app/icons/user-left-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.assigned_to_me')); ?></span>
                    <?php if ($assignedToMeCasesCount > 0): ?>
                    <span class="badge"><?php echo $assignedToMeCasesCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="vendor-onboarding-list.php?view=closed_cases"<?php echo $_isActive('closed_cases') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/check-square-broken.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.closed_cases')); ?></span>
                    <?php if ($closedCasesCount > 0): ?>
                    <span class="badge"><?php echo $closedCasesCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php if ($isAdmin || $isProcurement || $isCyberTPRM || $isAuditor): ?>
            <li>
                <a href="vendor-onboarding-list.php?view=expiring_contracts"<?php echo $_isActive('expiring_contracts_cases') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/file-06.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.expiring_contracts')); ?></span>
                    <?php if ($expiringContractCasesCount > 0): ?>
                    <span class="badge"><?php echo $expiringContractCasesCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($isCyberTPRM || $isAdmin || $isAuditor): ?>
            <li>
                <a href="cyber-todo.php"<?php echo $_isActive('cyber_todo') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/check-square-broken.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.cyber_todo')); ?></span>
                    <?php if (!empty($cyberTodoItems)): ?>
                    <span class="badge"><?php echo count($cyberTodoItems); ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="cyber-todo.php?assigned=<?php echo $user['id']; ?>"<?php echo $_isActive('cyber_todo_assigned') ? ' class="active"' : ''; ?> style="padding-left: 32px;">
                    <span class="icon"><img src="app/icons/user-left-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.assigned_to_me')); ?></span>
                    <?php if ($cyberTodoAssignedCount > 0): ?>
                    <span class="badge"><?php echo $cyberTodoAssignedCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($grcMyTaskCount > 0 && !$showGRCModule): ?>
            <li>
                <a href="grc-tasks.php"<?php echo $_isActive('grc_tasks') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/calendar-check-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.grc_task_inbox')); ?></span>
                    <span class="badge"><?php echo $grcMyTaskCount; ?></span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </details>

    <?php if ($isAdmin || $isProcurement || $isCyberTPRM || $isAuditor): ?>
    <details class="sidebar-section"<?php echo $_isActive(['contracts', 'cyber_status']) ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.procurement')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="procurement-contracts.php"<?php echo $_isActive('contracts') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/file-06.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.contracts')); ?></span>
                    <?php if ($expiringContractsCount > 0): ?>
                    <span class="badge"><?php echo $expiringContractsCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php if ($isAdmin || $isProcurement || $isCyberTPRM): ?>
            <li>
                <a href="procurement-cyber-status.php"<?php echo $_isActive('cyber_status') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/file-shield-02.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.cyber_status')); ?></span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </details>
    <?php endif; ?>

    <?php if ($showFairModule || $showSRSModule): ?>
    <details class="sidebar-section"<?php echo $_isActive(['fair', 'srs', 'vendor_domains', '4th_party', 'cve_search', 'shadow_saas']) ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.modules')); ?></summary>
        <ul class="sidebar-nav">
            <?php if ($showFairModule): ?>
            <li>
                <a href="fair_dashboard.php"<?php echo $_isActive('fair') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/file-07.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.fair_analysis')); ?></span>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($showSRSModule): ?>
            <li>
                <a href="vendor-srs-list.php"<?php echo $_isActive('srs') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/file-shield-02.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.srs_scoring')); ?></span>
                    <?php if ($srsStats['needs_rescore'] > 0): ?>
                    <span class="badge"><?php echo $srsStats['needs_rescore']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($showVendorDomains): ?>
            <li>
                <a href="vendor-domains.php"<?php echo $_isActive('vendor_domains') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/globe-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e($vendorDomainsDisplayName); ?></span>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($showSRSModule): ?>
            <li>
                <a href="fourth-party-risk.php"<?php echo $_isActive('4th_party') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/dice-6.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.fourth_party_risk')); ?></span>
                </a>
            </li>
            <li>
                <a href="fourth-party-risk.php?mode=cve"<?php echo $_isActive('cve_search') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/search-refraction.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.cve_search')); ?></span>
                </a>
            </li>
            <li>
                <a href="fourth-party-risk.php?mode=subprocessors"<?php echo $_isActive('subprocessors') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/users-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.subprocessors')); ?></span>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($isAdmin || $isCyberTPRM || $isAuditor): ?>
            <li>
                <a href="shadow-saas.php"<?php echo $_isActive('shadow_saas') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/alert-square.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.shadow_saas')); ?></span>
                    <?php if ($shadowSaasCount > 0): ?>
                    <span class="badge"><?php echo $shadowSaasCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </details>
    <?php endif; ?>

    <?php if ($isAdmin || $isCyberTPRM || $isAuditor || (!$isProcurementOnly && $isProcurement) || $isStakeholderGroup): ?>
    <details class="sidebar-section"<?php echo $_isActive(['assessments', 'assessments_pending', 'assessments_in_progress', 'assessments_completed']) ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.vendor_assessments')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="vendor-assessments.php"<?php echo $_isActive('assessments') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/file-shield-02.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.all_assessments')); ?></span>
                </a>
            </li>
            <li>
                <a href="vendor-assessments.php?status=pending"<?php echo $_isActive('assessments_pending') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/calendar-date.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.pending')); ?></span>
                </a>
            </li>
            <li>
                <a href="vendor-assessments.php?status=in_progress"<?php echo $_isActive('assessments_in_progress') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/hourglass-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.in_progress')); ?></span>
                </a>
            </li>
        </ul>
    </details>
    <?php endif; ?>

    </div><!-- /.sidebar-module-body -->
    </details><!-- /#module-tprm -->

    <?php if ($showGRCModule): ?>
    <!-- ═══════════ GRC Module (collapsible) ═══════════ -->
    <details class="sidebar-module" id="module-grc">
        <summary class="sidebar-module-header">
            <span class="module-label"><?php echo e(t('nav.module.grc')); ?></span>
        </summary>
        <div class="sidebar-module-body">

    <details class="sidebar-section"<?php echo $_isActive(['grc_dashboard', 'grc_frameworks', 'grc_controls', 'grc_crosswalk']) ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.compliance')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="grc-dashboard.php"<?php echo $_isActive('grc_dashboard') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/home-03.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.grc_dashboard')); ?></span>
                </a>
            </li>
            <li>
                <a href="grc-frameworks.php"<?php echo $_isActive('grc_frameworks') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/file-shield-02.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.frameworks')); ?></span>
                </a>
            </li>
            <li>
                <a href="grc-controls.php"<?php echo $_isActive('grc_controls') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/key-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.internal_controls')); ?></span>
                </a>
            </li>
            <li>
                <a href="grc-crosswalk.php"<?php echo $_isActive('grc_crosswalk') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/globe-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.framework_crosswalk')); ?></span>
                </a>
            </li>
        </ul>
    </details>

    <details class="sidebar-section"<?php echo $_isActive(['grc_evidence', 'grc_monitors']) ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.evidence_monitoring')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="grc-evidence.php"<?php echo $_isActive('grc_evidence') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/file-07.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.evidence_library')); ?></span>
                    <?php if ($grcEvidenceExpiring > 0): ?>
                    <span class="badge"><?php echo $grcEvidenceExpiring; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="grc-monitors.php"<?php echo $_isActive('grc_monitors') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/search-refraction.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.continuous_monitors')); ?></span>
                    <?php if ($grcMonitorsFailing > 0): ?>
                    <span class="badge badge-danger"><?php echo $grcMonitorsFailing; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </details>

    <details class="sidebar-section"<?php echo $_isActive(['grc_policies']) ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.policy_management')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="grc-policies.php"<?php echo $_isActive('grc_policies') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/book-open-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.policies')); ?></span>
                    <?php if ($grcPoliciesDueReview > 0): ?>
                    <span class="badge"><?php echo $grcPoliciesDueReview; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </details>

    <details class="sidebar-section"<?php echo $_isActive(['grc_assessment', 'grc_tasks', 'grc_audits', 'grc_findings', 'grc_risks', 'grc_gaps']) ? ' open' : ''; ?>>
        <summary class="sidebar-section-title"><?php echo e(t('nav.section.assessment_audit')); ?></summary>
        <ul class="sidebar-nav">
            <li>
                <a href="grc-fairscore.php"<?php echo $_isActive('grc_fairscore') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/csf-maturity.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.csf_maturity_score')); ?></span>
                </a>
            </li>
            <li>
                <a href="grc-assessment.php"<?php echo $_isActive('grc_assessment') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/check-square-broken.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.assessment_questionnaire')); ?></span>
                </a>
            </li>
            <li>
                <a href="grc-tasks.php"<?php echo $_isActive('grc_tasks') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/calendar-check-01.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.task_inbox')); ?></span>
                    <?php if ($grcMyTaskCount > 0): ?>
                    <span class="badge"><?php echo $grcMyTaskCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="grc-audits.php"<?php echo $_isActive('grc_audits') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/check-square-broken.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.audits')); ?></span>
                    <?php if ($grcOpenFindings > 0): ?>
                    <span class="badge"><?php echo $grcOpenFindings; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="grc-risks.php"<?php echo $_isActive('grc_risks') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/alert-square.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.risk_register')); ?></span>
                </a>
            </li>
            <li>
                <a href="grc-gaps.php"<?php echo $_isActive('grc_gaps') ? ' class="active"' : ''; ?>>
                    <span class="icon"><img src="app/icons/search-refraction.svg" alt="" width="18" height="18"></span>
                    <span><?php echo e(t('nav.gaps')); ?></span>
                    <?php if ($grcGapCount > 0): ?>
                    <span class="badge"><?php echo $grcGapCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </details>

    </div><!-- /.sidebar-module-body -->
    </details><!-- /#module-grc -->
    <?php endif; ?>

    <!-- ═══════════ Utility Links ═══════════ -->
    <ul class="sidebar-nav" style="margin-top: 8px;">
        <li>
            <a href="index.php"<?php echo $_isActive('dashboard') ? ' class="active"' : ''; ?>>
                <span class="icon"><img src="app/icons/home-03.svg" alt="" width="18" height="18"></span>
                <span><?php echo e(t('nav.dashboard')); ?></span>
            </a>
        </li>
        <li>
            <a href="profile.php"<?php echo $_isActive('profile') ? ' class="active"' : ''; ?>>
                <span class="icon"><img src="app/icons/image-user.svg" alt="" width="18" height="18"></span>
                <span><?php echo e(t('nav.profile')); ?></span>
            </a>
        </li>
        <?php if ($isAdmin): ?>
        <li>
            <a href="documentation.php"<?php echo $_isActive('documentation') ? ' class="active"' : ''; ?>>
                <span class="icon"><img src="app/icons/book-open-01.svg" alt="" width="18" height="18"></span>
                <span><?php echo e(t('nav.documentation')); ?></span>
            </a>
        </li>
        <li>
            <a href="admin.php"<?php echo $_isActive('admin') ? ' class="active"' : ''; ?>>
                <span class="icon"><img src="app/icons/key-01.svg" alt="" width="18" height="18"></span>
                <span><?php echo e(t('nav.administration')); ?></span>
            </a>
        </li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>
    </div><!-- /.sidebar-content -->
</aside>
<script nonce="<?php echo cspNonce(); ?>">
// Inline sidebar SVG icons so stroke="currentColor" inherits --nav-font-color
document.querySelectorAll('.sidebar .icon img').forEach(function(img) {
    fetch(img.getAttribute('src')).then(function(r){return r.text()}).then(function(svg) {
        var span = document.createElement('span');
        span.innerHTML = svg.trim();
        var el = span.querySelector('svg');
        if (el) {
            el.setAttribute('width', img.getAttribute('width') || '18');
            el.setAttribute('height', img.getAttribute('height') || '18');
            el.removeAttribute('class');
            img.parentNode.replaceChild(el, img);
        }
    });
});

// Module collapse state — persists via localStorage
(function() {
    var KEY = 'sidebar_modules';
    var state = {};
    try { state = JSON.parse(localStorage.getItem(KEY)) || {}; } catch(e) {}

    document.querySelectorAll('.sidebar-module').forEach(function(mod) {
        var id = mod.id.replace('module-', '');
        if (state[id] === true) mod.setAttribute('open', '');
        mod.addEventListener('toggle', function() {
            state[id] = mod.open;
            localStorage.setItem(KEY, JSON.stringify(state));
        });
    });
})();

// Section collapse state — persists inner <details> sections via localStorage
(function() {
    var KEY = 'sidebar_sections';
    var state = {};
    try { state = JSON.parse(localStorage.getItem(KEY)) || {}; } catch(e) {}

    document.querySelectorAll('.sidebar-section').forEach(function(sec, idx) {
        var title = sec.querySelector('.sidebar-section-title');
        var id = title ? title.textContent.trim().replace(/\s+/g, '_').toLowerCase() : ('sec_' + idx);

        // Restore saved state; respect PHP-set 'open' as default if no saved state
        if (id in state) {
            if (state[id]) sec.setAttribute('open', '');
            else sec.removeAttribute('open');
        }

        sec.addEventListener('toggle', function() {
            state[id] = sec.open;
            localStorage.setItem(KEY, JSON.stringify(state));
        });
    });
})();
</script>
