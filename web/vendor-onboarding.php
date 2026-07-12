<?php
/**
 * Vendor Onboarding Form
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the big kahuna -- the vendor onboarding intake form. When a new vendor
 * wants to do business with us, they get to go through this 3-section questionnaire
 * covering everything from "what's your company name" to "are you going to touch
 * our source code" (please say no).
 *
 * The form supports draft saving, auto-save every 60 seconds (because browser crashes
 * are a thing), stakeholder assignment with type-ahead search, vendor domain auto-fill,
 * SRS security scoring integration, FAIR analysis linking, and a permission model
 * more complex than your average MMORPG skill tree.
 *
 * Statuses flow like this: draft -> submitted -> in_review -> approved (or inactive).
 * Think of it as the vendor's hero's journey, except the dragon is a CSRF token.
 */

// Initialize the app framework and make sure the user is logged in
require_once 'includes/init.php';
requireAuth();

/**
 * Normalize a comma-separated list of notification email addresses: trim, drop
 * blanks and anything that isn't a valid email, de-dupe, and re-join. Returns a
 * clean comma-separated string (empty string if none valid).
 */
if (!function_exists('sanitizeNotifyEmails')) {
    function sanitizeNotifyEmails($raw): string {
        $raw = trim((string)$raw);
        if ($raw === '') { return ''; }
        $valid = [];
        foreach (explode(',', $raw) as $part) {
            $p = trim($part);
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) { $valid[$p] = $p; }
        }
        return implode(', ', array_values($valid));
    }
}

// Load the SRS (Security Rating Service) integration -- this talks to UpGuard
// to get vendor security scores. Because trusting vendors at their word is so 2005.
require_once __DIR__ . '/includes/classes/SRSService.php';
require_once __DIR__ . '/includes/classes/ShodanService.php';
$srsService = new SRSService();
$shodanService = new ShodanService();

// ============================================================
// PERMISSION CHECKS
// The permission model here is... thorough. We've got granular perms for
// creating, updating (own/assigned/all), deactivating, deleting, and approving.
// It's like an onion -- layers upon layers, and it might make you cry.
// ============================================================
$acl = ACL::getInstance();
$canCreate = $acl->hasPermission('onboarding.create') || $acl->hasGroup(['administrator', 'cyber_tprm', 'procurement', 'stakeholder']);
$canUpdateOwn = $acl->hasPermission('onboarding.update_own');
$canUpdateAssigned = $acl->hasPermission('onboarding.update_assigned');
$canUpdateAll = $acl->hasPermission('onboarding.update');
$canDeactivate = $acl->hasPermission('onboarding.deactivate') || $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');
$canDeleteAll = $acl->hasPermission('onboarding.delete') || $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');
$canDelete = $canDeleteAll; // updated below to include owner-of-draft
$canApprove = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');
$canAssignStakeholder = $acl->hasPermission('onboarding.assign_stakeholder');

// Grab our usual gang of singleton services
$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$session = Session::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// Auto-migration: ensure vendor_sisterdomains column exists
try {
    $db->fetchOne("SELECT vendor_sisterdomains FROM vendor_onboarding_requests LIMIT 0");
} catch (Exception $e) {
    try { $db->getConnection()->exec("ALTER TABLE vendor_onboarding_requests ADD COLUMN vendor_sisterdomains TEXT COMMENT 'Related/sister domains for Shodan scanning (one per line)' AFTER vendor_domain"); } catch (Exception $e2) {}
}

// Figure out what kind of power user we're dealing with
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || $acl->hasGroup('administrator');
$isProcurement = $acl->hasGroup('procurement');
$isCyberTPRM = $acl->hasGroup('cyber_tprm');
$isStakeholderGroup = $acl->hasGroup('stakeholder');
$isStakeholderOnly = $isStakeholderGroup && !$isAdmin && !$isProcurement && !$isCyberTPRM;
$isProcurementOnly = $isProcurement && !$isAdmin && !$isCyberTPRM;

// Sidebar navigation permission flags (same as index.php)
$showOnboarding = $acl->hasPermission('onboarding.create') ||
                  $acl->hasPermission('onboarding.read') ||
                  $acl->hasPermission('onboarding.read_own') ||
                  $acl->hasPermission('onboarding.read_assigned') ||
                  $isStakeholderGroup || $isProcurement;
$showFairModule = ($acl->hasPermission('analysis.create') ||
                  $acl->hasPermission('analysis.read') ||
                  $isCyberTPRM ||
                  $isAdmin) && !$isStakeholderOnly && !$isProcurementOnly;
$showSRSModule = ($isCyberTPRM || $isAdmin) && !$isStakeholderOnly;

// Only admins and cyber TPRM folks can mess with vendor tier assignments.
// Tiers control how often the vendor gets re-scored by UpGuard (daily/monthly/quarterly).
$canEditTier = $isAdmin || $isCyberTPRM;

$error = '';
$success = '';
$editMode = false;
$requestId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$request = null;
$canEdit = false;
$isStakeholder = false;
$isOwner = false;
// Page is always read-only for existing vendors (data entry happens via vendor-assessment.php)
$showTemplateSelection = false;

// ============================================================
// EDIT MODE vs NEW MODE
// If we have an ID in the URL, we're editing an existing request.
// Otherwise, we're creating a brand new one. Simple concept, but
// the permission logic below makes it look like a tax return.
// ============================================================
if ($requestId > 0) {
    $editMode = true;

    // Try to fetch the existing request from the database
    $request = $db->fetchOne(
        'SELECT * FROM vendor_onboarding_requests WHERE id = :id',
        [':id' => $requestId]
    );

    // If the request doesn't exist, boot them back to the list. No request, no party.
    if (!$request) {
        redirect('vendor-onboarding-list.php?error=not_found');
    }

    // Figure out the user's relationship to this request
    $isOwner = ($request['created_by'] == $user['id']);

    // Check if the current user is an assigned stakeholder on this request
    $stakeholderCheck = $db->fetchOne(
        'SELECT id FROM vendor_onboarding_stakeholders WHERE request_id = :request_id AND user_id = :user_id',
        [':request_id' => $requestId, ':user_id' => $user['id']]
    );
    $isStakeholder = !empty($stakeholderCheck);

    // Grab who created this request (for display purposes)
    $creatorInfo = $db->fetchOne(
        'SELECT id, full_name, username FROM users WHERE id = :id',
        [':id' => $request['created_by']]
    );

    // Find the primary stakeholder -- the person actually responsible for managing
    // this vendor relationship. Grab the most recently assigned one.
    $assignedStakeholder = $db->fetchOne(
        "SELECT u.id, u.full_name, u.username
         FROM vendor_onboarding_stakeholders vs
         JOIN users u ON vs.user_id = u.id
         WHERE vs.request_id = :request_id AND vs.role = 'stakeholder'
         ORDER BY vs.assigned_at DESC
         LIMIT 1",
        [':request_id' => $requestId]
    );

    // No stakeholder assigned? Fall back to the creator. Someone's gotta own this thing.
    if (!$assignedStakeholder) {
        $assignedStakeholder = $creatorInfo;
    }

    // The great "can you edit this?" decision tree.
    // Full update perms trump everything, then own-record, then assigned-record.
    if ($canUpdateAll) {
        $canEdit = true;
    } elseif ($canUpdateOwn && $isOwner) {
        $canEdit = true;
    } elseif ($canUpdateAssigned && $isStakeholder) {
        $canEdit = true;
    }

    // Creators can delete their own drafts
    if ($isOwner && $request['status'] === 'draft') {
        $canDelete = true;
    }

    // Procurement users can read all records but cannot edit (except limited fields)
    if ($isProcurement && !$isAdmin && !$isCyberTPRM) {
        $canEdit = false;
    }
    $canEditProcurementFields = ($isProcurementOnly && $editMode && $requestId > 0);

    // Read permission check -- even viewing requires the right access level.
    // We've got read, read_own, and read_assigned flavors.
    // Procurement gets read-all access to every vendor record.
    $canRead = $acl->hasPermission('onboarding.read') ||
               ($acl->hasPermission('onboarding.read_own') && $isOwner) ||
               ($acl->hasPermission('onboarding.read_assigned') && $isStakeholder) ||
               $isProcurement;

    if (!$canRead) {
        http_response_code(403);
        die('Access denied. You do not have permission to view this request.');
    }

    // Inactive requests are read-only for non-admins. You broke it, only an admin can fix it.
    if ($request['status'] === 'inactive' && !$acl->hasPermission('onboarding.update')) {
        $canEdit = false;
    }
} else {
    // NEW REQUEST MODE -- make sure they have create permission
    if (!$canCreate) {
        http_response_code(403);
        die('Access denied. You do not have permission to create onboarding requests.');
    }
    $canEdit = true;
    $creatorInfo = null;          // Will use current user for new requests
    $assignedStakeholder = null;

    // Check for onboarding templates -- always show template picker for new vendors
    require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
    $onboardingAssessmentService = new VendorAssessmentService();
    $onboardingTemplates = $onboardingAssessmentService->getTemplates(true, 'onboarding');
    $showTemplateSelection = true; // Always show template picker (no built-in form)
}

// The vendor tab is always read-only now -- data entry is done via vendor-assessment.php.
// $canEdit is still used for workflow action permissions (submit, approve, etc.).

// Stakeholder assignment permissions -- who can assign or reassign the responsible party?
// Admins and procurement are always in control. If you're the current stakeholder,
// you can also hand it off to someone else (hot potato style).
$canAssignStakeholders = $isAdmin || $isProcurement || $canAssignStakeholder;
if (!$canAssignStakeholders && $editMode && $isStakeholder) {
    $canAssignStakeholders = true;
}

// Build the stakeholder display string for the form -- "Jane Doe (jdoe)" format
if ($editMode && $assignedStakeholder) {
    $stakeholderDisplayName = !empty($assignedStakeholder['full_name'])
        ? $assignedStakeholder['full_name'] . ' (' . $assignedStakeholder['username'] . ')'
        : $assignedStakeholder['username'];
    $stakeholderUserId = $assignedStakeholder['id'];
} else {
    // New request - use current user
    $stakeholderDisplayName = !empty($user['full_name'])
        ? $user['full_name'] . ' (' . $user['username'] . ')'
        : $user['username'];
    $stakeholderUserId = $user['id'];
}

// Grab any security assessments that have been linked to this vendor.
// These are questionnaires we've sent out to evaluate the vendor's security posture.
$linkedAssessments = [];
$onboardingAssessmentId = null;
$onboardingAssessmentUUID = null;
if ($editMode && $requestId) {
    $linkedAssessments = $db->fetchAll(
        "SELECT va.*, t.name as template_name, t.category as template_category
         FROM vendor_assessments va
         JOIN assessment_templates t ON va.template_id = t.id
         WHERE va.vendor_request_id = :request_id
         ORDER BY va.created_at DESC",
        [':request_id' => $requestId]
    );
    // Find the most recent onboarding assessment for the Edit button
    foreach ($linkedAssessments as $la) {
        if ($la['template_category'] === 'onboarding') {
            $onboardingAssessmentId = $la['id'];
            $onboardingAssessmentUUID = $la['uuid'];
            break;
        }
    }
}

// Viewer role context for onboarding role-based field visibility (section + question
// gates). Empty group list + non-super (e.g. the vendor) only sees unrestricted items.
$viewerGroups = $acl->getUserGroups();
$viewerSuper = (bool)$session->get('is_super_admin');

// For completed assessments, load responses that aren't mapped to built-in fields
$assessmentExtraData = [];
if (!empty($linkedAssessments)) {
    require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
    $vasForExtras = new VendorAssessmentService();
    foreach ($linkedAssessments as $la) {
        if ($la['status'] === 'completed') {
            $sections = $vasForExtras->getSections($la['template_id']);
            $extraMaps = $vasForExtras->getOnboardingCustomMaps($la['template_id']);
            $extras = [];
            foreach ($sections as $sec) {
                // Section role gate — restricted sections are hidden from viewers who lack the role.
                if (!VendorAssessmentService::roleCanSee($sec['visible_roles'] ?? null, $sec['editable_roles'] ?? null, $viewerGroups, $viewerSuper, isset($extraMaps['sections'][(int)$sec['id']]))) continue;
                $questions = $vasForExtras->getQuestions($sec['id']);
                $responses = $vasForExtras->getResponses($la['id']);
                $sectionExtras = [];
                foreach ($questions as $q) {
                    if (!VendorAssessmentService::roleCanSee($q['visible_roles'] ?? null, $q['editable_roles'] ?? null, $viewerGroups, $viewerSuper, isset($extraMaps['questions'][(int)$q['id']]))) continue;
                    if (empty($q['field_name'])) {
                        $resp = $responses[$q['id']] ?? null;
                        $val = $resp ? $resp['response_value'] : '';
                        if ($val !== '' && $val !== null) {
                            if ($q['question_type'] === 'checkbox' && $val) {
                                $decoded = json_decode($val, true);
                                if (is_array($decoded)) $val = implode(', ', $decoded);
                            }
                            $sectionExtras[] = ['question' => $q['question_text'], 'value' => $val, 'type' => $q['question_type']];
                        }
                    }
                }
                if (!empty($sectionExtras)) {
                    $extras[] = ['section_name' => $sec['name'], 'items' => $sectionExtras];
                }
            }
            if (!empty($extras)) {
                $assessmentExtraData[$la['id']] = $extras;
            }
        }
    }
}

// Custom onboarding fields: questions on this vendor's onboarding template(s) whose
// field_name is a NEW custom field (no vendor_onboarding_requests column). Surfaced
// in the "Custom Data" tab. Shares one source of truth with the CSV export/import
// and the /vendors/{id} API (VendorAssessmentService::getCustomOnboardingData).
$customOnboardingData = [];
$hiddenOnboardingFields = [];
if ($requestId) {
    if (!isset($vasForExtras)) {
        require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
        $vasForExtras = new VendorAssessmentService();
    }
    // Role-gated by the current user (super admins see all). Restricted fields the
    // viewer can't see are dropped from the Custom Data tab and the Vendor tab.
    // Template-driven so every custom field shows up (editable, empty when unanswered)
    // even for onboarding requests that have no assessment yet.
    $customOnboardingData = $vasForExtras->getOnboardingCustomFields($requestId, ['groups' => $viewerGroups, 'super' => $viewerSuper]);
    $hiddenOnboardingFields = $vasForExtras->getHiddenOnboardingFieldNames($requestId, $viewerGroups, $viewerSuper);
}

// SRS integration display names and scoring config
$upguardConfig = $srsService->getScoringConfig();
$shodanConfig = $shodanService->getScoringConfig();
$upguardDisplayName = $upguardConfig['display_name'] ?? 'UpGuard';
$shodanDisplayName = $shodanConfig['display_name'] ?? 'Shodan';
$upguardDisplayMode = $upguardConfig['display_mode'] ?? 'raw';
$upguardMaxScore = (int)($upguardConfig['max_score'] ?? 950);
$upguardAvailable = $srsService->isAvailable();
$shodanAvailable = $shodanService->isAvailable();

/**
 * Converts a raw UpGuard score for display based on the configured display mode.
 */
if (!function_exists('displayUpguardScore')) {
    function displayUpguardScore(int $rawScore, string $mode, int $maxScore): string
    {
        if ($mode === 'percentage') {
            $pct = $maxScore > 0 ? (int)floor(($rawScore / $maxScore) * 100) : 0;
            return $pct . '%';
        }
        return (string)$rawScore;
    }
}

$_cronRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'upguard_use_cron'");
$upguardUseCron = ($_cronRow && $_cronRow['config_value'] === '1');
$_cronRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'shodan_use_cron'");
$shodanUseCron = ($_cronRow && $_cronRow['config_value'] === '1');


// Tab data for admin/procurement/cyber_tprm/stakeholder users
$showVendorTabs = $editMode && $requestId && ($isAdmin || $isProcurement || $isCyberTPRM || $isStakeholder);
$canAddNotes = $editMode && $requestId > 0 && ($isAdmin || $isProcurement || $isCyberTPRM || $isStakeholder) && !$isProcurementOnly;

// Action Plan (Vendor Remediation Schedule) is restricted to admin / cyber_tprm.
$canManageScheduledActions = $editMode && $requestId > 0 && ($isAdmin || $isCyberTPRM);

// Role-based assignee group restrictions for case management
$allowedNoteGroups = '';
if ($isAdmin || $isCyberTPRM || $isProcurement) {
    $allowedNoteGroups = 'procurement,cyber_tprm,administrator';
} elseif ($isStakeholder) {
    $allowedNoteGroups = 'cyber_tprm,administrator';
}

$vendorNotes = [];
$vendorNotesCount = 0;
$scheduledActions = [];
$scheduledActionsCount = 0;
$scheduledActionAssessments = [];
$scheduledActionAssignees = [];
$scheduledActionNotesByAction = [];
$openCasesCount = 0;
$closedCasesCount = 0;
$vendorRisksCount = 0;
$vendorSrsRisks = [];
$vendorSrsRiskDetails = [];
$vendorShodanFindings = [];
$vendorShodanFindingDetails = [];
$vendorFairAnalyses = [];
$vendorDocuments = [];
$vendorDocumentsCount = 0;

// Score data: used by Vendor tab header to show "Security Score Details" link
$vendorLatestSrsScore = null;
$vendorLatestShodanScore = null;
$hasScoreData = false;

if ($showVendorTabs) {
    // Case Notes from cyber_todo_activities
    try {
        $allActivities = $db->fetchAll(
            "SELECT a.*, u.full_name as created_by_name, au.full_name as assigned_to_name
             FROM cyber_todo_activities a
             LEFT JOIN users u ON a.created_by = u.id
             LEFT JOIN users au ON a.assigned_to = au.id
             WHERE a.reference_type = 'vendor_onboarding_requests' AND a.reference_id = :request_id
             ORDER BY a.created_at ASC",
            [':request_id' => $requestId]
        );

        // Separate top-level cases from replies
        $noteReplies = [];
        $topLevelNotes = [];
        foreach ($allActivities as $note) {
            if (!empty($note['parent_id'])) {
                $noteReplies[$note['parent_id']][] = $note;
            } else {
                $topLevelNotes[] = $note;
            }
        }

        $vendorNotes = $topLevelNotes;
        $vendorNotesCount = count($vendorNotes);

        // Compute open/closed case counts (top-level only)
        foreach ($vendorNotes as $n) {
            if (in_array($n['status'], ['open', 'in_progress'])) {
                $openCasesCount++;
            } elseif ($n['status'] === 'closed') {
                $closedCasesCount++;
            }
        }

        // Filter by case_status if specified (for sidebar links)
        $caseStatusFilter = isset($_GET['case_status']) ? $_GET['case_status'] : '';
        if ($caseStatusFilter === 'open') {
            $vendorNotes = array_filter($vendorNotes, function($n) {
                return in_array($n['status'], ['open', 'in_progress']);
            });
        } elseif ($caseStatusFilter === 'closed') {
            $vendorNotes = array_filter($vendorNotes, function($n) {
                return $n['status'] === 'closed';
            });
        } elseif ($caseStatusFilter === 'assigned_to_me') {
            $currentUserId = $user['id'];
            $vendorNotes = array_filter($vendorNotes, function($n) use ($currentUserId) {
                return !empty($n['assigned_to']) && intval($n['assigned_to']) === intval($currentUserId) && in_array($n['status'], ['open', 'in_progress']);
            });
        }

        // Procurement-only users see only their own closed cases
        if ($isProcurementOnly) {
            $currentUserId = $user['id'];
            $vendorNotes = array_filter($vendorNotes, function($n) use ($currentUserId) {
                return $n['status'] === 'closed'
                    && (intval($n['created_by']) === intval($currentUserId)
                        || (!empty($n['assigned_to']) && intval($n['assigned_to']) === intval($currentUserId)));
            });
            $vendorNotesCount = count($vendorNotes);
            $openCasesCount = 0;
        }
    } catch (Exception $e) {
        // Table may not exist yet
    }

    // Action Plan: scheduled actions for this vendor (admin / cyber_tprm only)
    if ($canManageScheduledActions) {
        try {
            $scheduledActions = $db->fetchAll(
                "SELECT sa.*, t.name AS assessment_name, u.full_name AS created_by_name
                 FROM vendor_scheduled_actions sa
                 LEFT JOIN assessment_templates t ON sa.assessment_template_id = t.id
                 LEFT JOIN users u ON sa.created_by = u.id
                 WHERE sa.request_id = :request_id
                 ORDER BY FIELD(sa.status,'pending','in_progress','problem','completed','cancelled'), sa.scheduled_date ASC, sa.id ASC",
                [':request_id' => $requestId]
            );
            $scheduledActionsCount = 0;
            foreach ($scheduledActions as $sa) {
                if (!in_array($sa['status'], ['completed', 'cancelled'], true)) { $scheduledActionsCount++; }
            }
            // Status notes for every action (for the inline expand-on-click view)
            $scheduledActionNotesByAction = [];
            if (!empty($scheduledActions)) {
                $saIds = array_map(function ($x) { return (int)$x['id']; }, $scheduledActions);
                $ph = implode(',', array_fill(0, count($saIds), '?'));
                $allSaNotes = $db->fetchAll(
                    "SELECT a.*, u.full_name AS created_by_name
                     FROM cyber_todo_activities a
                     LEFT JOIN users u ON a.created_by = u.id
                     WHERE a.reference_type = 'vendor_scheduled_actions' AND a.reference_id IN ($ph)
                     ORDER BY a.created_at ASC",
                    $saIds
                );
                foreach ($allSaNotes as $an) { $scheduledActionNotesByAction[(int)$an['reference_id']][] = $an; }
            }
            // Vendor Assessment templates available for the "Send Assessment" action
            $scheduledActionAssessments = $db->fetchAll(
                "SELECT id, name FROM assessment_templates WHERE category = 'vendor_assessment' AND is_active = 1 ORDER BY name ASC"
            );
            // cyber_tprm members who can be assigned a scheduled action
            $scheduledActionAssignees = $db->fetchAll(
                "SELECT u.id, u.full_name, u.username, u.email
                 FROM users u
                 JOIN user_acl_groups ug ON ug.user_id = u.id
                 JOIN acl_groups g ON g.id = ug.group_id
                 WHERE g.group_name = 'cyber_tprm' AND (u.is_active IS NULL OR u.is_active = 1)
                 ORDER BY u.full_name ASC, u.username ASC"
            );
            // Detail view: a single selected action + its status-note thread
            $selectedAction = null;
            $selectedActionNotes = [];
            if (!empty($_GET['action_id'])) {
                $selId = (int)$_GET['action_id'];
                foreach ($scheduledActions as $sa) {
                    if ((int)$sa['id'] === $selId) { $selectedAction = $sa; break; }
                }
                if ($selectedAction) {
                    $selectedActionNotes = $db->fetchAll(
                        "SELECT a.*, u.full_name AS created_by_name
                         FROM cyber_todo_activities a
                         LEFT JOIN users u ON a.created_by = u.id
                         WHERE a.reference_type = 'vendor_scheduled_actions' AND a.reference_id = :sid
                         ORDER BY a.created_at ASC",
                        [':sid' => $selId]
                    );
                }
            }
        } catch (Exception $e) {
            // Table may not exist yet
        }
    }

    // SRS Risks (summary counts)
    try {
        $vendorSrsRisks = $db->fetchAll(
            "SELECT vsr.severity, COUNT(*) as count
             FROM vendor_srs_risks vsr
             JOIN vendor_srs_scores vss ON vsr.srs_score_id = vss.id
             WHERE vss.vendor_onboarding_id = :request_id
             GROUP BY vsr.severity
             ORDER BY FIELD(vsr.severity, 'critical', 'high', 'medium', 'low', 'info')",
            [':request_id' => $requestId]
        );
    } catch (Exception $e) {}

    // SRS Risks (individual details)
    try {
        $vendorSrsRiskDetails = $db->fetchAll(
            "SELECT vsr.risk_name, vsr.risk_category, vsr.severity, vsr.description, vsr.first_seen
             FROM vendor_srs_risks vsr
             JOIN vendor_srs_scores vss ON vsr.srs_score_id = vss.id
             WHERE vss.vendor_onboarding_id = :request_id
             ORDER BY FIELD(vsr.severity, 'critical', 'high', 'medium', 'low', 'info'), vsr.risk_name",
            [':request_id' => $requestId]
        );
    } catch (Exception $e) {}

    // Shodan Findings (summary counts — exclude NULL severity)
    try {
        $vendorShodanFindings = $db->fetchAll(
            "SELECT vsf.severity, COUNT(*) as count
             FROM vendor_shodan_findings vsf
             JOIN vendor_shodan_scores vss ON vsf.shodan_score_id = vss.id
             WHERE vss.vendor_onboarding_id = :request_id AND vsf.severity IS NOT NULL AND vsf.severity != ''
             GROUP BY vsf.severity
             ORDER BY FIELD(vsf.severity, 'critical', 'high', 'medium', 'low', 'info', 'positive', 'negative')",
            [':request_id' => $requestId]
        );
    } catch (Exception $e) {}

    // Shodan Findings (individual details)
    try {
        $vendorShodanFindingDetails = $db->fetchAll(
            "SELECT vsf.finding_type, COALESCE(NULLIF(vsf.severity, ''), 'info') as severity,
                    vsf.description, vsf.ip_address, vsf.port,
                    vsf.service_name, vsf.cve_id, vsf.cvss_score, vsf.subdomain, vsf.category
             FROM vendor_shodan_findings vsf
             JOIN vendor_shodan_scores vss ON vsf.shodan_score_id = vss.id
             WHERE vss.vendor_onboarding_id = :request_id
             ORDER BY FIELD(COALESCE(NULLIF(vsf.severity, ''), 'info'), 'critical', 'high', 'medium', 'low', 'info', 'positive', 'negative'), vsf.finding_type",
            [':request_id' => $requestId]
        );
    } catch (Exception $e) {}

    // Total risks count
    $srsTotal = 0;
    foreach ($vendorSrsRisks as $r) { $srsTotal += $r['count']; }
    $shodanTotal = 0;
    foreach ($vendorShodanFindings as $r) { $shodanTotal += $r['count']; }
    $vendorRisksCount = $srsTotal + $shodanTotal;

    // Fetch latest scores for scorecard display in Risks tab
    $latestScore = null;
    $shodanLatestScore = null;
    $shodanWaivers = [];
    try {
        $latestScore = $srsService->getLatestScore($requestId);
    } catch (Exception $e) {}
    try {
        $shodanLatestScore = $shodanService->getLatestScore($requestId);
    } catch (Exception $e) {}
    try {
        $shodanWaivers = $shodanService->getWaiversForVendor($requestId);
    } catch (Exception $e) {}

    // FAIR Analyses linked by vendor name/domain
    try {
        $fairParams = [];
        $fairConditions = [];
        if (!empty($request['vendor_name'])) {
            $fairConditions[] = "vendor_name = :vname";
            $fairParams[':vname'] = $request['vendor_name'];
        }
        if (!empty($request['vendor_domain'])) {
            $fairConditions[] = "vendor_domain = :vdomain";
            $fairParams[':vdomain'] = $request['vendor_domain'];
        }
        if (!empty($fairConditions)) {
            $vendorFairAnalyses = $db->fetchAll(
                "SELECT id, vendor_name, vendor_domain, status, risk_output,
                        ale, loss_event_frequency, primary_loss_magnitude,
                        secondary_loss_magnitude, recommended_liability,
                        vendor_cyber_insurance_coverage, created_at
                 FROM tprm_results
                 WHERE (" . implode(' OR ', $fairConditions) . ")
                 ORDER BY created_at DESC",
                $fairParams
            );
            // Decrypt the encrypted FAIR fields
            $encryption = new Encryption();
            foreach ($vendorFairAnalyses as &$fair) {
                $encryptedFields = ['ale', 'loss_event_frequency', 'primary_loss_magnitude',
                                    'secondary_loss_magnitude', 'recommended_liability',
                                    'vendor_cyber_insurance_coverage'];
                foreach ($encryptedFields as $field) {
                    if (!empty($fair[$field])) {
                        $fair[$field] = $encryption->decrypt($fair[$field]);
                    }
                }
            }
            unset($fair);
        }
    } catch (Exception $e) {}

    // Vendor Documents
    try {
        $vendorDocuments = $db->fetchAll(
            "SELECT vd.id, vd.file_uuid, vd.document_type, vd.contract_name, vd.contract_type,
                    vd.contract_creation_date, vd.contract_expiration_date, vd.contract_pricing,
                    vd.certification_type, vd.certification_expiration_date, vd.is_active,
                    vd.description, vd.original_filename, vd.mime_type, vd.file_size,
                    vd.uploaded_by, vd.created_at, u.full_name as uploaded_by_name
             FROM vendor_documents vd
             LEFT JOIN users u ON vd.uploaded_by = u.id
             WHERE vd.vendor_request_id = :request_id
             ORDER BY vd.created_at DESC",
            [':request_id' => $requestId]
        );
        // Procurement-only users see only contract documents
        if ($isProcurementOnly) {
            $vendorDocuments = array_filter($vendorDocuments, function($d) {
                return $d['document_type'] === 'contract';
            });
            $vendorDocuments = array_values($vendorDocuments);
        }
        $vendorDocumentsCount = count($vendorDocuments);
    } catch (Exception $e) {
        $vendorDocuments = [];
    }

    // Load vendor subprocessors (4th party supply chain)
    $vendorSubprocessors = [];
    $vendorSubprocessorsCount = 0;
    try {
        $vendorSubprocessors = $db->fetchAll(
            "SELECT m.id AS mapping_id, m.service_description, m.data_shared, m.created_at,
                    s.id AS subprocessor_id, s.subprocessor_name, s.subprocessor_domain,
                    s.country, s.linked_vendor_id,
                    lv.vendor_name AS linked_vendor_name,
                    u.full_name AS added_by_name,
                    (SELECT COUNT(*) FROM vendor_subprocessor_mappings m2 WHERE m2.subprocessor_id = s.id) AS vendor_count
             FROM vendor_subprocessor_mappings m
             JOIN vendor_subprocessors s ON m.subprocessor_id = s.id
             LEFT JOIN vendor_onboarding_requests lv ON s.linked_vendor_id = lv.id
             LEFT JOIN users u ON m.added_by = u.id
             WHERE m.vendor_onboarding_id = :request_id
             ORDER BY s.subprocessor_name ASC",
            [':request_id' => $requestId]
        );
        $vendorSubprocessorsCount = count($vendorSubprocessors);
    } catch (Exception $e) {
        $vendorSubprocessors = [];
    }

    // Check if vendor has score data (for SRS details link in header)
    try {
        $vendorLatestSrsScore = $srsService->getLatestScore($requestId);
    } catch (Exception $e) {}
    try {
        if ($shodanService->isAvailable()) {
            $vendorLatestShodanScore = $shodanService->getLatestScore($requestId);
        }
    } catch (Exception $e) {}
    $hasScoreData = ($vendorLatestSrsScore || $vendorLatestShodanScore);

    // Grip "SaaS Data" tab — curated telemetry stamped on the vendor record by
    // GripService during sync (domain match). Only surfaced when Grip is enabled
    // AND this vendor actually has matched app data.
    $gripEnabled = (getAppConfig('grip_enabled', '0') === '1');
    $gripSaasData = null;
    if ($gripEnabled && !empty($request['grip_app_data'])) {
        $decoded = json_decode($request['grip_app_data'], true);
        if (is_array($decoded) && $decoded) {
            $gripSaasData = $decoded;
        }
    }
    $showGripSaasTab = ($gripEnabled && !empty($gripSaasData));

    // ============================================================
    // PROCUREMENT DETAILED SUMMARY — Additional Data Collection
    // Fetches data needed for the embedded vendor detailed summary report
    // that procurement-only users see in the Risks tab.
    // ============================================================
    if ($isProcurementOnly) {
        // Combined Score
        $combinedScore = $srsService->getCombinedScore($request);

        // Score Trends (1 year)
        $ugTrend = $srsService->getScoreTrend($requestId, 365);
        $shTrend = [];
        try { $shTrend = $shodanService->getScoreTrend($requestId, 365); } catch (Exception $e) {}

        // Full risk details from UpGuard
        $risks = $latestScore ? $srsService->getRisksForScore($latestScore['id']) : [];
        $ugCategoryScores = [];
        if ($latestScore && !empty($latestScore['category_scores'])) {
            $ugCategoryScores = is_string($latestScore['category_scores'])
                ? json_decode($latestScore['category_scores'], true) : $latestScore['category_scores'];
            if (!is_array($ugCategoryScores)) $ugCategoryScores = [];
        }

        // Full findings from Shodan
        $shodanFindings = [];
        if ($shodanLatestScore) {
            try { $shodanFindings = $shodanService->getFindingsForScore($shodanLatestScore['id']); } catch (Exception $e) {}
        }
        $shCategoryScores = [];
        if ($shodanLatestScore && !empty($shodanLatestScore['category_scores'])) {
            $shCategoryScores = is_string($shodanLatestScore['category_scores'])
                ? json_decode($shodanLatestScore['category_scores'], true) : $shodanLatestScore['category_scores'];
            if (!is_array($shCategoryScores)) $shCategoryScores = [];
        }

        // Single latest FAIR Analysis (pick from already-fetched $vendorFairAnalyses)
        $fairAnalysis = null;
        if (!empty($vendorFairAnalyses)) {
            foreach ($vendorFairAnalyses as $fa) {
                if ($fa['status'] === 'completed') { $fairAnalysis = $fa; break; }
            }
            if (!$fairAnalysis) $fairAnalysis = $vendorFairAnalyses[0];
        }

        // Technologies (deduplicated, filter out version-only names)
        $rawTechs = [];
        try {
            $rawTechs = $db->fetchAll(
                'SELECT DISTINCT technology_name, technology_category, technology_version
                 FROM vendor_technologies
                 WHERE vendor_onboarding_id = :id AND is_current = 1
                 ORDER BY technology_category, technology_name',
                [':id' => $requestId]
            );
        } catch (Exception $e) {}
        $techAliases = [
            'gcp' => 'Google Cloud', 'google cloud platform' => 'Google Cloud',
            'aws' => 'Amazon Web Services', 'msft' => 'Microsoft',
            'ms azure' => 'Microsoft Azure', 'azure' => 'Microsoft Azure',
            'letsencrypt' => "Let's Encrypt", "let's encrypt" => "Let's Encrypt",
            'cloudflare cdn' => 'Cloudflare', 'cloudflare dns' => 'Cloudflare',
            'bigip' => 'F5 BIG-IP', 'big-ip' => 'F5 BIG-IP', 'f5 big-ip' => 'F5 BIG-IP',
            'akamaighost' => 'Akamai', 'akamai ghost' => 'Akamai',
            'amazon elb' => 'AWS ELB', 'awselb' => 'AWS ELB',
        ];
        $techCategoryOverrides = [
            'f5 big-ip' => 'cdn_waf', 'akamai' => 'cdn_waf', 'aws elb' => 'cdn_waf',
            'cloudflare' => 'cdn_waf', 'cloudfront' => 'cdn_waf', 'fastly' => 'cdn_waf',
            'imperva' => 'cdn_waf', 'incapsula' => 'cdn_waf', 'sucuri' => 'cdn_waf',
            'stackpath' => 'cdn_waf',
        ];
        $technologies = [];
        $seenTechNames = [];
        foreach ($rawTechs as $t) {
            $name = trim($t['technology_name'] ?? '');
            if ($name === '' || preg_match('/^\d[\d.]*$/', $name)) continue;
            $key = strtolower($name);
            if (isset($techAliases[$key])) {
                $name = $techAliases[$key];
                $t['technology_name'] = $name;
                $key = strtolower($name);
            }
            if (isset($techCategoryOverrides[$key])) {
                $t['technology_category'] = $techCategoryOverrides[$key];
            }
            if (isset($seenTechNames[$key])) continue;
            $seenTechNames[$key] = true;
            $technologies[] = $t;
        }

        // Completed Assessments Count
        $assessmentCount = 0;
        try {
            $assessmentRow = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM vendor_assessments WHERE vendor_request_id = :id AND status = 'completed'",
                [':id' => $requestId]
            );
            $assessmentCount = (int)($assessmentRow['cnt'] ?? 0);
        } catch (Exception $e) {}

        // Certifications
        $certifications = [];
        try {
            $certifications = $db->fetchAll(
                "SELECT certification_type, certification_expiration_date, original_filename
                 FROM vendor_documents
                 WHERE vendor_request_id = :id AND document_type = 'certification' AND is_active = 1
                 ORDER BY certification_expiration_date DESC",
                [':id' => $requestId]
            );
        } catch (Exception $e) {}

        // Assessment Metadata & Vendor Claims
        require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
        $procAssessmentService = new VendorAssessmentService();
        $assessmentMeta = [];
        $vendorClaims = [];
        $domainKeywords = [
            'tls_crypto'        => ['encrypt', 'tls', 'ssl', 'crypto', 'certificate', 'https'],
            'network_security'  => ['firewall', 'network', 'port', 'ids', 'ips', 'intrusion', 'segmentation'],
            'app_hardening'     => ['harden', 'waf', 'application security', 'secure development', 'sdlc', 'code review'],
            'email_security'    => ['email', 'phishing', 'spf', 'dkim', 'dmarc', 'spam'],
            'vuln_exposure'     => ['vulnerab', 'scan', 'patch', 'remediat', 'penetration', 'pentest', 'cve'],
            'certifications'    => ['certif', 'soc', 'iso', 'compliance', 'audit', 'attestation'],
        ];
        try {
            $completedAssessments = $db->fetchAll(
                "SELECT a.id, a.completed_at, t.name as template_name, t.id as template_id
                 FROM vendor_assessments a
                 JOIN assessment_templates t ON a.template_id = t.id
                 WHERE a.vendor_request_id = :id AND a.status = 'completed' AND t.category = 'vendor_assessment'
                 ORDER BY a.completed_at DESC LIMIT 5",
                [':id' => $requestId]
            );
            foreach ($completedAssessments as $ca) {
                $sections = $procAssessmentService->getSections($ca['template_id']);
                $responses = $procAssessmentService->getResponses($ca['id']);
                $totalAnswered = 0;
                $totalQuestions = 0;
                $sectionCount = count($sections);
                foreach ($sections as $section) {
                    $questions = $procAssessmentService->getQuestions($section['id']);
                    $totalQuestions += count($questions);
                    $sectionName = strtolower($section['name'] ?? '');
                    foreach ($questions as $q) {
                        $qId = $q['id'];
                        $hasAnswer = isset($responses[$qId]) && ($responses[$qId]['response_value'] ?? '') !== '';
                        if ($hasAnswer) $totalAnswered++;
                        if (!$hasAnswer) continue;
                        $rawVal = $responses[$qId]['response_value'];
                        if (in_array($q['question_type'] ?? '', ['checkbox', 'button_group_multi'])) {
                            $decoded = json_decode($rawVal, true);
                            if (is_array($decoded)) $rawVal = implode(', ', $decoded);
                        }
                        if (mb_strlen($rawVal) > 100) continue;
                        $qText = strtolower($q['question_text'] ?? '');
                        $searchText = $sectionName . ' ' . $qText;
                        foreach ($domainKeywords as $domain => $keywords) {
                            foreach ($keywords as $kw) {
                                if (stripos($searchText, $kw) !== false) {
                                    if (!isset($vendorClaims[$domain])) {
                                        $vendorClaims[$domain] = [
                                            'answer' => $rawVal,
                                            'question' => $q['question_text'] ?? '',
                                            'source' => 'Assessment',
                                        ];
                                    }
                                    break 2;
                                }
                            }
                        }
                    }
                }
                $assessmentMeta[] = [
                    'template_name' => $ca['template_name'],
                    'completed_at'  => $ca['completed_at'],
                    'sections'      => $sectionCount,
                    'answered'      => $totalAnswered,
                    'total'         => $totalQuestions,
                ];
            }
        } catch (Exception $e) {}

        // NIST CSF 2.0 Score Computation
        $nistIdentify = 0;
        if (count($technologies) > 0) $nistIdentify += 40;
        if ($fairAnalysis) $nistIdentify += 30;
        if (!empty($request['vendor_tier'])) $nistIdentify += 30;

        $nistProtect = 0;
        if (!empty($shCategoryScores)) {
            $protectCats = ['tls_crypto', 'app_hardening', 'email_security', 'network_security'];
            $protectSum = 0; $protectCount = 0;
            foreach ($protectCats as $cat) {
                if (isset($shCategoryScores[$cat])) {
                    $protectSum += (int)$shCategoryScores[$cat];
                    $protectCount++;
                }
            }
            $nistProtect = $protectCount > 0 ? round($protectSum / $protectCount) : 0;
        }

        $detectVuln = isset($shCategoryScores['vuln_exposure']) ? (int)$shCategoryScores['vuln_exposure'] : 0;
        $detectMonitor = $latestScore ? 100 : 0;
        $nistDetect = round($detectVuln * 0.6 + $detectMonitor * 0.4);

        $nistRespond = 0;
        if ($assessmentCount > 0) $nistRespond += 50;
        $govAnswered = 0;
        try {
            $govRow = $db->fetchOne(
                "SELECT COUNT(DISTINCT ar.question_id) as cnt
                 FROM vendor_assessment_responses ar
                 JOIN vendor_assessments a ON ar.assessment_id = a.id
                 JOIN assessment_questions q ON ar.question_id = q.id
                 WHERE a.vendor_request_id = :id AND a.status = 'completed'
                   AND ar.response_value IS NOT NULL AND ar.response_value != ''",
                [':id' => $requestId]
            );
            $govAnswered = (int)($govRow['cnt'] ?? 0);
        } catch (Exception $e) {}
        if ($govAnswered > 0) $nistRespond += 50;

        $nistRecover = 0;
        if ($fairAnalysis) {
            $recommended = (float)($fairAnalysis['recommended_liability'] ?? 0);
            $actual = (float)($fairAnalysis['vendor_cyber_insurance_coverage'] ?? 0);
            if ($recommended > 0 && $actual > 0) {
                $coverageRatio = min(1.0, $actual / $recommended);
                $nistRecover += round($coverageRatio * 50);
            }
            $nistRecover += 50;
        } elseif ($assessmentCount > 0) {
            $nistRecover += 25;
        }

        $nistScores = [
            'Identify' => $nistIdentify, 'Protect' => $nistProtect,
            'Detect' => $nistDetect, 'Respond' => $nistRespond, 'Recover' => $nistRecover,
        ];

        // Severity Distribution (combined)
        $severityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
        foreach ($risks as $r) {
            $sev = strtolower($r['severity'] ?? 'info');
            if (isset($severityCounts[$sev])) $severityCounts[$sev]++;
        }
        if ($shodanLatestScore) {
            $severityCounts['critical'] += (int)($shodanLatestScore['critical_vulns'] ?? 0);
            $severityCounts['high'] += (int)($shodanLatestScore['high_vulns'] ?? 0);
            $severityCounts['medium'] += (int)($shodanLatestScore['medium_vulns'] ?? 0);
            $severityCounts['low'] += (int)($shodanLatestScore['low_vulns'] ?? 0);
        }

        // Prepare chart trend data
        $trendLabels = []; $ugTrendData = []; $shTrendData = [];
        $allDates = [];
        foreach ($ugTrend as $t) $allDates[$t['date']] = true;
        foreach ($shTrend as $t) $allDates[$t['date']] = true;
        ksort($allDates);
        $ugTrendMap = [];
        foreach ($ugTrend as $t) {
            $ugTrendMap[$t['date']] = $upguardMaxScore > 0 ? (int)floor(($t['score'] / $upguardMaxScore) * 100) : 0;
        }
        $shTrendMap = [];
        foreach ($shTrend as $t) $shTrendMap[$t['date']] = $t['score'];
        foreach ($allDates as $date => $_) {
            $trendLabels[] = $date;
            $ugTrendData[] = $ugTrendMap[$date] ?? null;
            $shTrendData[] = $shTrendMap[$date] ?? null;
        }

        // Signal filtering
        $negativeSignals = array_values(array_filter($shodanFindings, fn($f) => ($f['signal_type'] ?? '') === 'negative'));
        $positiveSignals = array_values(array_filter($shodanFindings, fn($f) => ($f['signal_type'] ?? '') === 'positive'));
        $highCVEs = array_values(array_filter($shodanFindings, fn($f) =>
            !empty($f['cve_id']) && ($f['cvss_score'] ?? 0) >= 7.0
        ));
        usort($highCVEs, fn($a, $b) => ($b['cvss_score'] ?? 0) <=> ($a['cvss_score'] ?? 0));
        $topRisks = array_values(array_filter($risks, fn($r) => in_array($r['severity'] ?? '', ['critical', 'high'])));

        // Grade helpers
        $ugGrade = $latestScore ? $srsService->calculateGrade(intval($latestScore['score'])) : null;
        $shGrade = $shodanLatestScore ? $shodanService->calculateGrade(intval($shodanLatestScore['score'])) : null;

        // Report metadata
        $headerColor = $theme['header_color'] ?? '#35a0a3';
        $logoUrl = $theme['logo_url'] ?? '';
        $reportDate = date('F j, Y');
        $reportTime = date('g:i A');

        // Grade color helper
        if (!function_exists('gradeClass')) {
            function gradeClass(string $grade): string {
                $map = ['A' => '#22c55e', 'B' => '#3b82f6', 'C' => '#f59e0b', 'D' => '#f97316', 'F' => '#ef4444'];
                return $map[$grade] ?? '#6b7280';
            }
        }
    }
}

// ============================================================
// CASE REPLY HANDLER
// Adds a threaded reply to an existing case.
// ============================================================
// SECURITY (IDOR): match the UI policy ($canAddNotes) -- procurement-only users may
// not write case replies/notes/status/reassignment even via a direct POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reply']) && $editMode && $requestId > 0 && ($isAdmin || $isProcurement || $isCyberTPRM || $isStakeholder) && !$isProcurementOnly) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $parentCaseId = intval($_POST['parent_case_id'] ?? 0);
        $replyDescription = trim($security->cleanInput($_POST['reply_description'] ?? ''));

        if (empty($replyDescription)) {
            $error = t('vendor-onboarding.err_reply_text_required');
        } elseif ($parentCaseId <= 0) {
            $error = t('vendor-onboarding.err_invalid_parent_case');
        } else {
            try {
                // Verify parent exists and belongs to this vendor
                $parentCase = $db->fetchOne(
                    "SELECT * FROM cyber_todo_activities WHERE id = :id AND reference_type = 'vendor_onboarding_requests' AND reference_id = :rid AND (parent_id IS NULL OR parent_id = 0)",
                    [':id' => $parentCaseId, ':rid' => $requestId]
                );
                if ($parentCase) {
                    $db->insert('cyber_todo_activities', [
                        'parent_id' => $parentCaseId,
                        'todo_type' => 'custom',
                        'reference_type' => $parentCase['reference_type'],
                        'reference_id' => $parentCase['reference_id'],
                        'activity_type' => 'note',
                        'title' => null,
                        'description' => $replyDescription,
                        'status' => 'open',
                        'created_by' => $user['id']
                    ]);
                    $auth->audit($user['id'], 'case_reply', 'cyber_todo_activities', $parentCaseId, [
                        'new' => ['parent_case_id' => $parentCaseId, 'vendor_request_id' => $requestId]
                    ]);
                    redirect('vendor-onboarding.php?id=' . $requestId . '&tab=notes&success_note=1');
                } else {
                    $error = t('vendor-onboarding.err_parent_case_not_found');
                }
            } catch (Exception $e) {
                error_log('Add reply error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_add_reply_failed');
            }
        }
    }
}

// ============================================================
// CASE STATUS TOGGLE HANDLER
// Toggles a case between open and closed.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_case_status']) && $editMode && $requestId > 0 && ($isAdmin || $isProcurement || $isCyberTPRM || $isStakeholder) && !$isProcurementOnly) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $toggleCaseId = intval($_POST['case_id'] ?? 0);
        if ($toggleCaseId > 0) {
            try {
                $caseRow = $db->fetchOne(
                    "SELECT id, status FROM cyber_todo_activities WHERE id = :id AND reference_type = 'vendor_onboarding_requests' AND reference_id = :rid AND (parent_id IS NULL OR parent_id = 0)",
                    [':id' => $toggleCaseId, ':rid' => $requestId]
                );
                if ($caseRow) {
                    $oldStatus = $caseRow['status'];
                    if ($caseRow['status'] === 'closed') {
                        $db->query(
                            "UPDATE cyber_todo_activities SET status = 'open', closed_at = NULL, closed_by = NULL, updated_at = NOW() WHERE id = :id",
                            [':id' => $toggleCaseId]
                        );
                        $newStatus = 'open';
                    } else {
                        $db->query(
                            "UPDATE cyber_todo_activities SET status = 'closed', closed_at = NOW(), closed_by = :uid, updated_at = NOW() WHERE id = :id",
                            [':uid' => $user['id'], ':id' => $toggleCaseId]
                        );
                        $newStatus = 'closed';
                    }
                    $auth->audit($user['id'], 'case_toggle_status', 'cyber_todo_activities', $toggleCaseId, [
                        'old' => ['status' => $oldStatus],
                        'new' => ['status' => $newStatus, 'vendor_request_id' => $requestId]
                    ]);
                    redirect('vendor-onboarding.php?id=' . $requestId . '&tab=notes');
                }
            } catch (Exception $e) {
                error_log('Toggle case status error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_update_case_status_failed');
            }
        }
    }
}

// ============================================================
// CASE REASSIGNMENT HANDLER
// Reassigns an existing case to a different user.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reassign_case']) && $editMode && $requestId > 0 && ($isAdmin || $isProcurement || $isCyberTPRM || $isStakeholder) && !$isProcurementOnly) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $reassignCaseId = intval($_POST['reassign_case_id'] ?? 0);
        $reassignToUserId = intval($_POST['reassign_case_user_id'] ?? 0);

        if ($reassignCaseId <= 0 || $reassignToUserId <= 0) {
            $error = t('vendor-onboarding.err_select_valid_user_case');
        } else {
            // Validate assignee belongs to an allowed group
            $validAssignee = false;
            try {
                $assigneeGroups = $acl->getUserGroups($reassignToUserId);
                $allowedList = array_filter(array_map('trim', explode(',', $allowedNoteGroups)));
                foreach ($assigneeGroups as $groupName) {
                    if (in_array($groupName, $allowedList)) {
                        $validAssignee = true;
                        break;
                    }
                }
            } catch (Exception $e) {}

            if (!$validAssignee) {
                $error = t('vendor-onboarding.err_user_not_allowed_group');
            } else {
                try {
                    $caseRow = $db->fetchOne(
                        "SELECT id, assigned_to FROM cyber_todo_activities WHERE id = :id AND reference_type = 'vendor_onboarding_requests' AND reference_id = :rid AND (parent_id IS NULL OR parent_id = 0)",
                        [':id' => $reassignCaseId, ':rid' => $requestId]
                    );
                    if ($caseRow) {
                        $db->query(
                            "UPDATE cyber_todo_activities SET assigned_to = :uid, updated_at = NOW() WHERE id = :id",
                            [':uid' => $reassignToUserId, ':id' => $reassignCaseId]
                        );
                        $auth->audit($user['id'], 'case_reassign', 'cyber_todo_activities', $reassignCaseId, [
                            'old' => ['assigned_to' => $caseRow['assigned_to']],
                            'new' => ['assigned_to' => $reassignToUserId, 'vendor_request_id' => $requestId]
                        ]);
                        redirect('vendor-onboarding.php?id=' . $requestId . '&tab=notes');
                    } else {
                        $error = t('vendor-onboarding.err_case_not_found');
                    }
                } catch (Exception $e) {
                    error_log('Case reassignment error: ' . $e->getMessage());
                    $error = t('vendor-onboarding.err_reassign_case_failed');
                }
            }
        }
    }
}

// ============================================================
// CASE NOTE HANDLER
// Adds a note to the vendor via cyber_todo_activities table.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note']) && $editMode && $requestId > 0 && ($isAdmin || $isProcurement || $isCyberTPRM || $isStakeholder) && !$isProcurementOnly) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $noteTitle = trim($security->cleanInput($_POST['note_title'] ?? ''));
        $noteDescription = trim($security->cleanInput($_POST['note_description'] ?? ''));
        $noteDueDate = trim($security->cleanInput($_POST['note_due_date'] ?? ''));
        $noteAssignedTo = intval($_POST['note_assigned_to'] ?? 0);

        // Validate assignee belongs to an allowed group
        if ($noteAssignedTo > 0) {
            $validAssignee = false;
            try {
                $assigneeGroups = $acl->getUserGroups($noteAssignedTo);
                $allowedList = array_filter(array_map('trim', explode(',', $allowedNoteGroups)));
                foreach ($assigneeGroups as $groupName) {
                    if (in_array($groupName, $allowedList)) {
                        $validAssignee = true;
                        break;
                    }
                }
            } catch (Exception $e) {}
            if (!$validAssignee) {
                $noteAssignedTo = 0;
            }
        }

        if (empty($noteTitle)) {
            $error = t('vendor-onboarding.err_note_title_required');
        } else {
            try {
                $insertData = [
                    'todo_type' => 'custom',
                    'reference_type' => 'vendor_onboarding_requests',
                    'reference_id' => $requestId,
                    'activity_type' => 'note',
                    'title' => $noteTitle,
                    'description' => $noteDescription,
                    'status' => 'open',
                    'created_by' => $user['id']
                ];
                if (!empty($noteDueDate)) {
                    $insertData['due_date'] = $noteDueDate;
                }
                if ($noteAssignedTo > 0) {
                    $insertData['assigned_to'] = $noteAssignedTo;
                }
                $db->insert('cyber_todo_activities', $insertData);
                $auth->audit($user['id'], 'case_add_note', 'cyber_todo_activities', $requestId, [
                    'new' => ['title' => $noteTitle, 'assigned_to' => $noteAssignedTo ?: null, 'vendor_request_id' => $requestId]
                ]);
                redirect('vendor-onboarding.php?id=' . $requestId . '&tab=notes&success_note=1');
            } catch (Exception $e) {
                error_log('Add note error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_add_note_failed');
            }
        }
    }
}

// ============================================================
// ACTION PLAN HANDLERS (Vendor Remediation Schedule)
// Create / cancel / delete per-vendor scheduled actions. Restricted to
// admin and cyber_tprm. The "Vendor Remediation Schedule" cron later fires
// any pending action whose scheduled_date has arrived.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_scheduled_action']) && $editMode && $requestId > 0 && ($isAdmin || $isCyberTPRM)) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $saAction = trim($_POST['sa_action'] ?? '');
        $saDate = trim($security->cleanInput($_POST['sa_date'] ?? ''));
        $saDescription = trim($security->cleanInput($_POST['sa_description'] ?? ''));
        $saTemplateId = intval($_POST['sa_assessment_template_id'] ?? 0);
        $saNotify = !empty($_POST['sa_notify']) ? 1 : 0;
        $saNotifyEmails = sanitizeNotifyEmails($_POST['sa_notify_emails'] ?? '');

        // Assignees must be cyber_tprm members. Validate each id against the group.
        $saAssignees = [];
        $rawAssignees = $_POST['sa_assignees'] ?? [];
        if (is_array($rawAssignees) && !empty($rawAssignees)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $rawAssignees), function ($v) { return $v > 0; })));
            if (!empty($ids)) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $valid = $db->fetchAll(
                    "SELECT DISTINCT u.id FROM users u
                     JOIN user_acl_groups ug ON ug.user_id = u.id
                     JOIN acl_groups g ON g.id = ug.group_id
                     WHERE g.group_name = 'cyber_tprm' AND u.id IN ($ph)",
                    $ids
                );
                foreach ($valid as $v) { $saAssignees[] = (int)$v['id']; }
            }
        }

        $validActions = ['contact_vendor', 'contact_stakeholder', 'send_assessment', 'force_annual_review'];
        $dateOk = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $saDate) && strtotime($saDate) !== false;

        if (!in_array($saAction, $validActions, true)) {
            $error = t('vendor-onboarding.err_choose_valid_action');
        } elseif (!$dateOk) {
            $error = t('vendor-onboarding.err_choose_valid_date');
        } elseif ($saDescription === '') {
            $error = t('vendor-onboarding.err_description_required');
        } elseif ($saAction === 'send_assessment') {
            // The template must exist and be an active Vendor Assessment.
            $tpl = $db->fetchOne(
                "SELECT id FROM assessment_templates WHERE id = :id AND category = 'vendor_assessment' AND is_active = 1",
                [':id' => $saTemplateId]
            );
            if (!$tpl) {
                $error = t('vendor-onboarding.err_select_valid_assessment');
            }
        }

        if (empty($error)) {
            try {
                $db->insert('vendor_scheduled_actions', [
                    'request_id' => $requestId,
                    'action' => $saAction,
                    'assessment_template_id' => ($saAction === 'send_assessment' && $saTemplateId > 0) ? $saTemplateId : null,
                    'scheduled_date' => $saDate,
                    'description' => $saDescription,
                    'assignees' => !empty($saAssignees) ? json_encode($saAssignees) : null,
                    'notify_assignees' => $saNotify,
                    'notify_emails' => $saNotifyEmails !== '' ? $saNotifyEmails : null,
                    'status' => 'pending',
                    'created_by' => $user['id'],
                ]);
                $auth->audit($user['id'], 'scheduled_action_create', 'vendor_scheduled_actions', $requestId, [
                    'new' => ['action' => $saAction, 'scheduled_date' => $saDate, 'assessment_template_id' => $saTemplateId ?: null, 'assignees' => $saAssignees, 'notify_assignees' => $saNotify, 'vendor_request_id' => $requestId]
                ]);
                redirect('vendor-onboarding.php?id=' . $requestId . '&tab=action_plan&success_action=1');
            } catch (Exception $e) {
                error_log('Create scheduled action error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_create_scheduled_action_failed');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_scheduled_action']) && $editMode && $requestId > 0 && ($isAdmin || $isCyberTPRM)) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $saId = intval($_POST['scheduled_action_id'] ?? 0);
        if ($saId > 0) {
            try {
                // Only a still-pending action belonging to THIS vendor can be cancelled.
                $db->query(
                    "UPDATE vendor_scheduled_actions SET status = 'cancelled', updated_at = NOW()
                     WHERE id = :id AND request_id = :rid AND status = 'pending'",
                    [':id' => $saId, ':rid' => $requestId]
                );
                $auth->audit($user['id'], 'scheduled_action_cancel', 'vendor_scheduled_actions', $saId, [
                    'new' => ['status' => 'cancelled', 'vendor_request_id' => $requestId]
                ]);
                redirect('vendor-onboarding.php?id=' . $requestId . '&tab=action_plan&success_action=2');
            } catch (Exception $e) {
                error_log('Cancel scheduled action error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_cancel_scheduled_action_failed');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_scheduled_action']) && $editMode && $requestId > 0 && ($isAdmin || $isCyberTPRM)) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $saId = intval($_POST['scheduled_action_id'] ?? 0);
        if ($saId > 0) {
            try {
                // Vendor scope is enforced in the WHERE clause as defense in depth.
                $db->query(
                    "DELETE FROM vendor_scheduled_actions WHERE id = :id AND request_id = :rid",
                    [':id' => $saId, ':rid' => $requestId]
                );
                $auth->audit($user['id'], 'scheduled_action_delete', 'vendor_scheduled_actions', $saId, [
                    'new' => ['vendor_request_id' => $requestId]
                ]);
                redirect('vendor-onboarding.php?id=' . $requestId . '&tab=action_plan&success_action=3');
            } catch (Exception $e) {
                error_log('Delete scheduled action error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_delete_scheduled_action_failed');
            }
        }
    }
}

// Edit a scheduled action. Status is always editable; the schedule fields are
// only editable while the action has not yet fired (executed_at IS NULL).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_scheduled_action']) && $editMode && $requestId > 0 && ($isAdmin || $isCyberTPRM)) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $saId = intval($_POST['scheduled_action_id'] ?? 0);
        $row = $saId > 0 ? $db->fetchOne("SELECT * FROM vendor_scheduled_actions WHERE id = :id AND request_id = :rid", [':id' => $saId, ':rid' => $requestId]) : null;
        if (!$row) {
            $error = t('vendor-onboarding.err_scheduled_action_not_found');
        } else {
            $validStatuses = ['pending', 'in_progress', 'problem', 'completed', 'cancelled'];
            $newStatus = in_array(($_POST['sa_status'] ?? ''), $validStatuses, true) ? $_POST['sa_status'] : $row['status'];

            $update = ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')];
            $auditNew = ['status' => $newStatus];

            // Schedule fields are only editable before the action fires.
            if (empty($row['executed_at']) && isset($_POST['sa_action'])) {
                $eAction = trim($_POST['sa_action'] ?? '');
                $eDate = trim($security->cleanInput($_POST['sa_date'] ?? ''));
                $eDescription = trim($security->cleanInput($_POST['sa_description'] ?? ''));
                $eTemplateId = intval($_POST['sa_assessment_template_id'] ?? 0);
                $eNotify = !empty($_POST['sa_notify']) ? 1 : 0;
                $eNotifyEmails = sanitizeNotifyEmails($_POST['sa_notify_emails'] ?? '');
                $validActions = ['contact_vendor', 'contact_stakeholder', 'send_assessment', 'force_annual_review'];
                $dateOk = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $eDate) && strtotime($eDate) !== false;

                // Validate assignees against cyber_tprm membership.
                $eAssignees = [];
                $rawA = $_POST['sa_assignees'] ?? [];
                if (is_array($rawA) && !empty($rawA)) {
                    $ids = array_values(array_unique(array_filter(array_map('intval', $rawA), function ($v) { return $v > 0; })));
                    if (!empty($ids)) {
                        $ph = implode(',', array_fill(0, count($ids), '?'));
                        $valid = $db->fetchAll(
                            "SELECT DISTINCT u.id FROM users u
                             JOIN user_acl_groups ug ON ug.user_id = u.id
                             JOIN acl_groups g ON g.id = ug.group_id
                             WHERE g.group_name = 'cyber_tprm' AND u.id IN ($ph)",
                            $ids
                        );
                        foreach ($valid as $v) { $eAssignees[] = (int)$v['id']; }
                    }
                }

                if (!in_array($eAction, $validActions, true)) {
                    $error = t('vendor-onboarding.err_choose_valid_action');
                } elseif (!$dateOk) {
                    $error = t('vendor-onboarding.err_choose_valid_date');
                } elseif ($eDescription === '') {
                    $error = t('vendor-onboarding.err_description_required');
                } elseif ($eAction === 'send_assessment') {
                    $tpl = $db->fetchOne("SELECT id FROM assessment_templates WHERE id = :id AND category = 'vendor_assessment' AND is_active = 1", [':id' => $eTemplateId]);
                    if (!$tpl) { $error = 'Please select a valid Vendor Assessment.'; }
                }

                if (empty($error)) {
                    $update['action'] = $eAction;
                    $update['assessment_template_id'] = ($eAction === 'send_assessment' && $eTemplateId > 0) ? $eTemplateId : null;
                    $update['scheduled_date'] = $eDate;
                    $update['description'] = $eDescription;
                    $update['assignees'] = !empty($eAssignees) ? json_encode($eAssignees) : null;
                    $update['notify_assignees'] = $eNotify;
                    $update['notify_emails'] = $eNotifyEmails !== '' ? $eNotifyEmails : null;
                    $auditNew = array_merge($auditNew, ['action' => $eAction, 'scheduled_date' => $eDate, 'assignees' => $eAssignees, 'notify_assignees' => $eNotify]);
                }
            }

            if (empty($error)) {
                try {
                    $db->update('vendor_scheduled_actions', $update, 'id = :id AND request_id = :rid', [':id' => $saId, ':rid' => $requestId]);
                    $auth->audit($user['id'], 'scheduled_action_edit', 'vendor_scheduled_actions', $saId, ['new' => $auditNew]);
                    redirect('vendor-onboarding.php?id=' . $requestId . '&tab=action_plan&action_id=' . $saId . '&success_action=4');
                } catch (Exception $e) {
                    error_log('Edit scheduled action error: ' . $e->getMessage());
                    $error = t('vendor-onboarding.err_update_scheduled_action_failed');
                }
            }
        }
    }
}

// Add a status note to a scheduled action (stored as a cyber_todo_activities row
// keyed to the action, mirroring the case-note model).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_scheduled_action_note']) && $editMode && $requestId > 0 && ($isAdmin || $isCyberTPRM)) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $saId = intval($_POST['scheduled_action_id'] ?? 0);
        $noteText = trim($security->cleanInput($_POST['sa_note'] ?? ''));
        $row = $saId > 0 ? $db->fetchOne("SELECT id FROM vendor_scheduled_actions WHERE id = :id AND request_id = :rid", [':id' => $saId, ':rid' => $requestId]) : null;
        if (!$row) {
            $error = t('vendor-onboarding.err_scheduled_action_not_found');
        } elseif ($noteText === '') {
            $error = t('vendor-onboarding.err_enter_note');
        } else {
            try {
                $db->insert('cyber_todo_activities', [
                    'todo_type' => 'custom',
                    'reference_type' => 'vendor_scheduled_actions',
                    'reference_id' => $saId,
                    'activity_type' => 'note',
                    'description' => $noteText,
                    'status' => 'open',
                    'created_by' => $user['id'],
                ]);
                $auth->audit($user['id'], 'scheduled_action_add_note', 'vendor_scheduled_actions', $saId, ['new' => ['vendor_request_id' => $requestId]]);
                redirect('vendor-onboarding.php?id=' . $requestId . '&tab=action_plan&action_id=' . $saId . '&success_action=5');
            } catch (Exception $e) {
                error_log('Add scheduled action note error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_add_the_note_failed');
            }
        }
    }
}

// Edit / delete a status note on a scheduled action. Allowed for the note's
// author or an administrator. The note must belong to a scheduled action of THIS
// vendor (joined via vendor_scheduled_actions.request_id) -- defense in depth so a
// forged id cannot reach another vendor's note.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_scheduled_action_note']) && $editMode && $requestId > 0 && ($isAdmin || $isCyberTPRM)) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $noteId = intval($_POST['note_id'] ?? 0);
        $saId = intval($_POST['scheduled_action_id'] ?? 0);
        $noteText = trim($security->cleanInput($_POST['sa_note'] ?? ''));
        $noteRow = $noteId > 0 ? $db->fetchOne(
            "SELECT a.id, a.created_by FROM cyber_todo_activities a
             JOIN vendor_scheduled_actions sa ON a.reference_id = sa.id
             WHERE a.id = :id AND a.reference_type = 'vendor_scheduled_actions' AND sa.request_id = :rid",
            [':id' => $noteId, ':rid' => $requestId]
        ) : null;
        if (!$noteRow) {
            $error = t('vendor-onboarding.err_status_note_not_found');
        } elseif (!$isAdmin && intval($noteRow['created_by']) !== intval($user['id'])) {
            $error = t('vendor-onboarding.err_edit_own_status_notes');
        } elseif ($noteText === '') {
            $error = t('vendor-onboarding.err_enter_note');
        } else {
            try {
                if ($isAdmin) {
                    $db->query("UPDATE cyber_todo_activities SET description = :d, updated_at = NOW() WHERE id = :id", [':d' => $noteText, ':id' => $noteId]);
                } else {
                    $db->query("UPDATE cyber_todo_activities SET description = :d, updated_at = NOW() WHERE id = :id AND created_by = :uid", [':d' => $noteText, ':id' => $noteId, ':uid' => $user['id']]);
                }
                $auth->audit($user['id'], 'scheduled_action_edit_note', 'cyber_todo_activities', $noteId, ['new' => ['vendor_request_id' => $requestId, 'scheduled_action_id' => $saId]]);
                redirect('vendor-onboarding.php?id=' . $requestId . '&tab=action_plan&action_id=' . $saId . '&success_action=6');
            } catch (Exception $e) {
                error_log('Edit scheduled action note error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_update_note_failed');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_scheduled_action_note']) && $editMode && $requestId > 0 && ($isAdmin || $isCyberTPRM)) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $noteId = intval($_POST['note_id'] ?? 0);
        $saId = intval($_POST['scheduled_action_id'] ?? 0);
        $noteRow = $noteId > 0 ? $db->fetchOne(
            "SELECT a.id, a.created_by FROM cyber_todo_activities a
             JOIN vendor_scheduled_actions sa ON a.reference_id = sa.id
             WHERE a.id = :id AND a.reference_type = 'vendor_scheduled_actions' AND sa.request_id = :rid",
            [':id' => $noteId, ':rid' => $requestId]
        ) : null;
        if (!$noteRow) {
            $error = t('vendor-onboarding.err_status_note_not_found');
        } elseif (!$isAdmin && intval($noteRow['created_by']) !== intval($user['id'])) {
            $error = t('vendor-onboarding.err_delete_own_status_notes');
        } else {
            try {
                if ($isAdmin) {
                    $db->query("DELETE FROM cyber_todo_activities WHERE id = :id", [':id' => $noteId]);
                } else {
                    $db->query("DELETE FROM cyber_todo_activities WHERE id = :id AND created_by = :uid", [':id' => $noteId, ':uid' => $user['id']]);
                }
                $auth->audit($user['id'], 'scheduled_action_delete_note', 'cyber_todo_activities', $noteId, ['new' => ['vendor_request_id' => $requestId, 'scheduled_action_id' => $saId]]);
                redirect('vendor-onboarding.php?id=' . $requestId . '&tab=action_plan&action_id=' . $saId . '&success_action=7');
            } catch (Exception $e) {
                error_log('Delete scheduled action note error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_delete_note_failed');
            }
        }
    }
}

// ============================================================
// CASE / REPLY EDIT + DELETE HANDLERS (author-only)
// A note's author may edit or delete their own case or reply. Ownership
// (created_by) AND vendor scope (reference_type/reference_id) are re-checked
// server-side -- mirroring the cyber-todo activity edit/delete model -- so a
// forged id cannot touch another user's note or a note on another vendor even
// with a scraped CSRF token. The created_by guard is repeated in the write's
// WHERE clause as defense in depth.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_case']) && $editMode && $requestId > 0 && ($isAdmin || $isProcurement || $isCyberTPRM || $isStakeholder) && !$isProcurementOnly) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $editCaseId = intval($_POST['case_id'] ?? 0);
        $newTitle = trim($security->cleanInput($_POST['note_title'] ?? ''));
        $newDescription = trim($security->cleanInput($_POST['note_description'] ?? ''));
        if ($editCaseId <= 0) {
            $error = t('vendor-onboarding.err_invalid_case');
        } elseif (empty($newTitle)) {
            $error = t('vendor-onboarding.err_note_title_required');
        } else {
            try {
                $caseRow = $db->fetchOne(
                    "SELECT id, created_by, status FROM cyber_todo_activities WHERE id = :id AND reference_type = 'vendor_onboarding_requests' AND reference_id = :rid AND (parent_id IS NULL OR parent_id = 0)",
                    [':id' => $editCaseId, ':rid' => $requestId]
                );
                if (!$caseRow) {
                    $error = t('vendor-onboarding.err_case_not_found');
                } elseif (!$isAdmin && intval($caseRow['created_by']) !== intval($user['id'])) {
                    $error = t('vendor-onboarding.err_edit_own_cases');
                } elseif ($caseRow['status'] === 'closed') {
                    $error = t('vendor-onboarding.err_closed_case_edit');
                } else {
                    // Administrators may edit any case; authors only their own (guard kept in WHERE).
                    if ($isAdmin) {
                        $db->query(
                            "UPDATE cyber_todo_activities SET title = :title, description = :description, updated_at = NOW() WHERE id = :id",
                            [':title' => $newTitle, ':description' => $newDescription, ':id' => $editCaseId]
                        );
                    } else {
                        $db->query(
                            "UPDATE cyber_todo_activities SET title = :title, description = :description, updated_at = NOW() WHERE id = :id AND created_by = :uid",
                            [':title' => $newTitle, ':description' => $newDescription, ':id' => $editCaseId, ':uid' => $user['id']]
                        );
                    }
                    $auth->audit($user['id'], 'case_edit_note', 'cyber_todo_activities', $editCaseId, [
                        'new' => ['title' => $newTitle, 'vendor_request_id' => $requestId]
                    ]);
                    redirect('vendor-onboarding.php?id=' . $requestId . '&tab=notes');
                }
            } catch (Exception $e) {
                error_log('Edit case error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_update_case_failed');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_case']) && $editMode && $requestId > 0 && ($isAdmin || $isProcurement || $isCyberTPRM || $isStakeholder) && !$isProcurementOnly) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $deleteCaseId = intval($_POST['case_id'] ?? 0);
        if ($deleteCaseId <= 0) {
            $error = t('vendor-onboarding.err_invalid_case');
        } else {
            try {
                $caseRow = $db->fetchOne(
                    "SELECT id, created_by, status FROM cyber_todo_activities WHERE id = :id AND reference_type = 'vendor_onboarding_requests' AND reference_id = :rid AND (parent_id IS NULL OR parent_id = 0)",
                    [':id' => $deleteCaseId, ':rid' => $requestId]
                );
                if (!$caseRow) {
                    $error = t('vendor-onboarding.err_case_not_found');
                } elseif (!$isAdmin && intval($caseRow['created_by']) !== intval($user['id'])) {
                    $error = t('vendor-onboarding.err_delete_own_cases');
                } elseif ($caseRow['status'] === 'closed') {
                    $error = t('vendor-onboarding.err_closed_case_delete');
                } else {
                    // For a non-admin author, don't let a delete destroy other users'
                    // responses -- if anyone else replied, the thread is shared and must
                    // be Closed, not deleted. Administrators may remove the whole thread.
                    $otherReply = null;
                    if (!$isAdmin) {
                        $otherReply = $db->fetchOne(
                            "SELECT id FROM cyber_todo_activities WHERE parent_id = :pid AND reference_type = 'vendor_onboarding_requests' AND reference_id = :rid AND created_by != :uid LIMIT 1",
                            [':pid' => $deleteCaseId, ':rid' => $requestId, ':uid' => $user['id']]
                        );
                    }
                    if ($otherReply) {
                        $error = t('vendor-onboarding.err_case_has_responses');
                    } else {
                        // Remove the case and all of its replies (admin), or the author's
                        // own case + own replies (non-admin -- guaranteed no others above).
                        $db->query(
                            "DELETE FROM cyber_todo_activities WHERE reference_type = 'vendor_onboarding_requests' AND reference_id = :rid AND (id = :id OR parent_id = :pid)",
                            [':rid' => $requestId, ':id' => $deleteCaseId, ':pid' => $deleteCaseId]
                        );
                        $auth->audit($user['id'], 'case_delete_note', 'cyber_todo_activities', $deleteCaseId, [
                            'new' => ['vendor_request_id' => $requestId, 'as_admin' => $isAdmin ? 1 : 0]
                        ]);
                        redirect('vendor-onboarding.php?id=' . $requestId . '&tab=notes');
                    }
                }
            } catch (Exception $e) {
                error_log('Delete case error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_delete_case_failed');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_reply']) && $editMode && $requestId > 0 && ($isAdmin || $isProcurement || $isCyberTPRM || $isStakeholder) && !$isProcurementOnly) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $editReplyId = intval($_POST['reply_id'] ?? 0);
        $newDescription = trim($security->cleanInput($_POST['reply_description'] ?? ''));
        if ($editReplyId <= 0) {
            $error = t('vendor-onboarding.err_invalid_reply');
        } elseif (empty($newDescription)) {
            $error = t('vendor-onboarding.err_reply_text_required');
        } else {
            try {
                $replyRow = $db->fetchOne(
                    "SELECT a.id, a.created_by, p.status AS parent_status
                     FROM cyber_todo_activities a
                     JOIN cyber_todo_activities p ON a.parent_id = p.id
                     WHERE a.id = :id AND a.reference_type = 'vendor_onboarding_requests' AND a.reference_id = :rid AND a.parent_id IS NOT NULL AND a.parent_id > 0",
                    [':id' => $editReplyId, ':rid' => $requestId]
                );
                if (!$replyRow) {
                    $error = t('vendor-onboarding.err_reply_not_found');
                } elseif (!$isAdmin && intval($replyRow['created_by']) !== intval($user['id'])) {
                    $error = t('vendor-onboarding.err_edit_own_replies');
                } elseif ($replyRow['parent_status'] === 'closed') {
                    $error = t('vendor-onboarding.err_closed_case_response_edit');
                } else {
                    if ($isAdmin) {
                        $db->query(
                            "UPDATE cyber_todo_activities SET description = :description, updated_at = NOW() WHERE id = :id",
                            [':description' => $newDescription, ':id' => $editReplyId]
                        );
                    } else {
                        $db->query(
                            "UPDATE cyber_todo_activities SET description = :description, updated_at = NOW() WHERE id = :id AND created_by = :uid",
                            [':description' => $newDescription, ':id' => $editReplyId, ':uid' => $user['id']]
                        );
                    }
                    $auth->audit($user['id'], 'case_edit_reply', 'cyber_todo_activities', $editReplyId, [
                        'new' => ['vendor_request_id' => $requestId]
                    ]);
                    redirect('vendor-onboarding.php?id=' . $requestId . '&tab=notes');
                }
            } catch (Exception $e) {
                error_log('Edit reply error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_update_reply_failed');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_reply']) && $editMode && $requestId > 0 && ($isAdmin || $isProcurement || $isCyberTPRM || $isStakeholder) && !$isProcurementOnly) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } else {
        $deleteReplyId = intval($_POST['reply_id'] ?? 0);
        if ($deleteReplyId <= 0) {
            $error = t('vendor-onboarding.err_invalid_reply');
        } else {
            try {
                $replyRow = $db->fetchOne(
                    "SELECT a.id, a.created_by, p.status AS parent_status
                     FROM cyber_todo_activities a
                     JOIN cyber_todo_activities p ON a.parent_id = p.id
                     WHERE a.id = :id AND a.reference_type = 'vendor_onboarding_requests' AND a.reference_id = :rid AND a.parent_id IS NOT NULL AND a.parent_id > 0",
                    [':id' => $deleteReplyId, ':rid' => $requestId]
                );
                if (!$replyRow) {
                    $error = t('vendor-onboarding.err_reply_not_found');
                } elseif (!$isAdmin && intval($replyRow['created_by']) !== intval($user['id'])) {
                    $error = t('vendor-onboarding.err_delete_own_replies');
                } elseif ($replyRow['parent_status'] === 'closed') {
                    $error = t('vendor-onboarding.err_closed_case_response_delete');
                } else {
                    if ($isAdmin) {
                        $db->query(
                            "DELETE FROM cyber_todo_activities WHERE id = :id",
                            [':id' => $deleteReplyId]
                        );
                    } else {
                        $db->query(
                            "DELETE FROM cyber_todo_activities WHERE id = :id AND created_by = :uid",
                            [':id' => $deleteReplyId, ':uid' => $user['id']]
                        );
                    }
                    $auth->audit($user['id'], 'case_delete_reply', 'cyber_todo_activities', $deleteReplyId, [
                        'new' => ['vendor_request_id' => $requestId]
                    ]);
                    redirect('vendor-onboarding.php?id=' . $requestId . '&tab=notes');
                }
            } catch (Exception $e) {
                error_log('Delete reply error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_delete_reply_failed');
            }
        }
    }
}

// ============================================================
// FORM SUBMISSION HANDLING
// This is the big POST handler that processes saves, submissions,
// deletions, deactivations, status changes, and stakeholder
// reassignments. It's basically a Swiss Army knife of form processing.
// ============================================================
// TIER CHANGE -- handled separately from the main form because it uses a modal,
// not the main save flow. Same audit-trail logic as vendor-srs-details.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tier']) && $canEditTier && $editMode && $requestId > 0) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf_retry');
    } else {
        $newTier = $_POST['new_tier'] ?? '';
        $justification = trim($_POST['tier_justification'] ?? '');

        if (!in_array($newTier, ['', '1', '2', '3'])) {
            $error = t('vendor-onboarding.err_invalid_tier');
        } elseif (empty($justification)) {
            $error = t('vendor-onboarding.err_tier_justification_required');
        } else {
            try {
                $oldTier = $request['vendor_tier'] ?? '';
                $userName = $user['full_name'] ?? $user['username'] ?? 'Unknown User';
                $tierMap = [
                    '' => 'Not Assigned',
                    '1' => 'Tier 1 - Critical (Monthly rescoring)',
                    '2' => 'Tier 2 - Standard (90-day rescoring)',
                    '3' => 'Tier 3 - Low Priority (Annual rescoring)'
                ];
                $logEntry = "\n\n--- Tier Change Log ---\nDate: " . date('m/d/Y, h:i A') . "\nUser: {$userName}\nChanged From: " . ($tierMap[$oldTier] ?? 'Unknown') . "\nChanged To: " . ($tierMap[$newTier] ?? 'Unknown') . "\nReason: {$justification}\n-----------------------";

                $db->update('vendor_onboarding_requests', [
                    'vendor_tier' => $newTier === '' ? null : $newTier,
                    'additional_information' => ($request['additional_information'] ?? '') . $logEntry
                ], 'id = :id', [':id' => $requestId]);
                $auth->audit($user['id'], 'vendor_tier_update', 'vendor_onboarding_requests', $requestId, [
                    'old' => ['vendor_tier' => $oldTier],
                    'new' => ['vendor_tier' => $newTier, 'justification' => $justification]
                ]);

                $success = t('vendor-onboarding.ok_tier_updated');
                // Refresh request data so the page shows the new tier
                $request = $db->fetchOne(
                    'SELECT * FROM vendor_onboarding_requests WHERE id = :id',
                    [':id' => $requestId]
                );
            } catch (Exception $e) {
                error_log('Tier update error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_update_tier_failed');
            }
        }
    }
}

// Handle rescore from Risks tab
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['rescore']) || isset($_POST['rescore_upguard']) || isset($_POST['rescore_shodan'])) && $editMode && $requestId > 0 && ($isAdmin || $isCyberTPRM)) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf');
    } elseif (($request['status'] ?? '') === 'inactive') {
        $error = t('vendor-onboarding.err_score_inactive_vendor');
    } elseif (empty($request['vendor_domain'])) {
        $error = t('vendor-onboarding.err_score_no_domain');
    } elseif (!$upguardAvailable && !$shodanAvailable) {
        $error = t('vendor-onboarding.err_no_srs_integration');
    } else {
        $scoreType = 'all';
        if (isset($_POST['rescore_upguard'])) $scoreType = 'upguard';
        elseif (isset($_POST['rescore_shodan'])) $scoreType = 'shodan';

        $useCron = false;
        if ($scoreType === 'upguard') $useCron = $upguardUseCron;
        elseif ($scoreType === 'shodan') $useCron = $shodanUseCron;
        else $useCron = ($upguardUseCron || $shodanUseCron);

        if ($useCron) {
            $statusLabel = $scoreType === 'all' ? 'rescoring' : 'rescoring_' . $scoreType;
            $db->query(
                'UPDATE vendor_onboarding_requests SET rescore_status = ?, rescore_started_at = NOW(), rescore_result = NULL WHERE id = ?',
                [$statusLabel, $requestId]
            );
            header('Location: vendor-onboarding.php?id=' . $requestId . '&tab=risks');
            exit;
        }

        // Realtime scoring
        $domain = $request['vendor_domain'];
        $messages = [];
        $scoreErrors = [];

        if (($scoreType === 'all' || $scoreType === 'upguard') && $upguardAvailable) {
            try {
                $result = $srsService->scoreVendor($requestId, $domain, $request['vendor_name']);
                if ($result) {
                    $messages[] = $upguardDisplayName . ": {$result['score']} ({$result['grade']})";
                } else {
                    $scoreErrors[] = $upguardDisplayName . ': ' . ($srsService->getLastError() ?? 'Unknown error');
                }
            } catch (Exception $e) {
                $scoreErrors[] = $upguardDisplayName . ': ' . $e->getMessage();
            }
        }

        if (($scoreType === 'all' || $scoreType === 'shodan') && $shodanAvailable) {
            try {
                $shodanResult = $shodanService->scoreVendor($requestId, $domain);
                if ($shodanResult) {
                    $messages[] = $shodanDisplayName . ": {$shodanResult['score']} ({$shodanResult['grade']})";
                } else {
                    $scoreErrors[] = $shodanDisplayName . ': ' . ($shodanService->getLastError() ?? 'Unknown error');
                }
            } catch (Exception $e) {
                $scoreErrors[] = $shodanDisplayName . ': ' . $e->getMessage();
            }
        }

        if (!empty($messages)) $success = implode(' | ', $messages);
        if (!empty($scoreErrors)) $error = 'ERRORS: ' . implode(' | ', $scoreErrors);

        if (!empty($messages)) {
            try {
                $auth->audit($user['id'], 'vendor_rescore', 'vendor_onboarding_requests', $requestId, [
                    'new' => ['results' => implode(' | ', $messages)]
                ]);
            } catch (Exception $e) {}
        }

        // Re-fetch vendor data after scoring
        $request = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $requestId]);
    }
}

// Handle template-based onboarding: create draft vendor record + assessment, then redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_template_onboarding']) && !$editMode && $canCreate) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('vendor-onboarding.err_invalid_request_retry');
    } else {
        $selectedTemplateId = intval($_POST['template_id'] ?? 0);
        if ($selectedTemplateId > 0) {
            // Create draft vendor record
            $newRequestId = $db->insert('vendor_onboarding_requests', [
                'status' => 'draft',
                'created_by' => $user['id'],
                'last_autosave' => date('Y-m-d H:i:s'),
            ]);
            // Stakeholder ownership
            $db->insert('vendor_onboarding_stakeholders', [
                'request_id' => $newRequestId,
                'user_id' => $user['id'],
                'role' => 'owner',
                'assigned_by' => $user['id']
            ]);
            // Create assessment and redirect
            if (!class_exists('VendorAssessmentService')) {
                require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
            }
            $svc = new VendorAssessmentService();
            $result = $svc->createAssessment(
                $selectedTemplateId, '(New Vendor)',
                $user['email'] ?? '', $user['full_name'] ?? '', $user['email'] ?? '',
                $newRequestId, $user['id'], 365
            );
            redirect('vendor-assessment.php?token=' . $result['uuid']);
        }
    }
}

// Administrative action handlers -- delete, deactivate, status transitions
$canProcessForm = $canEdit || $canDeactivate || $canDelete;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canProcessForm && !isset($_POST['update_tier'])) {
    // CSRF check first -- always. No token, no service.
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-onboarding.err_csrf_retry');
    } else {
        $action = $_POST['action'] ?? '';

        // DELETE: The nuclear option. Wipes stakeholder links first, then the request itself.
        // Owners can only delete drafts; admins/cyber_tprm can delete any status.
        if ($action === 'delete' && $canDelete && $editMode && $requestId > 0) {
            if (!$canDeleteAll && $request['status'] !== 'draft') {
                $error = t('vendor-onboarding.err_delete_draft_only');
            } else {
            try {
                $vendorName = $request['vendor_name'] ?? '';
                $auth->audit($user['id'], 'vendor_delete', 'vendor_onboarding_requests', $requestId, [
                    'old' => ['vendor_name' => $vendorName, 'status' => $request['status'] ?? '']
                ]);
                $db->query('DELETE FROM vendor_assessment_responses WHERE assessment_id IN (SELECT id FROM vendor_assessments WHERE vendor_request_id = :rid)', [':rid' => $requestId]);
                $db->delete('vendor_assessments', 'vendor_request_id = :rid', [':rid' => $requestId]);
                $db->delete('vendor_onboarding_stakeholders', 'request_id = :rid', [':rid' => $requestId]);
                $db->delete('vendor_onboarding_requests', 'id = :id', [':id' => $requestId]);
                redirect('vendor-onboarding-list.php?success=deleted');
            } catch (Exception $e) {
                error_log('Delete error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_delete_request_failed');
            }
            }
        }

        // DEACTIVATE: Soft-delete basically. Marks the request as inactive with a timestamp
        // and who pulled the trigger. The data sticks around for audit purposes.
        if ($action === 'deactivate' && $canDeactivate && $editMode && $requestId > 0) {
            try {
                $db->update('vendor_onboarding_requests', [
                    'status' => 'inactive',
                    'marked_inactive_at' => date('Y-m-d H:i:s'),
                    'marked_inactive_by' => $user['id']
                ], 'id = :id', [':id' => $requestId]);
                $auth->audit($user['id'], 'vendor_deactivate', 'vendor_onboarding_requests', $requestId, [
                    'old' => ['status' => $request['status'] ?? ''],
                    'new' => ['status' => 'inactive', 'vendor_name' => $request['vendor_name'] ?? '']
                ]);
                redirect('vendor-onboarding-list.php?success=deactivated');
            } catch (Exception $e) {
                error_log('Deactivate error: ' . $e->getMessage());
                $error = t('vendor-onboarding.err_deactivate_request_failed');
            }
        }

        // SET IN REVIEW: Moves the status to "in_review" -- the cyber team is now looking at it.
        if ($action === 'set_in_review' && $canApprove && $editMode && $requestId > 0) {
            if (!in_array($request['status'], ['submitted', 'approved', 'inactive'])) {
                $error = t('vendor-onboarding.err_mark_in_review_status');
            } else {
                try {
                    $db->update('vendor_onboarding_requests', [
                        'status' => 'in_review'
                    ], 'id = :id', [':id' => $requestId]);
                    $auth->audit($user['id'], 'vendor_set_in_review', 'vendor_onboarding_requests', $requestId, [
                        'old' => ['status' => $request['status']],
                        'new' => ['status' => 'in_review', 'vendor_name' => $request['vendor_name'] ?? '']
                    ]);
                    redirect('vendor-onboarding-list.php?success=in_review');
                } catch (Exception $e) {
                    error_log('In Review error: ' . $e->getMessage());
                    $error = t('vendor-onboarding.err_mark_in_review_failed');
                }
            }
        }

        // FORCE AI REVIEW: for vendors flagged "Services Use AI", move the request
        // into the ai_review status so the AI-usage review can proceed.
        if ($action === 'force_ai_review' && $canApprove && $editMode && $requestId > 0) {
            if (($request['vendor_use_ai'] ?? '') !== 'yes') {
                $error = t('vendor-onboarding.err_ai_review_requires_ai');
            } elseif (($request['status'] ?? '') === 'ai_review') {
                $error = t('vendor-onboarding.err_already_in_ai_review');
            } else {
                try {
                    $db->update('vendor_onboarding_requests', [
                        'status' => 'ai_review'
                    ], 'id = :id', [':id' => $requestId]);
                    $auth->audit($user['id'], 'vendor_force_ai_review', 'vendor_onboarding_requests', $requestId, [
                        'old' => ['status' => $request['status']],
                        'new' => ['status' => 'ai_review', 'vendor_name' => $request['vendor_name'] ?? '']
                    ]);
                    redirect('vendor-onboarding.php?id=' . $requestId . '&success_ai_review=1');
                } catch (Exception $e) {
                    error_log('Force AI Review error: ' . $e->getMessage());
                    $error = t('vendor-onboarding.err_move_ai_review_failed');
                }
            }
        }

        // SET DRAFT: Reactivates an inactive vendor back to draft status.
        if ($action === 'set_draft' && $canApprove && $editMode && $requestId > 0) {
            if ($request['status'] !== 'inactive') {
                $error = t('vendor-onboarding.err_back_to_draft_status');
            } else {
                try {
                    $db->update('vendor_onboarding_requests', [
                        'status' => 'draft'
                    ], 'id = :id', [':id' => $requestId]);
                    $auth->audit($user['id'], 'vendor_set_draft', 'vendor_onboarding_requests', $requestId, [
                        'old' => ['status' => 'inactive'],
                        'new' => ['status' => 'draft', 'vendor_name' => $request['vendor_name'] ?? '']
                    ]);
                    redirect('vendor-onboarding.php?id=' . $requestId . '&success_reactivated=1');
                } catch (Exception $e) {
                    error_log('Set Draft error: ' . $e->getMessage());
                    $error = t('vendor-onboarding.err_back_to_draft_failed');
                }
            }
        }

        // APPROVE: Sets status to 'approved' and calculates annual review due date.
        if (($action === 'approve' || $action === 'set_approved') && $canApprove && $editMode && $requestId > 0) {
            if (!in_array($request['status'], ['draft', 'submitted', 'in_review', 'inactive'])) {
                $error = t('vendor-onboarding.err_approve_status');
            } else {
                try {
                    $approvalDate = date('Y-m-d H:i:s');
                    $reviewDueDate = date('Y-m-d', strtotime('+365 days'));

                    $updateData = [
                        'status' => 'approved',
                        'submitted_at' => $approvalDate,
                        'last_annual_review_due' => $reviewDueDate
                    ];
                    // If vendor_id is null, assign placeholder vendor_id
                    if (empty($request['vendor_id']) || $request['vendor_id'] === null) {
                        $updateData['vendor_id'] = 99999;
                    }
                    $db->update('vendor_onboarding_requests', $updateData, 'id = :id', [':id' => $requestId]);
                    $auth->audit($user['id'], 'vendor_approve', 'vendor_onboarding_requests', $requestId, [
                        'old' => ['status' => $request['status']],
                        'new' => ['status' => 'approved', 'vendor_name' => $request['vendor_name'] ?? '', 'review_due' => $reviewDueDate]
                    ]);
                    redirect('vendor-onboarding-list.php?success=approved');
                } catch (Exception $e) {
                    error_log('Approve error: ' . $e->getMessage());
                    $error = t('vendor-onboarding.err_approve_failed');
                }
            }
        }

        // SUBMIT FOR REVIEW: Reads VID/VSU status from DB (not POST), validates, sets status=submitted.
        if ($action === 'submit_for_review' && $canEdit && $editMode && $requestId > 0) {
            if ($request['status'] !== 'draft') {
                $error = t('vendor-onboarding.err_submit_draft_only');
            } else {
                $vsuOnboarded = $request['vsu_onboarded'] ?? '';
                $vendorId = $request['vendor_id'] ?? '';

                if ($vsuOnboarded !== 'yes') {
                    $error = t('vendor-onboarding.err_submit_requires_vsu');
                } elseif (empty($vendorId) || !preg_match('/^\d{4,8}$/', $vendorId)) {
                    $error = t('vendor-onboarding.err_submit_requires_vid');
                } else {
                    try {
                        $db->update('vendor_onboarding_requests', [
                            'status' => 'submitted',
                            'submitted_at' => date('Y-m-d H:i:s')
                        ], 'id = :id', [':id' => $requestId]);
                        $auth->audit($user['id'], 'vendor_submit', 'vendor_onboarding_requests', $requestId, [
                            'old' => ['status' => 'draft'],
                            'new' => ['status' => 'submitted', 'vendor_name' => $request['vendor_name'] ?? '']
                        ]);
                        redirect('vendor-onboarding-list.php?success=submitted');
                    } catch (Exception $e) {
                        error_log('Submit for review error: ' . $e->getMessage());
                        $error = t('vendor-onboarding.err_submit_failed');
                    }
                }
            }
        }

        // UPDATE VENDOR FIELDS: Generic handler for inline card editing modals.
        if ($action === 'update_vendor_fields' && ($canEdit || $canEditProcurementFields) && $editMode && $requestId > 0) {
            $allowedFields = [
                'vendor_name', 'vendor_domain', 'vendor_sisterdomains', 'vendor_id', 'vendor_type',
                'vsu_onboarded', 'relationship_manager', 'expected_procurement_date',
                'product_service_description', 'target_user_count',
                'primary_contact_email', 'primary_contact_details',
                'primary_contact_title', 'primary_contact_phone', 'vat_number',
                'nda_in_place', 'vendor_competitors',
                'cost_center', 'project',
                'pii_record_count', 'spii_record_count', 'sox_record_count',
                'business_impact', 'unauthorized_disclosure_impact',
                'unauthorized_disclosure_justification', 'unauthorized_modification_impact',
                'disruption_impact',
                'confidential_info_shared', 'confidential_info_justification',
                'cross_border_transfer', 'cross_border_justification',
                'offsite_data_hosting', 'offsite_data_justification',
                'remote_network_access', 'remote_access_justification',
                'source_code_access', 'source_code_justification',
                'critical_business_function', 'critical_function_justification',
                'saml_sso_support', 'is_saas', 'vendor_use_ai', 'additional_information',
            ];

            // Numeric fields that must be NULL (not empty string) for DECIMAL/INT columns
            $numericFields = [
                'pii_record_count', 'spii_record_count', 'sox_record_count',
                'business_impact', 'target_user_count', 'cost_center',
            ];

            // Human-readable labels for validation messages
            $fieldLabels = [
                'vendor_name' => 'Vendor Name', 'vendor_domain' => 'Vendor Domain',
                'vendor_sisterdomains' => 'Sister/Subdomains', 'vendor_id' => 'Vendor ID',
                'vendor_type' => 'Vendor Type', 'vsu_onboarded' => 'Procurement Onboarding',
                'relationship_manager' => 'Relationship Manager',
                'expected_procurement_date' => 'Expected Procurement Date',
                'product_service_description' => 'Product/Service Description',
                'target_user_count' => 'Target User Count',
                'primary_contact_email' => 'Primary Contact Email',
                'primary_contact_details' => 'Contact Name',
                'primary_contact_title' => 'Contact Title',
                'primary_contact_phone' => 'Contact Phone', 'vat_number' => 'VAT Number',
                'nda_in_place' => 'NDA in Place', 'vendor_competitors' => 'Competitors',
                'pii_record_count' => 'PII Records', 'spii_record_count' => 'SPII Records',
                'sox_record_count' => 'SOX Records', 'business_impact' => 'Business Impact (USD)',
                'unauthorized_disclosure_impact' => 'Disclosure Impact',
                'unauthorized_disclosure_justification' => 'Disclosure Justification',
                'unauthorized_modification_impact' => 'Modification Impact',
                'disruption_impact' => 'Disruption Impact',
                'confidential_info_shared' => 'Confidential Info Shared',
                'confidential_info_justification' => 'Confidential Info Justification',
                'cross_border_transfer' => 'Cross-Border Transfer',
                'cross_border_justification' => 'Cross-Border Justification',
                'offsite_data_hosting' => 'Off-site Data Hosting',
                'offsite_data_justification' => 'Off-site Data Justification',
                'remote_network_access' => 'Remote Network Access',
                'remote_access_justification' => 'Remote Access Justification',
                'source_code_access' => 'Source Code Access',
                'source_code_justification' => 'Source Code Justification',
                'critical_business_function' => 'Critical Business Function',
                'critical_function_justification' => 'Critical Function Justification',
                'saml_sso_support' => 'SAML/SSO Support', 'is_saas' => 'SaaS Product',
                'vendor_use_ai' => 'Services Use AI',
                'cost_center' => 'Cost Center', 'project' => 'Project',
                'additional_information' => 'Additional Information',
            ];

            // Procurement-only users can only edit a limited set of fields
            if ($canEditProcurementFields && !$canEdit) {
                $allowedFields = ['vendor_name', 'vendor_type', 'vendor_id',
                    'primary_contact_details', 'primary_contact_email',
                    'primary_contact_title', 'primary_contact_phone'];
            }

            // Tier Justification (additional_information) is only editable by admin/cyber_tprm
            if (!$canEditTier) {
                $allowedFields = array_diff($allowedFields, ['additional_information']);
            }

            // Date fields that must be valid date format or empty
            $dateFields = ['expected_procurement_date'];

            $updateData = [];
            $validationErrors = [];
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $val = trim($_POST[$field]);
                    $label = $fieldLabels[$field] ?? $field;

                    // Strip scheme/path from a pasted URL so we store just the domain
                    if ($field === 'vendor_domain') {
                        $val = normalizeVendorDomain($val);
                    }

                    // Validate numeric fields
                    if (in_array($field, $numericFields)) {
                        if ($val === '') {
                            $val = null;
                        } elseif (!is_numeric($val)) {
                            $validationErrors[] = "$label must be a valid number.";
                            continue;
                        }
                    }

                    // Validate email
                    if ($field === 'primary_contact_email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        $validationErrors[] = "$label must be a valid email address.";
                        continue;
                    }

                    // Canonicalise the phone number to E.164 ("+13144445544") so it
                    // is stored uniformly regardless of how it was typed.
                    if ($field === 'primary_contact_phone') {
                        $val = phone_normalize_e164($val);
                    }

                    // Canonicalise the VAT number (uppercase, punctuation stripped).
                    // VIES validity is advisory and handled in the UI; we never block.
                    if ($field === 'vat_number') {
                        $val = vat_normalize($val);
                    }

                    // Validate date fields
                    if (in_array($field, $dateFields)) {
                        if ($val === '') {
                            $val = null;
                        } else {
                            $d = date_create($val);
                            if (!$d) {
                                $validationErrors[] = "$label must be a valid date.";
                                continue;
                            }
                        }
                    }

                    $updateData[$field] = $val;
                }
            }

            if (!empty($validationErrors)) {
                $error = implode(' ', $validationErrors);
                // Preserve the user's input so the form doesn't reset
                foreach ($allowedFields as $f) {
                    if (isset($_POST[$f])) {
                        $request[$f] = trim($_POST[$f]);
                    }
                }
            } elseif (!empty($updateData)) {
                try {
                    $db->update('vendor_onboarding_requests', $updateData, 'id = :id', [':id' => $requestId]);
                    $auth->audit($user['id'], 'vendor_fields_update', 'vendor_onboarding_requests', $requestId, [
                        'fields' => array_keys($updateData)
                    ]);
                    $success = t('vendor-onboarding.ok_vendor_updated');
                    $request = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $requestId]);
                } catch (Exception $e) {
                    error_log('Vendor field update error: ' . $e->getMessage());
                    // Build a helpful error with the field names that were being updated
                    $fieldNames = [];
                    foreach (array_keys($updateData) as $f) {
                        $fieldNames[] = $fieldLabels[$f] ?? $f;
                    }
                    $error = t('vendor-onboarding.err_update_fields_prefix') . implode(', ', $fieldNames) . t('vendor-onboarding.err_update_fields_suffix');
                    // Preserve the user's input so the form doesn't reset
                    foreach ($updateData as $f => $v) {
                        $request[$f] = $v;
                    }
                }
            }
        }

        // UPDATE CUSTOM ONBOARDING DATA: persist the editable Custom Data tab.
        // Values land in vendor_assessment_responses via the shared service. We
        // re-derive the viewer's visible custom fields server-side and only write
        // those, so a user who cannot see a role-restricted field cannot set it
        // either (no trusting the posted field list). A holder onboarding
        // assessment is created lazily on first save.
        if ($action === 'update_custom_onboarding' && $canEdit && $editMode && $requestId > 0) {
            if (!isset($vasForExtras)) {
                require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
                $vasForExtras = new VendorAssessmentService();
            }
            $editableCustom = $vasForExtras->getOnboardingCustomFields(
                $requestId,
                ['groups' => $acl->getUserGroups(), 'super' => (bool)$session->get('is_super_admin')]
            );
            $postedCustom = $_POST['custom'] ?? [];
            if (!is_array($postedCustom)) $postedCustom = [];
            // Multi-select fields (checkbox / button_group_multi) submit nothing when
            // every option is unchecked, so a hidden marker tells us the field was on
            // the form and an empty selection means "clear it".
            $postedCustomPresent = $_POST['custom_present'] ?? [];
            if (!is_array($postedCustomPresent)) $postedCustomPresent = [];

            if (empty($editableCustom)) {
                $error = t('vendor-onboarding.err_no_custom_fields');
            } else {
                // Only persist fields that were actually submitted AND are visible to
                // this editor. Skip the lazy assessment creation entirely if nothing
                // applies, so we never leave an empty phantom assessment behind.
                $toWrite = [];
                foreach ($editableCustom as $fn => $cf) {
                    // Grant-only: only persist fields this viewer is permitted to EDIT.
                    // can_edit is recomputed server-side from the role grants, so a
                    // view-only user cannot write a field by forging the POST.
                    if (empty($cf['can_edit'])) continue;
                    $cfWriteType = $cf['type'] ?? '';
                    $isMultiSelect = in_array($cfWriteType, ['checkbox', 'button_group_multi'], true);
                    $submitted = array_key_exists($fn, $postedCustom)
                              || ($isMultiSelect && !empty($postedCustomPresent[$fn]));
                    if (!$submitted) continue;

                    if ($isMultiSelect) {
                        // Store the selected options as a JSON array, matching how the
                        // assessment platform persists multi-select answers.
                        $arr = $postedCustom[$fn] ?? [];
                        if (!is_array($arr)) $arr = ($arr === '' || $arr === null) ? [] : [$arr];
                        $arr = array_values(array_filter(array_map('strval', $arr), function ($v) { return $v !== ''; }));
                        $val = empty($arr) ? '' : json_encode($arr);
                    } else {
                        $val = $postedCustom[$fn];
                        if (is_array($val)) $val = implode(', ', array_map('strval', $val));
                        $val = trim((string)$val);
                        // Canonicalise widget-backed types so they store uniformly
                        // regardless of how they were typed (mirrors the standard
                        // contact fields). The phone/VAT widgets already submit a
                        // canonical hidden value; this is the belt-and-suspenders.
                        if ($cfWriteType === 'phone') {
                            $val = phone_normalize_e164($val);
                        } elseif ($cfWriteType === 'vat') {
                            $val = vat_normalize($val);
                        }
                    }
                    $toWrite[$fn] = $val;
                }

                if (empty($toWrite)) {
                    $error = t('vendor-onboarding.err_no_custom_values');
                } else {
                    try {
                        // Each custom field carries the onboarding template that defines it.
                        // Ensure a holder assessment exists for EACH of those templates before
                        // writing. Without this, a vendor with no assessment (or only one for a
                        // different onboarding template) gets a holder created against the
                        // lowest-id onboarding template, which does not contain these questions,
                        // so setCustomOnboardingValue() finds no target and silently writes nothing.
                        $tplIds = [];
                        foreach (array_keys($toWrite) as $fn) {
                            $tid = (int)($editableCustom[$fn]['template_id'] ?? 0);
                            if ($tid > 0) $tplIds[$tid] = true;
                        }
                        if (empty($tplIds)) {
                            $vasForExtras->ensureOnboardingAssessment($requestId, $user['id']);
                        } else {
                            foreach (array_keys($tplIds) as $tid) {
                                $vasForExtras->ensureOnboardingAssessment($requestId, $user['id'], $tid);
                            }
                        }
                        $written = 0;
                        foreach ($toWrite as $fn => $val) {
                            if ($vasForExtras->setCustomOnboardingValue($requestId, $fn, $val)) $written++;
                        }
                        $auth->audit($user['id'], 'vendor_custom_onboarding_update', 'vendor_onboarding_requests', $requestId, [
                            'fields' => array_keys($toWrite)
                        ]);
                        if ($written > 0) {
                            $success = t('vendor-onboarding.ok_custom_data_updated');
                        } else {
                            // Never claim success when nothing persisted.
                            $error = t('vendor-onboarding.err_custom_data_no_field');
                        }
                        // Re-read so the tab reflects the saved values.
                        $customOnboardingData = $vasForExtras->getOnboardingCustomFields(
                            $requestId,
                            ['groups' => $viewerGroups, 'super' => $viewerSuper]
                        );
                    } catch (Exception $e) {
                        error_log('Custom onboarding update error: ' . $e->getMessage());
                        $error = t('vendor-onboarding.err_update_custom_data_failed');
                    }
                }
            }
        }

        // REASSIGN STAKEHOLDER: Change the assigned stakeholder for this vendor.
        if (isset($_POST['reassign_stakeholder']) && $canAssignStakeholders && $editMode && $requestId > 0) {
            $newStakeholderId = intval($_POST['stakeholder_user_id'] ?? 0);
            if ($newStakeholderId > 0) {
                try {
                    // Remove any existing stakeholder role (but not owner/reviewer)
                    $db->query(
                        "DELETE FROM vendor_onboarding_stakeholders WHERE request_id = :rid AND role = 'stakeholder' AND user_id != :uid",
                        [':rid' => $requestId, ':uid' => $newStakeholderId]
                    );

                    // Upsert: if user already has a row (e.g. as owner), update their role to stakeholder
                    $db->query(
                        "INSERT INTO vendor_onboarding_stakeholders (request_id, user_id, role, assigned_by)
                         VALUES (:rid, :uid, 'stakeholder', :aby)
                         ON DUPLICATE KEY UPDATE role = 'stakeholder', assigned_by = VALUES(assigned_by), assigned_at = CURRENT_TIMESTAMP",
                        [':rid' => $requestId, ':uid' => $newStakeholderId, ':aby' => $user['id']]
                    );

                    $newStakeholderInfo = $db->fetchOne(
                        'SELECT id, full_name, username FROM users WHERE id = :id',
                        [':id' => $newStakeholderId]
                    );
                    if ($newStakeholderInfo) {
                        $assignedStakeholder = $newStakeholderInfo;
                        $stakeholderDisplayName = !empty($newStakeholderInfo['full_name'])
                            ? $newStakeholderInfo['full_name'] . ' (' . $newStakeholderInfo['username'] . ')'
                            : $newStakeholderInfo['username'];
                        $stakeholderUserId = $newStakeholderInfo['id'];
                    }

                    $auth->audit($user['id'], 'vendor_reassign_stakeholder', 'vendor_onboarding_requests', $requestId, [
                        'new' => ['stakeholder_user_id' => $newStakeholderId, 'vendor_name' => $request['vendor_name'] ?? '']
                    ]);
                    $success = t('vendor-onboarding.ok_stakeholder_reassigned');
                    // Refresh request data
                    $request = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $requestId]);
                } catch (Exception $e) {
                    error_log('Stakeholder reassignment error: ' . $e->getMessage());
                    $error = t('vendor-onboarding.err_reassign_stakeholder_failed');
                }
            } else {
                $error = t('vendor-onboarding.err_select_valid_stakeholder');
            }
        }
    }
}

// Generate a fresh CSRF token. On GET requests or successful POSTs, we always
// generate a new one. On failed POSTs, we reuse the existing one so the user
// can try again without the token being invalidated.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($success)) {
    $csrfToken = $security->generateCSRFToken();
} else {
    $csrfToken = $security->getCSRFToken() ?? $security->generateCSRFToken();
}

// The categories a vendor can fall into. If none of these fit,
// there's always "OTHER" -- the junk drawer of vendor types.
$vendorTypes = [
    'GENERAL OPERATIONS',
    'TECHNOLOGY',
    'PROFESSIONAL SERVICES',
    'FINANCIAL SERVICES',
    'MARKETING',
    'HR/BENEFITS',
    'FACILITIES',
    'LEGAL',
    'OTHER'
];

// Simple helper to safely pull a value from the request array.
// Falls back to a default if the field doesn't exist or is null.
// Used about 50 times in the form below, so yeah, it earns its keep.
function getFieldValue($request, $field, $default = '') {
    return isset($request[$field]) ? $request[$field] : $default;
}
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo $editMode ? t('vendor-onboarding.title_dashboard') : t('vendor-onboarding.title_new'); ?> - TPRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color']); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color']); ?>;
            --sidebar-width: <?php echo e($theme['nav_width']); ?>px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Roboto', sans-serif; }
        .page { display: flex; flex-direction: column; min-height: 100vh; }
        .page-header { display: none !important; }
        .preloader { display: none !important; }

        /* Top Bar */
        .top-bar {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-shrink: 0;
        }
        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-menu a {
            color: #333;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 4px;
            background: rgba(255,101,67,0.1);
            transition: background 0.2s;
            font-size: 14px;
        }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }

        /* Main Layout */
        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width) !important;
            min-width: var(--sidebar-width) !important;
            max-width: var(--sidebar-width) !important;
            background: var(--nav-fill-color) !important;
            padding: 0;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title {
            color: var(--nav-font-color);
            font-size: 13px;
            font-weight: 500;
            margin-top: 8px;
            opacity: 0.9;
        }
        .sidebar-content { flex: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--nav-font-color);
            opacity: 0.5;
            padding: 0 20px;
            margin-bottom: 10px;
        }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 20px;
            color: var(--nav-font-color);
            opacity: 0.85;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .sidebar-nav li a:hover {
            background: rgba(255,255,255,0.1);
            opacity: 1;
            border-left-color: var(--nav-font-color);
        }
        .sidebar-nav li a.active {
            background: rgba(255,255,255,0.15);
            opacity: 1;
            border-left-color: var(--nav-font-color);
            font-weight: 500;
        }
        .sidebar-nav li a .icon { font-size: 16px; width: 20px; text-align: center; opacity: 0.9; }
        .sidebar-nav li a .badge {
            margin-left: auto;
            background: rgba(255,255,255,0.2);
            color: var(--nav-font-color);
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 10px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 35px 40px;
            background: #f9fafb;
            min-width: 0;
            overflow-y: auto;
        }
        .section-header {
            background: var(--theme-header-color);
            color: white;
            padding: 15px 20px;
            border-radius: 8px 8px 0 0;
            margin-top: 25px;
        }
        .section-header:first-of-type { margin-top: 0; }
        .section-header h3 { margin: 0; font-size: 18px; font-weight: 500; }
        .section-content {
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 8px 8px;
            padding: 25px;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        .form-group .question-number {
            color: var(--theme-header-color);
            font-weight: 600;
            margin-right: 8px;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--theme-header-color);
            box-shadow: 0 0 0 3px rgba(53,160,163,0.1);
        }
        .form-control:disabled {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }
        textarea.form-control { min-height: 80px; resize: vertical; }
        .form-hint {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        /* Vendor Dashboard Card Layout */
        .vendor-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .vendor-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        .vendor-card-full {
            grid-column: 1 / -1;
        }
        .vendor-card-header {
            background: #f8f9fa;
            padding: 12px 16px;
            font-weight: 600;
            font-size: 14px;
            color: #333;
            border-bottom: 1px solid #e5e7eb;
        }
        .vendor-card-body {
            padding: 16px;
        }
        .vendor-field-row {
            display: flex;
            padding: 6px 0;
            font-size: 13px;
            border-bottom: 1px solid #f3f4f6;
        }
        .vendor-field-row:last-child { border-bottom: none; }
        .vendor-field-label {
            color: #6b7280;
            min-width: 180px;
            flex-shrink: 0;
        }
        .vendor-field-value {
            color: #333;
            font-weight: 500;
            flex: 1;
        }
        .vendor-field-value.empty {
            color: #9ca3af;
            font-style: italic;
            font-weight: 400;
        }
        .vendor-field-justification {
            padding-left: 180px;
            font-size: 12px;
            color: #6b7280;
            font-style: italic;
            padding-bottom: 6px;
            border-bottom: 1px solid #f3f4f6;
        }
        .yes-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
            background: #dcfce7;
            color: #166534;
        }
        .no-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
            background: #fef2f2;
            color: #991b1b;
        }
        .unknown-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
            background: #f3f4f6;
            color: #6b7280;
        }
        .severity-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
        }
        .severity-low { background: #dcfce7; color: #166534; }
        .severity-moderate { background: #fef3c7; color: #92400e; }
        .severity-high { background: #fed7aa; color: #9a3412; }
        .severity-severe { background: #fef2f2; color: #991b1b; }
        .vendor-stakeholder-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            margin-bottom: 10px;
            font-size: 14px;
        }
        @media (max-width: 576px) {
            .vendor-cards-grid { grid-template-columns: 1fr; }
            .vendor-field-row { flex-direction: column; gap: 2px; }
            .vendor-field-label { min-width: 0; }
            .vendor-field-justification { padding-left: 0; }
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: var(--theme-button-color);
            color: white;
        }
        .btn-primary:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-outline {
            background: white;
            color: var(--theme-header-color);
            border: 2px solid var(--theme-header-color);
        }
        .btn-outline:hover {
            background: var(--theme-header-color);
            color: white;
        }
        .alert {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            font-size: 14px;
        }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .status-draft { background: #ffc107; color: #000; }
        .status-submitted { background: #17a2b8; color: #fff; }
        .status-in_review { background: #6f42c1; color: #fff; }
        .status-approved { background: #28a745; color: #fff; }
        .status-rejected { background: #dc3545; color: #fff; }
        .status-inactive { background: #6c757d; color: #fff; }
        .page-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-title { font-size: 18px; font-weight: 600; color: #333; }

        /* Footer */
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }
        .footer-modern .brand img { max-height: 45px; }

        /* Responsive - Tablet */
        @media (max-width: 992px) {
            .main-layout { flex-direction: column; }
            .sidebar {
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
            }
            .sidebar-brand {
                display: flex;
                align-items: center;
                gap: 15px;
                padding: 15px 20px;
            }
            .sidebar-brand img { max-width: 150px; }
            .sidebar-brand .brand-title { margin-top: 0; }
            .sidebar-content { padding: 10px 0; }
            .sidebar-section { margin-bottom: 10px; }
            .sidebar-section-title { padding: 0 15px; margin-bottom: 8px; }
            .sidebar-nav {
                display: flex;
                flex-wrap: wrap;
                padding: 0 10px;
            }
            .sidebar-nav li { flex: 0 0 auto; }
            .sidebar-nav li a {
                padding: 8px 14px;
                border-radius: 6px;
                margin: 3px;
                border-left: none;
            }
            .sidebar-nav li a:hover,
            .sidebar-nav li a.active {
                border-left: none;
                background: rgba(255,255,255,0.2);
            }
            .main-content { padding: 25px 20px; }
        }

        /* Responsive - Mobile */
        @media (max-width: 576px) {
            .top-bar {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .top-bar > span { margin-right: 0 !important; }
            .user-menu {
                flex-wrap: wrap;
                justify-content: center;
                gap: 8px;
            }
            .sidebar-brand { flex-direction: column; text-align: center; }
            .sidebar-nav { flex-direction: column; padding: 0 10px; }
            .sidebar-nav li { width: 100%; }
            .sidebar-nav li a { margin: 2px 0; border-radius: 6px; }
            .main-content { padding: 20px 15px; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; text-align: center; }
            .vendor-search-container {
                width: 100% !important;
                min-width: 0 !important;
            }
        }
        @media (max-width: 768px) {
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; text-align: center; }
        }
        /* Autocomplete styles */
        .autocomplete-wrapper {
            position: relative;
        }
        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .autocomplete-item {
            padding: 10px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        .autocomplete-item:last-child {
            border-bottom: none;
        }
        .autocomplete-item:hover,
        .autocomplete-item.highlighted {
            background: #f8f9fa;
        }
        .autocomplete-item .user-name {
            font-weight: 500;
            color: #333;
        }
        .autocomplete-item .user-email {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }
        .autocomplete-no-results {
            padding: 12px 14px;
            color: #666;
            font-style: italic;
        }
        .autocomplete-loading {
            padding: 12px 14px;
            color: #666;
            text-align: center;
        }
        details summary::-webkit-details-marker { display: none; }
        details[open] > summary .details-arrow { transform: rotate(90deg); }
        details > summary .details-arrow { display: inline-block; transition: transform 0.2s; }

        /* Template Selection Cards */
        .template-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 16px;
        }
        .template-card {
            display: block;
            padding: 24px;
            background: #fff;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
            text-align: left;
            font-family: inherit;
            font-size: inherit;
            width: 100%;
        }
        .template-card:hover {
            border-color: var(--theme-header-color);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }
        .template-card-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 8px 0;
        }
        .template-card-desc {
            font-size: 13px;
            color: #6b7280;
            margin: 0 0 12px 0;
            line-height: 1.4;
        }
        .template-card-meta {
            font-size: 12px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <?php renderImpersonationBanner(); ?>
    <div class="page">
        <!-- Top Bar with Search and User Menu -->
        <div class="top-bar">
            <span style="color: #666; margin-right: auto;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <?php if ($isAdmin): ?>
                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                <?php endif; ?>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <!-- Left Sidebar Navigation -->
            <?php $currentPage = 'new_request'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <!-- Main Content Area -->
            <main class="main-content">
                <div class="page-title-row">
                    <div>
                        <h1 class="page-title" style="margin: 0;"><?php echo $editMode ? t('vendor-onboarding.title_dashboard') : t('vendor-onboarding.title_new'); ?></h1>
                        <?php if ($editMode && $request): ?>
                            <p style="color: #666; margin: 5px 0 0 0; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <?php if (!empty($request['vendor_name'])): ?>
                                    <?php echo e($request['vendor_name']); ?>
                                <?php endif; ?>
                                <?php if (!empty($request['vendor_tier'])): ?>
                                    &nbsp;&middot;&nbsp;
                                    <span style="font-size: 11px; padding: 2px 8px; border-radius: 10px; background: <?php echo $request['vendor_tier'] === '1' ? '#fef2f2' : ($request['vendor_tier'] === '3' ? '#f0fdf4' : '#fef3c7'); ?>; color: <?php echo $request['vendor_tier'] === '1' ? '#991b1b' : ($request['vendor_tier'] === '3' ? '#166534' : '#92400e'); ?>;">
                                        <?php echo e(t('vendor-onboarding.tier_label')); ?> <?php echo e($request['vendor_tier']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php
                                    $hasUpguard = !empty($request['current_srs_score']);
                                    $hasShodan = !empty($request['current_shodan_score']);
                                    $hasCustom = !empty($request['custom_score']);
                                    if ($hasUpguard || $hasShodan || $hasCustom):
                                        $combined = $srsService->getCombinedScore($request);
                                        if ($combined['score'] !== null):
                                            $displayScore = $combined['score'] . '%';
                                            $displayGrade = $combined['grade'];
                                            $displayLabel = $combined['source_count'] >= 2 ? t('vendor-onboarding.label_combined_srs') : ($hasUpguard ? $upguardDisplayName : ($hasShodan ? $shodanDisplayName : t('vendor-onboarding.label_custom')));
                                            $gradeColor = $displayGrade === 'A' ? '#166534' : ($displayGrade === 'B' ? '#15803d' : ($displayGrade === 'C' ? '#ca8a04' : ($displayGrade === 'D' ? '#ea580c' : '#dc2626')));
                                            $gradeBg = $displayGrade === 'A' ? '#f0fdf4' : ($displayGrade === 'B' ? '#f0fdf4' : ($displayGrade === 'C' ? '#fefce8' : ($displayGrade === 'D' ? '#fff7ed' : '#fef2f2')));
                                ?>
                                    &nbsp;&middot;&nbsp;
                                    <span style="font-size: 11px; padding: 2px 8px; border-radius: 10px; background: <?php echo $gradeBg; ?>; color: <?php echo $gradeColor; ?>;">
                                        <?php echo e($displayLabel); ?>: <?php echo $displayScore; ?> (<?php echo $displayGrade; ?>)
                                    </span>
                                <?php endif; endif; ?>
                                &nbsp;&middot;&nbsp;
                                <span class="status-badge status-<?php echo e($request['status']); ?>">
                                    <?php echo e(ucfirst(str_replace('_', ' ', $request['status']))); ?>
                                </span>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <?php if ($editMode && ($isAdmin || $isCyberTPRM || $isProcurement)): ?>
                            <div class="vendor-search-container" style="position: relative; min-width: 260px;">
                                <input
                                    type="text"
                                    id="vendorSearchInput"
                                    placeholder="<?php echo e(t('vendor-onboarding.search_vendors_placeholder')); ?>"
                                    class="focus-ring"
                                    style="width: 100%; padding: 8px 35px 8px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 13px; transition: all 0.2s;"
                                    <?php if ($isProcurement && !$isAdmin && !$isCyberTPRM): ?>data-link-base="vendor-onboarding.php"<?php endif; ?>
                                >
                                <span style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #000; font-size: 16px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">&#128269;</span>
                                <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo e($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>

                <?php if (isset($_GET['success_note'])): ?>
                    <div class="alert alert-success"><?php echo e(t('vendor-onboarding.note_added')); ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['success_action'])): ?>
                    <div class="alert alert-success"><?php
                        $saMsgs = ['1' => t('vendor-onboarding.sa_created'), '2' => t('vendor-onboarding.sa_cancelled'), '3' => t('vendor-onboarding.sa_deleted'), '4' => t('vendor-onboarding.sa_updated'), '5' => t('vendor-onboarding.sn_added'), '6' => t('vendor-onboarding.sn_updated'), '7' => t('vendor-onboarding.sn_deleted')];
                        echo $saMsgs[$_GET['success_action']] ?? t('vendor-onboarding.sa_saved');
                    ?></div>
                <?php endif; ?>

                <?php if ($editMode && $request && $request['status'] === 'inactive'): ?>
                    <div class="alert alert-warning"><?php echo e(t('vendor-onboarding.vendor_inactive_notice')); ?></div>
                <?php endif; ?>

                <?php if ($showTemplateSelection): ?>
                <?php if (empty($onboardingTemplates)): ?>
                <!-- No onboarding templates configured -->
                <div class="alert alert-warning">
                    <?php echo e(t('vendor-onboarding.no_templates_configured')); ?>
                    <?php if ($isAdmin || $isCyberTPRM): ?>
                        <?php echo t('vendor-onboarding.no_templates_admin_html'); ?>
                    <?php else: ?>
                        <?php echo e(t('vendor-onboarding.no_templates_contact_admin')); ?>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <!-- Template Selection Cards -->
                <p style="color: #6b7280; margin: 0 0 4px 0; font-size: 14px;"><?php echo e(t('vendor-onboarding.choose_form_intro')); ?></p>
                <div class="template-cards-grid">
                    <?php foreach ($onboardingTemplates as $tpl): ?>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($security->getCSRFToken()); ?>">
                        <input type="hidden" name="start_template_onboarding" value="1">
                        <input type="hidden" name="template_id" value="<?php echo intval($tpl['id']); ?>">
                        <button type="submit" class="template-card">
                            <div class="template-card-title"><?php echo e($tpl['name']); ?></div>
                            <div class="template-card-desc"><?php echo e($tpl['description'] ?? t('vendor-onboarding.template_default_desc')); ?></div>
                            <div class="template-card-meta"><?php echo intval($tpl['section_count']); ?> section<?php echo $tpl['section_count'] != 1 ? 's' : ''; ?> &middot; <?php echo intval($tpl['question_count']); ?> question<?php echo $tpl['question_count'] != 1 ? 's' : ''; ?></div>
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php else: ?>

                <?php
                // Determine which tab to show (from URL param after note redirect, etc.)
                $activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'vendor';
                if (!in_array($activeTab, ['vendor', 'custom_data', 'notes', 'assessments', 'fair', 'risks', 'documents', 'subprocessors', 'action_plan'])) {
                    $activeTab = 'vendor';
                }
                // Action Plan is admin/cyber_tprm only
                if ($activeTab === 'action_plan' && !$canManageScheduledActions) {
                    $activeTab = 'vendor';
                }
                // Procurement-only users cannot access FAIR or Assessments tabs
                if ($isProcurementOnly && in_array($activeTab, ['fair', 'assessments'])) {
                    $activeTab = 'vendor';
                }
                // Custom Data tab badge counts only POPULATED fields (a non-empty
                // scalar value or a non-empty multi-select list), not the number of
                // fields defined on the template. When nothing is filled in, no badge.
                $customPopulatedCount = 0;
                foreach ($customOnboardingData as $cf) {
                    $hasVal = isset($cf['value']) && (string)$cf['value'] !== '';
                    $hasList = !empty($cf['value_list']) && is_array($cf['value_list']);
                    if ($hasVal || $hasList) $customPopulatedCount++;
                }
                ?>

                <?php if ($showVendorTabs): ?>
                <!-- Horizontal Tab Navigation -->
                <div style="display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; flex-wrap: wrap;">
                    <button type="button" data-action="showVendorTab" data-arg="vendor" id="vendorTab_vendor" class="vendor-section-tab"
                        style="padding: 10px 18px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'vendor' ? 'var(--theme-header-color)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'vendor' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'vendor' ? 'var(--theme-header-color)' : '#6b7280'; ?>; margin-bottom: -2px;">
                        <?php echo e(t('vendor-onboarding.tab_vendor')); ?>
                    </button>
                    <?php if ($showGripSaasTab): ?>
                    <button type="button" data-action="showVendorTab" data-arg="saas_data" id="vendorTab_saas_data" class="vendor-section-tab"
                        style="padding: 10px 18px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'saas_data' ? 'var(--theme-header-color)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'saas_data' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'saas_data' ? 'var(--theme-header-color)' : '#6b7280'; ?>; margin-bottom: -2px;">
                        <?php echo e(t('vendor-onboarding.tab_saas_data')); ?>
                    </button>
                    <?php endif; ?>
                    <?php if (!empty($customOnboardingData)): ?>
                    <button type="button" data-action="showVendorTab" data-arg="custom_data" id="vendorTab_custom_data" class="vendor-section-tab"
                        style="padding: 10px 18px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'custom_data' ? 'var(--theme-header-color)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'custom_data' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'custom_data' ? 'var(--theme-header-color)' : '#6b7280'; ?>; margin-bottom: -2px;">
                        <?php echo e(t('vendor-onboarding.tab_custom_data')); ?>
                        <?php if ($customPopulatedCount > 0): ?>
                        <span style="background: var(--theme-header-color); color: white; font-size: 10px; padding: 2px 7px; border-radius: 10px; margin-left: 4px;"><?php echo (int)$customPopulatedCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <?php endif; ?>
                    <button type="button" data-action="showVendorTab" data-arg="notes" id="vendorTab_notes" class="vendor-section-tab"
                        style="padding: 10px 18px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'notes' ? 'var(--theme-header-color)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'notes' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'notes' ? 'var(--theme-header-color)' : '#6b7280'; ?>; margin-bottom: -2px;">
                        <?php echo e(t('vendor-onboarding.tab_case_management')); ?>
                        <?php if ($vendorNotesCount > 0): ?>
                            <span style="background: var(--theme-header-color); color: white; font-size: 10px; padding: 2px 7px; border-radius: 10px; margin-left: 4px;"><?php echo $vendorNotesCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <?php if ($canManageScheduledActions): ?>
                    <button type="button" data-action="showVendorTab" data-arg="action_plan" id="vendorTab_action_plan" class="vendor-section-tab"
                        style="padding: 10px 18px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'action_plan' ? 'var(--theme-header-color)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'action_plan' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'action_plan' ? 'var(--theme-header-color)' : '#6b7280'; ?>; margin-bottom: -2px;">
                        <?php echo e(t('vendor-onboarding.tab_action_plan')); ?>
                        <?php if ($scheduledActionsCount > 0): ?>
                            <span style="background: var(--theme-header-color); color: white; font-size: 10px; padding: 2px 7px; border-radius: 10px; margin-left: 4px;"><?php echo $scheduledActionsCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <?php endif; ?>
                    <?php if (!$isStakeholderOnly && !$isProcurementOnly): ?>
                    <button type="button" data-action="showVendorTab" data-arg="assessments" id="vendorTab_assessments" class="vendor-section-tab"
                        style="padding: 10px 18px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'assessments' ? 'var(--theme-header-color)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'assessments' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'assessments' ? 'var(--theme-header-color)' : '#6b7280'; ?>; margin-bottom: -2px;">
                        <?php echo e(t('vendor-onboarding.tab_assessments')); ?>
                        <?php if (count($linkedAssessments) > 0): ?>
                            <span style="background: var(--theme-header-color); color: white; font-size: 10px; padding: 2px 7px; border-radius: 10px; margin-left: 4px;"><?php echo count($linkedAssessments); ?></span>
                        <?php endif; ?>
                    </button>
                    <?php if (!empty($vendorFairAnalyses)): ?>
                    <button type="button" data-action="showVendorTab" data-arg="fair" id="vendorTab_fair" class="vendor-section-tab"
                        style="padding: 10px 18px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'fair' ? 'var(--theme-header-color)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'fair' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'fair' ? 'var(--theme-header-color)' : '#6b7280'; ?>; margin-bottom: -2px;">
                        <?php echo e(t('vendor-onboarding.tab_fair_assessment')); ?>
                        <span style="background: var(--theme-header-color); color: white; font-size: 10px; padding: 2px 7px; border-radius: 10px; margin-left: 4px;"><?php echo count($vendorFairAnalyses); ?></span>
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$isStakeholderOnly): ?>
                    <button type="button" data-action="showVendorTab" data-arg="risks" id="vendorTab_risks" class="vendor-section-tab"
                        style="padding: 10px 18px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'risks' ? 'var(--theme-header-color)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'risks' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'risks' ? 'var(--theme-header-color)' : '#6b7280'; ?>; margin-bottom: -2px;">
                        <?php echo e(t('vendor-onboarding.tab_risks')); ?>
                        <?php if ($vendorRisksCount > 0): ?>
                            <span style="background: #dc3545; color: white; font-size: 10px; padding: 2px 7px; border-radius: 10px; margin-left: 4px;"><?php echo $vendorRisksCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <button type="button" data-action="showVendorTab" data-arg="documents" id="vendorTab_documents" class="vendor-section-tab"
                        style="padding: 10px 18px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'documents' ? 'var(--theme-header-color)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'documents' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'documents' ? 'var(--theme-header-color)' : '#6b7280'; ?>; margin-bottom: -2px;">
                        <?php echo e(t('vendor-onboarding.tab_documents')); ?>
                        <?php if ($vendorDocumentsCount > 0): ?>
                            <span style="background: var(--theme-header-color); color: white; font-size: 10px; padding: 2px 7px; border-radius: 10px; margin-left: 4px;"><?php echo $vendorDocumentsCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <button type="button" data-action="showVendorTab" data-arg="subprocessors" id="vendorTab_subprocessors" class="vendor-section-tab"
                        style="padding: 10px 18px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'subprocessors' ? 'var(--theme-header-color)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'subprocessors' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'subprocessors' ? 'var(--theme-header-color)' : '#6b7280'; ?>; margin-bottom: -2px;">
                        <?php echo e(t('vendor-onboarding.tab_subprocessors')); ?>
                        <?php if ($vendorSubprocessorsCount > 0): ?>
                            <span style="background: #7c3aed; color: white; font-size: 10px; padding: 2px 7px; border-radius: 10px; margin-left: 4px;"><?php echo $vendorSubprocessorsCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($hasScoreData): ?>
                    <a href="vendor-srs-details.php?id=<?php echo $requestId; ?>" style="margin-left: auto; padding: 8px 16px; border: none; border-bottom: 2px solid transparent; background: none; cursor: pointer; font-size: 13px; font-weight: 500; color: var(--theme-header-color); margin-bottom: -2px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        <img src="app/icons/graduation-hat-02.svg" alt="" width="16" height="16" style="opacity: 0.7;"> <?php echo t('vendor-onboarding.security_score_details_link'); ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Tab: Vendor (Default View) -->
                <div id="vendorTabContent_vendor" style="<?php echo ($showVendorTabs && $activeTab !== 'vendor') ? 'display: none;' : ''; ?>">
                <?php
                // PHP helper functions for card display
                function renderYesNo($val) {
                    if ($val === 'yes') return '<span class="yes-badge">' . e(t('vendor-onboarding.yes')) . '</span>';
                    if ($val === 'no') return '<span class="no-badge">' . e(t('vendor-onboarding.no')) . '</span>';
                    if ($val === 'unknown') return '<span class="unknown-badge">' . e(t('vendor-onboarding.unknown')) . '</span>';
                    return '<span class="vendor-field-value empty">' . e(t('vendor-onboarding.not_provided')) . '</span>';
                }
                function renderSeverity($val) {
                    $map = ['low' => t('vendor-onboarding.sev_low'), 'moderate' => t('vendor-onboarding.sev_moderate'), 'high' => t('vendor-onboarding.sev_high'), 'severe' => t('vendor-onboarding.sev_severe')];
                    if (isset($map[$val])) return '<span class="severity-badge severity-' . $val . '">' . $map[$val] . '</span>';
                    return '<span class="vendor-field-value empty">' . e(t('vendor-onboarding.not_provided')) . '</span>';
                }
                function renderFieldValue($val) {
                    if ($val === '' || $val === null) return '<span class="vendor-field-value empty">' . e(t('vendor-onboarding.not_provided')) . '</span>';
                    return '<span class="vendor-field-value">' . e($val) . '</span>';
                }
                $tierLabels = ['' => t('vendor-onboarding.not_assigned'), '1' => t('vendor-onboarding.tier1'), '2' => t('vendor-onboarding.tier2'), '3' => t('vendor-onboarding.tier3')];
                ?>

                <!-- Stakeholder & Edit Row -->
                <?php if ($editMode): ?>
                <div class="vendor-stakeholder-row">
                    <?php if ($assignedStakeholder): ?>
                    <span style="color: #6b7280;"><?php echo e(t('vendor-onboarding.stakeholder_label')); ?></span>
                    <strong><?php echo e($stakeholderDisplayName); ?></strong>
                    <?php endif; ?>
                    <div style="margin-left: auto; display: flex; gap: 8px;">
                        <?php if ($canEdit || $canEditProcurementFields): ?>
                            <button type="button" id="toggleEditBtn" class="btn" style="padding: 4px 12px; font-size: 12px; background: var(--theme-header-color); color: white;"><?php echo e(t('vendor-onboarding.btn_edit')); ?></button>
                        <?php endif; ?>
                        <?php if ($canAssignStakeholders): ?>
                            <button type="button" data-action="openReassignModal" class="btn" style="padding: 4px 12px; font-size: 12px; background: #6b7280; color: white;"><?php echo e(t('vendor-onboarding.btn_reassign')); ?></button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Vendor Dashboard Cards -->
                <?php if ($canEdit || $canEditProcurementFields): ?>
                <form method="POST" id="vendorFieldsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="update_vendor_fields">
                <?php endif; ?>

                <div class="vendor-cards-grid">
                    <?php
                    // Fields that procurement-only users are allowed to edit
                    $procurementEditableFields = ['vendor_name', 'vendor_type', 'vendor_id',
                        'primary_contact_details', 'primary_contact_email',
                        'primary_contact_title', 'primary_contact_phone'];
                    // Helper: render a dual-mode field (display + edit input)
                    function dualField($request, $field, $label, $type = 'text', $options = []) {
                        global $canEdit, $canEditProcurementFields, $procurementEditableFields, $hiddenOnboardingFields;
                        // Onboarding role visibility: a restricted field the viewer can't see is
                        // omitted entirely (the save handler only updates posted fields, so this
                        // never wipes the value).
                        if (!empty($hiddenOnboardingFields) && isset($hiddenOnboardingFields[strtolower($field)])) return;
                        $val = getFieldValue($request, $field);
                        echo '<div class="vendor-field-row">';
                        echo '<span class="vendor-field-label">' . e($label) . '</span>';
                        // Display mode
                        echo '<span class="vendor-field-display">';
                        if ($type === 'yes_no') {
                            echo renderYesNo($val);
                        } elseif ($type === 'yes_no_unknown') {
                            echo renderYesNo($val);
                        } elseif ($type === 'checkbox') {
                            $checked = ($val === 'yes');
                            echo '<span class="vendor-field-value">' . ($checked
                                ? '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:#ecfdf5;color:#065f46;">' . t('vendor-onboarding.checkbox_yes_html') . '</span>'
                                : '<span style="color:#9ca3af;">' . e(t('vendor-onboarding.no')) . '</span>') . '</span>';
                        } elseif ($type === 'severity') {
                            echo renderSeverity($val);
                        } elseif ($type === 'email' && $val) {
                            echo '<span class="vendor-field-value"><a href="mailto:' . e($val) . '">' . e($val) . '</a></span>';
                        } elseif ($type === 'domain' && $val) {
                            echo '<span class="vendor-field-value"><a href="https://' . e($val) . '" target="_blank" rel="noopener">' . e($val) . '</a></span>';
                        } elseif ($type === 'phone') {
                            if ($val === '' || $val === null) {
                                echo '<span class="vendor-field-value empty">' . e(t('vendor-onboarding.not_provided')) . '</span>';
                            } else {
                                $disp = phone_display($val);
                                echo '<span class="vendor-field-value"><a href="tel:' . e(phone_normalize_e164($val)) . '">' . e($disp) . '</a></span>';
                            }
                        } elseif ($type === 'vat') {
                            if ($val === '' || $val === null) {
                                $vatEditable = $canEdit || ($canEditProcurementFields && in_array($field, $procurementEditableFields));
                                if ($vatEditable) {
                                    // No VAT on file yet: offer a button that jumps into edit
                                    // mode and focuses the VAT field.
                                    echo '<button type="button" class="vat-add-btn" data-vat-target="' . e('vf_' . $field) . '" style="padding:4px 12px;border:1px solid #2563eb;border-radius:4px;background:#eff6ff;color:#2563eb;font-size:13px;font-weight:500;cursor:pointer;">' . e(t('vendor-onboarding.add_vat')) . '</button>';
                                } else {
                                    echo '<span class="vendor-field-value empty">' . e(t('vendor-onboarding.not_provided')) . '</span>';
                                }
                            } else {
                                $vatVal = vat_normalize($val);
                                echo '<span class="vendor-field-value">' . e($vatVal)
                                   . ' <button type="button" class="vat-info-standalone" data-vat-info="' . e($vatVal) . '" data-vies-endpoint="api/vat-validate.php"'
                                   . ' title="' . e(t('vendor-onboarding.vat_info_title')) . '" aria-label="' . e(t('vendor-onboarding.vat_info_title')) . '"'
                                   . ' style="display:inline-flex; align-items:center; vertical-align:middle; width:20px; height:20px; padding:0; margin-left:4px; border:none; background:none; cursor:pointer; color:#2563eb;">'
                                   . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'
                                   . '</button></span>';
                            }
                        } elseif ($type === 'date' && $val) {
                            echo '<span class="vendor-field-value">' . date('M j, Y', strtotime($val)) . '</span>';
                        } elseif ($type === 'number') {
                            if ($val === '' || $val === null) {
                                echo '<span class="vendor-field-value empty">' . e(t('vendor-onboarding.not_provided')) . '</span>';
                            } else {
                                echo '<span class="vendor-field-value">' . number_format(intval($val)) . '</span>';
                            }
                        } elseif ($type === 'integer') {
                            if ($val === '' || $val === null) {
                                echo '<span class="vendor-field-value empty">' . e(t('vendor-onboarding.not_provided')) . '</span>';
                            } else {
                                echo '<span class="vendor-field-value">' . intval($val) . '</span>';
                            }
                        } elseif ($type === 'currency') {
                            echo ($val !== '' && $val !== null) ? '<span class="vendor-field-value">$' . number_format(floatval($val), 2) . '</span>' : '<span class="vendor-field-value empty">' . e(t('vendor-onboarding.not_provided')) . '</span>';
                        } elseif ($type === 'tier') {
                            $tierLabels = ['' => t('vendor-onboarding.not_assigned'), '1' => t('vendor-onboarding.tier1'), '2' => t('vendor-onboarding.tier2'), '3' => t('vendor-onboarding.tier3')];
                            if ($val) {
                                $bg = $val === '1' ? '#fef2f2' : ($val === '3' ? '#f0fdf4' : '#fef3c7');
                                $color = $val === '1' ? '#991b1b' : ($val === '3' ? '#166534' : '#92400e');
                                echo '<span class="vendor-field-value"><span style="padding:2px 8px;border-radius:10px;font-size:12px;background:' . $bg . ';color:' . $color . ';">' . e($tierLabels[$val] ?? $val) . '</span></span>';
                            } else {
                                echo '<span class="vendor-field-value empty">' . e(t('vendor-onboarding.not_assigned')) . '</span>';
                            }
                        } else {
                            echo renderFieldValue($val);
                        }
                        echo '</span>';
                        // Edit mode — only render if this field is editable
                        $fieldEditable = $canEdit || ($canEditProcurementFields && in_array($field, $procurementEditableFields));
                        if ($fieldEditable) {
                        echo '<span class="vendor-field-edit" style="display:none;">';
                        if ($type === 'yes_no') {
                            echo '<select name="' . e($field) . '" style="padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;">';
                            echo '<option value="">--</option><option value="yes"' . ($val === 'yes' ? ' selected' : '') . '>' . e(t('vendor-onboarding.yes')) . '</option><option value="no"' . ($val === 'no' ? ' selected' : '') . '>' . e(t('vendor-onboarding.no')) . '</option></select>';
                        } elseif ($type === 'checkbox') {
                            // Hidden sentinel ensures unchecked state submits as "no"
                            echo '<input type="hidden" name="' . e($field) . '" value="no">';
                            echo '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">';
                            echo '<input type="checkbox" name="' . e($field) . '" value="yes"' . ($val === 'yes' ? ' checked' : '') . '>';
                            echo e(t('vendor-onboarding.yes')) . '</label>';
                        } elseif ($type === 'yes_no_unknown') {
                            echo '<select name="' . e($field) . '" style="padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;">';
                            echo '<option value="">--</option><option value="yes"' . ($val === 'yes' ? ' selected' : '') . '>' . e(t('vendor-onboarding.yes')) . '</option><option value="no"' . ($val === 'no' ? ' selected' : '') . '>' . e(t('vendor-onboarding.no')) . '</option><option value="unknown"' . ($val === 'unknown' ? ' selected' : '') . '>' . e(t('vendor-onboarding.unknown')) . '</option></select>';
                        } elseif ($type === 'severity') {
                            echo '<select name="' . e($field) . '" style="padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;">';
                            echo '<option value="">--</option>';
                            foreach (['low' => t('vendor-onboarding.sev_low'), 'moderate' => t('vendor-onboarding.sev_moderate'), 'high' => t('vendor-onboarding.sev_high'), 'severe' => t('vendor-onboarding.sev_severe')] as $k => $v) {
                                echo '<option value="' . $k . '"' . ($val === $k ? ' selected' : '') . '>' . $v . '</option>';
                            }
                            echo '</select>';
                        } elseif ($type === 'select' && !empty($options)) {
                            echo '<select name="' . e($field) . '" style="padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;">';
                            echo '<option value="">--</option>';
                            foreach ($options as $k => $v) {
                                echo '<option value="' . e($k) . '"' . ($val === $k ? ' selected' : '') . '>' . e($v) . '</option>';
                            }
                            echo '</select>';
                        } elseif ($type === 'textarea') {
                            echo '<textarea name="' . e($field) . '" style="width:100%;padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;font-family:inherit;" rows="2">' . e($val) . '</textarea>';
                        } elseif ($type === 'date') {
                            echo '<input type="date" name="' . e($field) . '" value="' . e($val) . '" style="padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;">';
                        } elseif ($type === 'email') {
                            echo '<input type="email" name="' . e($field) . '" value="' . e($val) . '" style="padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;width:100%;">';
                        } elseif ($type === 'phone') {
                            echo phone_render_widget($field, $val, ['id' => 'pf_' . $field]);
                        } elseif ($type === 'vat') {
                            echo vat_render_widget($field, $val, ['id' => 'vf_' . $field, 'vies_endpoint' => 'api/vat-validate.php']);
                        } elseif ($type === 'number' || $type === 'currency' || $type === 'integer') {
                            echo '<input type="number" name="' . e($field) . '" value="' . e($val) . '" style="padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;width:120px;"' . ($type === 'currency' ? ' step="0.01"' : ($type === 'integer' ? ' step="1"' : '')) . '>';
                        } else {
                            echo '<input type="text" name="' . e($field) . '" value="' . e($val) . '" style="padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;width:100%;">';
                        }
                        echo '</span>';
                        } // end if ($fieldEditable)
                        echo '</div>';
                    }
                    // Helper: render justification field (not editable by procurement)
                    function dualJustification($request, $field, $label = '') {
                        global $canEdit, $hiddenOnboardingFields;
                        if (!empty($hiddenOnboardingFields) && isset($hiddenOnboardingFields[strtolower($field)])) return;
                        $val = getFieldValue($request, $field);
                        echo '<div class="vendor-field-justification-wrap">';
                        if ($val) echo '<div class="vendor-field-justification vendor-field-display">' . e($val) . '</div>';
                        if ($canEdit) {
                            echo '<div class="vendor-field-edit" style="display:none;padding:0 0 8px 0;"><textarea name="' . e($field) . '" style="width:100%;padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:12px;font-family:inherit;" rows="2" placeholder="' . e(t('vendor-onboarding.justification_placeholder')) . '">' . e($val) . '</textarea></div>';
                        }
                        echo '</div>';
                    }
                    $vendorTypes = ['General Operations' => t('vendor-onboarding.vtype_general_operations'), 'Technology' => t('vendor-onboarding.vtype_technology'), 'Professional Services' => t('vendor-onboarding.vtype_professional_services'), 'Financial Services' => t('vendor-onboarding.vtype_financial_services'), 'Marketing' => t('vendor-onboarding.vtype_marketing'), 'HR/Benefits' => t('vendor-onboarding.vtype_hr_benefits'), 'Facilities' => t('vendor-onboarding.vtype_facilities'), 'Legal' => t('vendor-onboarding.vtype_legal'), 'Other' => t('vendor-onboarding.vtype_other')];
                    ?>

                    <!-- Vendor Information Card -->
                    <div class="vendor-card">
                        <div class="vendor-card-header"><?php echo e(t('vendor-onboarding.card_vendor_info')); ?></div>
                        <div class="vendor-card-body">
                            <?php dualField($request, 'vendor_name', t('vendor-onboarding.field_vendor_name')); ?>
                            <?php dualField($request, 'vendor_domain', t('vendor-onboarding.field_vendor_domain'), 'domain'); ?>
                            <?php dualField($request, 'vendor_sisterdomains', t('vendor-onboarding.field_sister_subdomains'), 'textarea'); ?>
                            <?php dualField($request, 'vendor_type', t('vendor-onboarding.field_vendor_type'), 'select', $vendorTypes); ?>
                            <?php dualField($request, 'vendor_use_ai', t('vendor-onboarding.field_services_use_ai'), 'checkbox'); ?>
                            <?php if ($canApprove && $editMode && ($request['vendor_use_ai'] ?? '') === 'yes' && ($request['status'] ?? '') !== 'ai_review'): ?>
                            <div class="vendor-field-row" style="justify-content: flex-end;">
                                <?php /* Force AI Review must not open its own form element here: vendorFieldsForm
                                          is already open, and a nested one would be flattened by the HTML parser
                                          and prematurely close the outer form, orphaning Save Changes. This button
                                          instead targets the separate forceAiReviewForm (rendered after
                                          vendorFieldsForm closes) via the HTML form= attribute. */ ?>
                                <button type="submit" form="forceAiReviewForm" data-confirm="<?php echo e(t('vendor-onboarding.confirm_force_ai_review')); ?>" style="background: none; border: none; padding: 0; color: #4f46e5; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline;"><?php echo e(t('vendor-onboarding.btn_force_ai_review')); ?></button>
                            </div>
                            <?php endif; ?>
                            <?php // Tier is read-only here — managed via SRS details page
                            $tier = getFieldValue($request, 'vendor_tier');
                            echo '<div class="vendor-field-row"><span class="vendor-field-label">' . e(t('vendor-onboarding.field_vendor_tier')) . '</span>';
                            if ($tier) {
                                $bg = $tier === '1' ? '#fef2f2' : ($tier === '3' ? '#f0fdf4' : '#fef3c7');
                                $cl = $tier === '1' ? '#991b1b' : ($tier === '3' ? '#166534' : '#92400e');
                                echo '<span class="vendor-field-value"><span style="padding:2px 8px;border-radius:10px;font-size:12px;background:' . $bg . ';color:' . $cl . ';">' . e($tierLabels[$tier] ?? $tier) . '</span></span>';
                            } else {
                                echo '<span class="vendor-field-value empty">' . e(t('vendor-onboarding.not_assigned')) . '</span>';
                            }
                            echo '</div>'; ?>
                            <?php dualField($request, 'vendor_id', t('vendor-onboarding.field_vendor_id')); ?>
                            <?php dualField($request, 'vat_number', t('vendor-onboarding.field_vat_number'), 'vat'); ?>
                            <?php dualField($request, 'vsu_onboarded', t('vendor-onboarding.field_procurement_onboarding'), 'yes_no'); ?>
                            <?php /* When Procurement Onboarding is not "yes", warn that automated
                                     vendor scoring stays disabled until it is completed. Server-rendered
                                     for the saved value and toggled live by JS when staff change the field. */ ?>
                            <div id="procurementScoringBanner" data-vsu-onboarding-banner
                                 style="display:<?php echo ((getFieldValue($request, 'vsu_onboarded') ?: '') === 'no') ? 'flex' : 'none'; ?>; align-items:flex-start; gap:8px; margin-top:10px; padding:10px 14px; background:#fff3cd; border:1px solid #ffeeba; border-radius:6px; color:#856404; font-size:13px; line-height:1.4;">
                                <span aria-hidden="true">&#9888;</span>
                                <span><?php echo e(t('vendor-onboarding.scoring_disabled_banner')); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Primary Contact Card -->
                    <div class="vendor-card">
                        <div class="vendor-card-header"><?php echo e(t('vendor-onboarding.card_primary_contact')); ?></div>
                        <div class="vendor-card-body">
                            <?php dualField($request, 'primary_contact_details', t('vendor-onboarding.field_contact_name')); ?>
                            <?php dualField($request, 'primary_contact_email', t('vendor-onboarding.field_email'), 'email'); ?>
                            <?php dualField($request, 'primary_contact_title', t('vendor-onboarding.field_title')); ?>
                            <?php dualField($request, 'primary_contact_phone', t('vendor-onboarding.field_phone'), 'phone'); ?>
                        </div>
                    </div>

                    <!-- Engagement Details Card -->
                    <div class="vendor-card">
                        <div class="vendor-card-header"><?php echo e(t('vendor-onboarding.card_engagement')); ?></div>
                        <div class="vendor-card-body">
                            <?php dualField($request, 'relationship_manager', t('vendor-onboarding.field_relationship_manager')); ?>
                            <?php dualField($request, 'expected_procurement_date', t('vendor-onboarding.field_expected_procurement'), 'date'); ?>
                            <?php dualField($request, 'product_service_description', t('vendor-onboarding.field_product_service'), 'textarea'); ?>
                            <?php dualField($request, 'target_user_count', t('vendor-onboarding.field_target_user_count'), 'number'); ?>
                            <?php dualField($request, 'cost_center', t('vendor-onboarding.field_cost_center'), 'integer'); ?>
                            <?php dualField($request, 'project', t('vendor-onboarding.field_project')); ?>
                            <?php dualField($request, 'nda_in_place', t('vendor-onboarding.field_nda_in_place'), 'yes_no'); ?>
                            <?php dualField($request, 'vendor_competitors', t('vendor-onboarding.field_competitors'), 'textarea'); ?>
                        </div>
                    </div>

                    <!-- Impact Assessment Card -->
                    <div class="vendor-card">
                        <div class="vendor-card-header"><?php echo e(t('vendor-onboarding.card_impact')); ?></div>
                        <div class="vendor-card-body">
                            <?php dualField($request, 'pii_record_count', t('vendor-onboarding.field_pii_records'), 'number'); ?>
                            <?php dualField($request, 'spii_record_count', t('vendor-onboarding.field_spii_records'), 'number'); ?>
                            <?php dualField($request, 'sox_record_count', t('vendor-onboarding.field_sox_records'), 'number'); ?>
                            <?php dualField($request, 'business_impact', t('vendor-onboarding.field_business_impact'), 'currency'); ?>
                            <?php dualField($request, 'unauthorized_disclosure_impact', t('vendor-onboarding.field_disclosure_impact'), 'severity'); ?>
                            <?php dualJustification($request, 'unauthorized_disclosure_justification'); ?>
                            <?php dualField($request, 'unauthorized_modification_impact', t('vendor-onboarding.field_modification_impact'), 'severity'); ?>
                            <?php dualField($request, 'disruption_impact', t('vendor-onboarding.field_disruption_impact'), 'severity'); ?>
                        </div>
                    </div>

                    <!-- Data & Security Card -->
                    <div class="vendor-card">
                        <div class="vendor-card-header"><?php echo e(t('vendor-onboarding.card_data_security')); ?></div>
                        <div class="vendor-card-body">
                            <?php dualField($request, 'confidential_info_shared', t('vendor-onboarding.field_confidential_info_shared'), 'yes_no'); ?>
                            <?php dualJustification($request, 'confidential_info_justification'); ?>
                            <?php dualField($request, 'cross_border_transfer', t('vendor-onboarding.field_cross_border_transfer'), 'yes_no'); ?>
                            <?php dualJustification($request, 'cross_border_justification'); ?>
                            <?php dualField($request, 'offsite_data_hosting', t('vendor-onboarding.field_offsite_data_hosting'), 'yes_no'); ?>
                            <?php dualJustification($request, 'offsite_data_justification'); ?>
                            <?php dualField($request, 'remote_network_access', t('vendor-onboarding.field_remote_network_access'), 'yes_no'); ?>
                            <?php dualJustification($request, 'remote_access_justification'); ?>
                            <?php dualField($request, 'source_code_access', t('vendor-onboarding.field_source_code_access'), 'yes_no'); ?>
                            <?php dualJustification($request, 'source_code_justification'); ?>
                            <?php dualField($request, 'critical_business_function', t('vendor-onboarding.field_critical_business_function'), 'yes_no'); ?>
                            <?php dualJustification($request, 'critical_function_justification'); ?>
                            <?php dualField($request, 'saml_sso_support', t('vendor-onboarding.field_saml_sso_support'), 'yes_no_unknown'); ?>
                            <?php dualField($request, 'is_saas', t('vendor-onboarding.field_saas_product'), 'yes_no'); ?>
                        </div>
                    </div>

                    <!-- Tier Justification Card (full-width) -->
                    <div class="vendor-card vendor-card-full">
                        <div class="vendor-card-header"><?php echo e(t('vendor-onboarding.card_tier_justification')); ?></div>
                        <div class="vendor-card-body">
                            <?php $addlInfo = getFieldValue($request, 'additional_information'); ?>
                            <div class="vendor-field-display">
                                <?php if ($addlInfo): ?>
                                    <div style="font-size: 13px; color: #333; line-height: 1.6;"><?php echo nl2br(e($addlInfo)); ?></div>
                                <?php else: ?>
                                    <span class="vendor-field-value empty"><?php echo e(t('vendor-onboarding.not_provided')); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($canEditTier): ?>
                            <div class="vendor-field-edit" style="display:none;">
                                <textarea name="additional_information" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:13px;font-family:inherit;" rows="4"><?php echo e($addlInfo); ?></textarea>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($canEdit || $canEditProcurementFields): ?>
                <div id="editActions" style="display:none; margin: 15px 0; gap: 10px;">
                    <button type="submit" class="btn" style="background: var(--theme-header-color); color: white; padding: 8px 20px;" id="saveFieldsBtn"><?php echo e(t('vendor-onboarding.btn_save_changes')); ?></button>
                    <button type="button" class="btn btn-outline" style="padding: 8px 20px;" id="cancelEditBtn"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                </div>
                </form>
                <?php endif; ?>

                <?php // Separate form for the "Force AI Review" action, kept OUTSIDE vendorFieldsForm
                      // so it never nests (which would orphan the Save Changes button above). The
                      // Force AI Review button (in the Vendor Information card) targets this via form=. ?>
                <?php if ($canApprove && $editMode && ($request['vendor_use_ai'] ?? '') === 'yes' && ($request['status'] ?? '') !== 'ai_review'): ?>
                <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>" id="forceAiReviewForm" style="display:none;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="force_ai_review">
                </form>
                <?php endif; ?>

                <div style="margin-bottom: 10px;">
                    <a href="vendor-onboarding-list.php" class="btn btn-outline"><?php echo e(t('vendor-onboarding.btn_back_to_list')); ?></a>
                </div>


                <?php
                // Check if user can create FAIR analysis from this onboarding
                $canCreateFairAnalysis = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');
                ?>

                <?php if ($editMode && $canCreateFairAnalysis && in_array($request['status'], ['draft', 'submitted', 'in_review', 'approved'])): ?>
                <!-- FAIR Analysis Action -->
                <div style="margin-top: 20px; padding: 15px; background: #e7f3ff; border: 1px solid #b8daff; border-radius: 4px;">
                    <strong style="color: #004085;"><?php echo e(t('vendor-onboarding.risk_assessment_label')); ?></strong>
                    <div style="margin-top: 10px;">
                        <a href="fair-analysis.php?from_onboarding=<?php echo $requestId; ?>" class="btn btn-primary">
                            <?php echo e(t('vendor-onboarding.start_fair_analysis')); ?>
                        </a>
                        <small style="display: block; margin-top: 8px; color: #666;">
                            <?php echo e(t('vendor-onboarding.fair_analysis_hint')); ?>
                        </small>
                    </div>
                </div>
                <?php endif; ?>


                <?php if ($editMode && $canEditTier && !empty($request['vendor_domain'])): ?>
                <!-- SRS Security Rating -->
                <div style="margin-top: 20px; padding: 15px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px;">
                    <strong style="color: #166534;"><?php echo e(t('vendor-onboarding.srs_label')); ?></strong>
                    <div style="margin-top: 10px;">
                        <a href="vendor-srs-details.php?id=<?php echo $requestId; ?>" class="btn" style="background: #166534; color: white;">
                            <?php echo e(t('vendor-onboarding.view_srs_details')); ?>
                        </a>
                        <?php if (!empty($request['current_srs_score'])): ?>
                            <span style="margin-left: 15px; color: #333;">
                                <?php echo e($upguardDisplayName); ?>: <strong><?php echo intval($request['current_srs_score']); ?></strong>
                                (<span style="font-weight: 600;"><?php echo $srsService->calculateGrade(intval($request['current_srs_score'])); ?></span>)
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($request['current_shodan_score'])): ?>
                            <span style="margin-left: 15px; color: #333;">
                                <?php echo e($shodanDisplayName); ?>: <strong><?php echo intval($request['current_shodan_score']); ?></strong>
                                (<span style="font-weight: 600;"><?php echo $shodanService->calculateGrade(intval($request['current_shodan_score'])); ?></span>)
                            </span>
                        <?php endif; ?>
                        <small style="display: block; margin-top: 8px; color: #666;">
                            <?php echo e(t('vendor-onboarding.srs_hint')); ?>
                        </small>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($editMode && ($canEdit || $canDeactivate || $canDelete || $canApprove || $canEditTier)): ?>
                <!-- Administrative actions -->
                <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">
                    <strong style="color: #333;"><?php echo e(t('vendor-onboarding.admin_actions_label')); ?></strong>
                    <div style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php if ($canEdit && $request['status'] === 'draft'): ?>
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="submit_for_review">
                            <button type="submit" class="btn btn-primary" data-confirm="<?php echo e(t('vendor-onboarding.confirm_submit_review')); ?>">
                                <?php echo e(t('vendor-onboarding.btn_submit_review')); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($canEditTier): ?>
                        <button type="button" class="btn" style="background: <?php echo empty($request['vendor_tier']) ? '#dc2626' : '#f59e0b'; ?>; color: white;" data-action="openTierModal">
                            <?php echo empty($request['vendor_tier']) ? t('vendor-onboarding.tier_label') : t('vendor-onboarding.btn_re_tier'); ?>
                        </button>
                        <?php endif; ?>
                        <?php if ($canApprove && in_array($request['status'], ['submitted', 'approved', 'inactive'])): ?>
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="set_in_review">
                            <button type="submit" class="btn" style="background: #6f42c1; color: white;" data-confirm="<?php echo e(t('vendor-onboarding.confirm_mark_in_review')); ?>">
                                <?php echo e(t('vendor-onboarding.btn_mark_in_review')); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($canApprove && in_array($request['status'], ['draft', 'submitted', 'in_review', 'inactive'])): ?>
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="set_approved">
                            <button type="submit" class="btn" style="background: #28a745; color: white;" data-confirm="<?php echo e(t('vendor-onboarding.confirm_approve')); ?>">
                                <?php echo e(t('vendor-onboarding.btn_mark_approved')); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($canApprove && $request['status'] === 'inactive'): ?>
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="set_draft">
                            <button type="submit" class="btn" style="background: #f59e0b; color: white;" data-confirm="<?php echo e(t('vendor-onboarding.confirm_back_to_draft')); ?>">
                                <?php echo e(t('vendor-onboarding.btn_mark_draft')); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($canDeactivate && $request['status'] !== 'inactive'): ?>
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="deactivate">
                            <button type="submit" class="btn btn-danger" data-confirm="<?php echo e(t('vendor-onboarding.confirm_deactivate')); ?>">
                                <?php echo e(t('vendor-onboarding.btn_mark_inactive')); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="btn btn-danger" data-confirm="<?php echo e(t('vendor-onboarding.confirm_delete')); ?>">
                                <?php echo e(t('vendor-onboarding.btn_delete_permanently')); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                </div><!-- /vendorTabContent_vendor -->


                <?php if ($showGripSaasTab): ?>
                <!-- Tab: SaaS Data (Grip telemetry) -->
                <div id="vendorTabContent_saas_data" style="<?php echo $activeTab !== 'saas_data' ? 'display: none;' : ''; ?>">
                    <?php
                    // Display helper: scalar/array → escaped string or an em-dash.
                    $gripShow = function ($v) {
                        if ($v === null || $v === '' || $v === []) return '<span style="color:#9ca3af;">&mdash;</span>';
                        if (is_array($v)) $v = implode(', ', $v);
                        return e((string)$v);
                    };
                    $gripDate = function ($v) {
                        if (empty($v)) return '<span style="color:#9ca3af;">&mdash;</span>';
                        $ts = strtotime((string)$v);
                        return e($ts ? date('M j, Y', $ts) : (string)$v);
                    };
                    // Round SecurityScorecard badge (A/B/C/D/F), matching shadow-saas.php /
                    // vendor-srs-list.php. Grip exposes only a letter grade, no number.
                    $gripSscBadge = function ($rating) {
                        $r = strtoupper(trim((string)$rating));
                        if (!in_array($r, ['A', 'B', 'C', 'D', 'F'], true)) {
                            return '<span style="color:#9ca3af;">&mdash;</span>';
                        }
                        $map = ['A' => ['#dcfce7', '#166534'], 'B' => ['#d1fae5', '#065f46'], 'C' => ['#fef3c7', '#92400e'], 'D' => ['#ffedd5', '#9a3412'], 'F' => ['#fee2e2', '#991b1b']];
                        [$bg, $fg] = $map[$r];
                        return '<span title="SecurityScorecard rating ' . $r . '" style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;font-weight:700;font-size:11px;background:' . $bg . ';color:' . $fg . ';">' . $r . '</span>';
                    };
                    $rowStyle  = 'display:flex; justify-content:space-between; gap:16px; padding:10px 0; border-bottom:1px solid #f1f3f5;';
                    $lblStyle  = 'color:#6b7280; font-size:13px; font-weight:500;';
                    $valStyle  = 'color:#111827; font-size:13px; font-weight:600; text-align:right; word-break:break-word;';
                    $cardStyle = 'flex:1 1 320px; min-width:280px; background:white; border:1px solid #e5e7eb; border-radius:8px; padding:25px;';
                    $hdrStyle  = 'margin:0 0 16px; font-size:15px; font-weight:600; color:var(--theme-header-color); display:flex; align-items:center; gap:8px;';
                    ?>
                    <div style="display:flex; flex-wrap:wrap; gap:20px;">
                        <!-- App Info -->
                        <div style="<?php echo $cardStyle; ?>">
                            <h3 style="<?php echo $hdrStyle; ?>"><?php echo e(t('vendor-onboarding.grip_app_info')); ?></h3>
                            <div style="<?php echo $rowStyle; ?>">
                                <span style="<?php echo $lblStyle; ?>"><?php echo e(t('vendor-onboarding.grip_first_discovered')); ?></span>
                                <span style="<?php echo $valStyle; ?>"><?php echo $gripDate($gripSaasData['first_event_time'] ?? null); ?></span>
                            </div>
                            <div style="<?php echo $rowStyle; ?>">
                                <span style="<?php echo $lblStyle; ?>"><?php echo e(t('vendor-onboarding.grip_active_accounts')); ?></span>
                                <span style="<?php echo $valStyle; ?>">
                                    <?php
                                    if (isset($gripSaasData['number_of_users']) && $gripSaasData['number_of_users'] !== null) {
                                        $accCount = number_format((int)$gripSaasData['number_of_users']);
                                        $accGripId = (string)($gripSaasData['grip_id'] ?? '');
                                        if ($accGripId !== '') {
                                            echo '<a href="grip-saas-users.php?id=' . urlencode($accGripId) . '" target="_blank" rel="noopener" style="color:var(--theme-header-color); text-decoration:none;">' . e($accCount) . '</a>';
                                        } else {
                                            echo e($accCount);
                                        }
                                    } else {
                                        echo '<span style="color:#9ca3af;">&mdash;</span>';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div style="<?php echo $rowStyle; ?>">
                                <span style="<?php echo $lblStyle; ?>"><?php echo e(t('vendor-onboarding.grip_last_known_usage')); ?></span>
                                <span style="<?php echo $valStyle; ?>"><?php echo $gripDate($gripSaasData['last_known_usage'] ?? null); ?></span>
                            </div>
                            <div style="<?php echo $rowStyle; ?> border-bottom:none;">
                                <span style="<?php echo $lblStyle; ?>"><?php echo e(t('vendor-onboarding.grip_app_classification')); ?></span>
                                <span style="<?php echo $valStyle; ?>"><?php echo $gripShow($gripSaasData['sanction_tag'] ?? null); ?></span>
                            </div>
                        </div>
                        <!-- Vendor Information -->
                        <div style="<?php echo $cardStyle; ?>">
                            <h3 style="<?php echo $hdrStyle; ?>"><?php echo e(t('vendor-onboarding.card_vendor_info')); ?></h3>
                            <div style="<?php echo $rowStyle; ?>">
                                <span style="<?php echo $lblStyle; ?>"><?php echo e(t('vendor-onboarding.grip_security_scorecard')); ?></span>
                                <span style="<?php echo $valStyle; ?> display:inline-flex; align-items:center; justify-content:flex-end;"><?php echo $gripSscBadge($request['security_scorecard_rating'] ?? null); ?></span>
                            </div>
                            <div style="<?php echo $rowStyle; ?>">
                                <span style="<?php echo $lblStyle; ?>"><?php echo e(t('vendor-onboarding.grip_category')); ?></span>
                                <span style="<?php echo $valStyle; ?>"><?php echo $gripShow($gripSaasData['category'] ?? null); ?></span>
                            </div>
                            <div style="<?php echo $rowStyle; ?>">
                                <span style="<?php echo $lblStyle; ?>"><?php echo e(t('vendor-onboarding.grip_ai_depth')); ?></span>
                                <span style="<?php echo $valStyle; ?>"><?php echo $gripShow($gripSaasData['ai_depth'] ?? null); ?></span>
                            </div>
                            <div style="<?php echo $rowStyle; ?>">
                                <span style="<?php echo $lblStyle; ?>"><?php echo e(t('vendor-onboarding.grip_compliance')); ?></span>
                                <span style="<?php echo $valStyle; ?>"><?php echo $gripShow($gripSaasData['compliances'] ?? null); ?></span>
                            </div>
                            <div style="<?php echo $rowStyle; ?>">
                                <span style="<?php echo $lblStyle; ?>"><?php echo e(t('vendor-onboarding.grip_saml_support')); ?></span>
                                <span style="<?php echo $valStyle; ?>"><?php echo $gripShow($gripSaasData['saml_supported'] ?? null); ?></span>
                            </div>
                            <div style="<?php echo $rowStyle; ?> border-bottom:none;">
                                <span style="<?php echo $lblStyle; ?>"><?php echo e(t('vendor-onboarding.grip_mfa_support')); ?></span>
                                <span style="<?php echo $valStyle; ?>"><?php echo $gripShow($gripSaasData['mfa_supported'] ?? null); ?></span>
                            </div>
                        </div>
                    </div>
                </div><!-- /vendorTabContent_saas_data -->
                <?php endif; ?>


                <?php if ($showVendorTabs && !empty($customOnboardingData)): ?>
                <!-- Tab: Custom Data -->
                <div id="vendorTabContent_custom_data" style="<?php echo $activeTab !== 'custom_data' ? 'display: none;' : ''; ?>">
                    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 25px;">
                        <div style="margin-bottom: 20px;">
                            <h3 style="margin: 0 0 4px; font-size: 18px; font-weight: 600; color: #333;"><?php echo e(t('vendor-onboarding.tab_custom_data')); ?><?php if (!empty($request['vendor_name'])): ?> (<?php echo e($request['vendor_name']); ?>)<?php endif; ?></h3>
                            <p style="margin: 0; color: #6b7280; font-size: 13px;"><?php echo e(t('vendor-onboarding.custom_data_intro')); ?></p>
                        </div>
                        <?php
                        // Group the custom fields by their template section for display.
                        $customBySection = [];
                        foreach ($customOnboardingData as $cf) {
                            $customBySection[$cf['section'] !== '' ? $cf['section'] : t('vendor-onboarding.custom_fields_section')][] = $cf;
                        }
                        // Custom-field editing is grant-only: a field is editable only when the
                        // viewer holds an explicit edit grant (can_edit, computed per role in the
                        // service) AND can edit the vendor record. A view-only user (e.g. a
                        // stakeholder without an edit grant) gets no Edit button and read-only
                        // fields. The tab opens read-only; ?edit=1 switches to the edit form.
                        $anyCustomEditable = false;
                        if (!empty($canEdit) && $editMode) {
                            foreach ($customOnboardingData as $cf) {
                                if (!empty($cf['can_edit'])) { $anyCustomEditable = true; break; }
                            }
                        }
                        $customEditMode = $anyCustomEditable && (($_GET['edit'] ?? '') === '1');
                        // Parse a question's stored options into a flat list of choice strings.
                        $customOptionList = function ($raw) {
                            if (is_array($raw)) return array_values(array_filter(array_map('strval', $raw), function ($s) { return $s !== ''; }));
                            $raw = (string)$raw;
                            if ($raw === '') return [];
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded)) {
                                $vals = [];
                                foreach ($decoded as $d) { $vals[] = is_array($d) ? (string)($d['value'] ?? $d['label'] ?? '') : (string)$d; }
                                return array_values(array_filter($vals, function ($s) { return $s !== ''; }));
                            }
                            return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), function ($s) { return $s !== ''; }));
                        };
                        ?>
                        <?php if (!$customEditMode && $anyCustomEditable): ?>
                        <div style="margin-bottom: 16px;">
                            <a href="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=custom_data&edit=1" class="btn btn-secondary" style="padding: 7px 18px; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_edit')); ?></a>
                        </div>
                        <?php endif; ?>
                        <?php if ($customEditMode): ?>
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=custom_data" id="customDataForm">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="update_custom_onboarding">
                        <?php endif; ?>
                        <?php foreach ($customBySection as $sectionName => $fields): ?>
                        <div style="margin-bottom: 22px;">
                            <div style="font-weight: 600; font-size: 13px; color: #444; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #f0f0f0;"><?php echo e($sectionName); ?></div>
                            <?php foreach ($fields as $cf):
                                $fieldEditable = $customEditMode && !empty($cf['can_edit']);
                            ?>
                            <div style="display: flex; gap: 12px; padding: 8px 0; border-bottom: 1px solid #f7f7f7; align-items: flex-start;">
                                <div style="min-width: 240px; max-width: 240px;">
                                    <div style="font-size: 13px; color: #333; font-weight: 500;"><?php echo e($cf['label']); ?></div>
                                </div>
                                <div style="flex: 1; word-break: break-word;">
                                    <?php if ($fieldEditable):
                                        $cfName = 'custom[' . e($cf['field_name']) . ']';
                                        // Raw (un-escaped) name for widget helpers, which
                                        // escape the name attribute themselves.
                                        $cfNameRaw = 'custom[' . $cf['field_name'] . ']';
                                        $cfType = $cf['type'] ?? 'text';
                                        $cfWidgetId = 'cf_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$cf['field_name']);
                                        if ($cfType === 'textarea'): ?>
                                        <textarea name="<?php echo $cfName; ?>" rows="3" class="form-control" style="width:100%; font-size:13px;"><?php echo e($cf['value']); ?></textarea>
                                        <?php elseif (in_array($cfType, ['select', 'radio', 'button_group'], true)):
                                            $opts = $customOptionList($cf['options'] ?? null); ?>
                                        <select name="<?php echo $cfName; ?>" class="form-control" style="width:100%; font-size:13px;">
                                            <option value=""><?php echo t('vendor-onboarding.opt_select_dash'); ?></option>
                                            <?php foreach ($opts as $opt): ?>
                                            <option value="<?php echo e($opt); ?>" <?php echo ($opt === $cf['value']) ? 'selected' : ''; ?>><?php echo e($opt); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php elseif (in_array($cfType, ['checkbox', 'button_group_multi'], true)):
                                            $opts = $customOptionList($cf['options'] ?? null);
                                            $selList = is_array($cf['value_list'] ?? null) ? $cf['value_list'] : array(); ?>
                                        <input type="hidden" name="custom_present[<?php echo e($cf['field_name']); ?>]" value="1">
                                        <div style="display:flex; flex-wrap:wrap; gap:6px 16px;">
                                            <?php foreach ($opts as $opt): ?>
                                            <label style="display:inline-flex; align-items:center; gap:6px; font-size:13px; cursor:pointer;">
                                                <input type="checkbox" name="<?php echo $cfName; ?>[]" value="<?php echo e($opt); ?>" <?php echo in_array($opt, $selList, true) ? 'checked' : ''; ?>>
                                                <?php echo e($opt); ?>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php elseif ($cfType === 'phone'): ?>
                                        <?php echo phone_render_widget($cfNameRaw, $cf['value'], ['id' => $cfWidgetId]); ?>
                                        <?php elseif ($cfType === 'vat'): ?>
                                        <?php echo vat_render_widget($cfNameRaw, $cf['value'], ['id' => $cfWidgetId, 'vies_endpoint' => 'api/vat-validate.php']); ?>
                                        <?php else: ?>
                                        <input type="<?php echo ($cfType === 'number') ? 'number' : (($cfType === 'date') ? 'date' : (($cfType === 'email') ? 'email' : 'text')); ?>" name="<?php echo $cfName; ?>" value="<?php echo e($cf['value']); ?>" class="form-control" style="width:100%; font-size:13px;">
                                        <?php endif; ?>
                                    <?php else:
                                        $cfDispType = $cf['type'] ?? 'text';
                                        $cfRaw = (string)$cf['value'];
                                        if ($cfRaw === '') {
                                            $cfDisp = '&mdash;';
                                        } elseif ($cfDispType === 'phone') {
                                            $cfDisp = '<a href="tel:' . e(phone_normalize_e164($cfRaw)) . '">' . e(phone_display($cfRaw)) . '</a>';
                                        } else {
                                            $cfDisp = nl2br(e($cfRaw));
                                        }
                                    ?>
                                        <span style="font-size: 13px; color: <?php echo ($cf['value'] === '' ? '#9ca3af' : '#1f2937'); ?>;"><?php echo $cfDisp; ?></span>
                                        <?php if ($customEditMode && empty($cf['can_edit'])): ?><span style="font-size: 11px; color: #9ca3af; margin-left: 8px;"><?php echo e(t('vendor-onboarding.view_only')); ?></span><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($customEditMode): ?>
                            <div style="margin-top: 18px; display: flex; gap: 10px;">
                                <button type="submit" class="btn btn-primary" style="padding: 9px 22px; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_save_custom_data')); ?></button>
                                <a href="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=custom_data" class="btn btn-secondary" style="padding: 9px 22px; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></a>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div><!-- /vendorTabContent_custom_data -->
                <?php endif; ?>


                <?php if ($showVendorTabs): ?>
                <!-- Tab: Case Management -->
                <div id="vendorTabContent_notes" style="<?php echo $activeTab !== 'notes' ? 'display: none;' : ''; ?>">
                    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 25px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;"><?php echo e(t('vendor-onboarding.tab_case_management')); ?><?php if (!empty($request['vendor_name'])): ?> (<?php echo e($request['vendor_name']); ?>)<?php endif; ?></h3>
                            <?php if ($canAddNotes): ?>
                            <button type="button" id="createCaseNoteBtn" class="btn btn-primary" style="padding: 8px 18px; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_create_case')); ?></button>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($caseStatusFilter)): ?>
                        <div style="margin-bottom: 15px; display: flex; gap: 8px; align-items: center;">
                            <span style="font-size: 12px; color: #666;"><?php echo e(t('vendor-onboarding.showing_label')); ?></span>
                            <span style="font-size: 12px; padding: 3px 10px; border-radius: 10px; background: <?php echo $caseStatusFilter === 'open' ? '#dbeafe' : '#dcfce7'; ?>; color: <?php echo $caseStatusFilter === 'open' ? '#1e40af' : '#166534'; ?>; font-weight: 500;">
                                <?php echo $caseStatusFilter === 'open' ? t('vendor-onboarding.filter_open_cases') : t('vendor-onboarding.filter_closed_cases'); ?>
                            </span>
                            <a href="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=notes" style="font-size: 12px; color: #6b7280; text-decoration: none;"><?php echo e(t('vendor-onboarding.btn_show_all')); ?></a>
                        </div>
                        <?php endif; ?>

                        <?php if (empty($vendorNotes)): ?>
                            <p style="color: #999; text-align: center; padding: 30px 0;"><?php echo e(t('vendor-onboarding.no_cases_found')); ?></p>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 16px;">
                            <?php foreach ($vendorNotes as $note):
                                $noteStatusColors = [
                                    'open' => ['bg' => '#dbeafe', 'color' => '#1e40af'],
                                    'in_progress' => ['bg' => '#fef3c7', 'color' => '#92400e'],
                                    'closed' => ['bg' => '#dcfce7', 'color' => '#166534'],
                                    'deferred' => ['bg' => '#f3f4f6', 'color' => '#6b7280'],
                                ];
                                $nsc = $noteStatusColors[$note['status']] ?? ['bg' => '#f3f4f6', 'color' => '#333'];
                                $replies = $noteReplies[$note['id']] ?? [];
                                $replyCount = count($replies);
                                // Author may delete a case only if nobody else has replied to it
                                // (closing is the alternative once a thread is shared).
                                $caseHasOtherReplies = false;
                                foreach ($replies as $rr) {
                                    if ((int)$rr['created_by'] !== (int)$note['created_by']) { $caseHasOtherReplies = true; break; }
                                }
                            ?>
                                <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; border-left: 4px solid <?php echo $nsc['color']; ?>;">
                                    <!-- Structured case fields -->
                                    <div style="padding: 16px 16px 12px 16px;">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                                            <div style="font-size: 14px; color: #333;"><strong><?php echo e(t('vendor-onboarding.label_title_colon')); ?></strong> <?php echo e($note['title']); ?></div>
                                            <div style="display: flex; gap: 6px; align-items: center;">
                                                <span style="font-size: 10px; padding: 2px 8px; border-radius: 10px; background: <?php echo $nsc['bg']; ?>; color: <?php echo $nsc['color']; ?>; font-weight: 500; text-transform: uppercase; white-space: nowrap;"><?php echo e(str_replace('_', ' ', $note['status'])); ?></span>
                                                <?php if ($canAddNotes): ?>
                                                <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=notes" style="display: inline; margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                    <input type="hidden" name="toggle_case_status" value="1">
                                                    <input type="hidden" name="case_id" value="<?php echo intval($note['id']); ?>">
                                                    <button type="submit" style="font-size: 10px; padding: 2px 8px; border-radius: 10px; border: 1px solid #d1d5db; background: #fff; color: #666; cursor: pointer; white-space: nowrap;" title="<?php echo $note['status'] === 'closed' ? t('vendor-onboarding.title_reopen_case') : t('vendor-onboarding.title_close_case'); ?>">
                                                        <?php echo $note['status'] === 'closed' ? t('vendor-onboarding.btn_reopen') : t('vendor-onboarding.btn_close'); ?>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                <?php if ($canAddNotes && ($isAdmin || (int)$note['created_by'] === (int)$user['id']) && $note['status'] !== 'closed'): ?>
                                                <button type="button" data-toggle="editCase_<?php echo intval($note['id']); ?>" style="font-size: 10px; padding: 2px 8px; border-radius: 10px; border: 1px solid #d1d5db; background: #fff; color: #666; cursor: pointer; white-space: nowrap;" title="<?php echo e(t('vendor-onboarding.title_edit_case')); ?>"><?php echo e(t('vendor-onboarding.btn_edit')); ?></button>
                                                <?php if (!$caseHasOtherReplies || $isAdmin): ?>
                                                <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=notes" style="display: inline; margin: 0;" data-confirm="<?php echo e(t('vendor-onboarding.confirm_delete_case')); ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                    <input type="hidden" name="delete_case" value="1">
                                                    <input type="hidden" name="case_id" value="<?php echo intval($note['id']); ?>">
                                                    <button type="submit" style="font-size: 10px; padding: 2px 8px; border-radius: 10px; border: 1px solid #fecaca; background: #fff; color: #991b1b; cursor: pointer; white-space: nowrap;" title="<?php echo e(t('vendor-onboarding.title_delete_case')); ?>"><?php echo e(t('vendor-onboarding.btn_delete')); ?></button>
                                                </form>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($note['description'])): ?>
                                        <div style="font-size: 13px; color: #555; margin-bottom: 8px; line-height: 1.5;"><strong><?php echo e(t('vendor-onboarding.label_description_colon')); ?></strong> <?php echo nl2br(e($note['description'])); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($note['due_date'])): ?>
                                        <div style="font-size: 13px; color: #555; margin-bottom: 4px;">
                                            <strong><?php echo e(t('vendor-onboarding.label_due_date_colon')); ?></strong> <?php echo date('M j, Y', strtotime($note['due_date'])); ?>
                                            <?php if ($note['status'] !== 'closed' && strtotime($note['due_date']) < strtotime('today')): ?>
                                                <span style="color: #dc2626; font-weight: 500;"><?php echo e(t('vendor-onboarding.overdue')); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                        <div style="font-size: 13px; color: #555; margin-bottom: 4px;"><strong><?php echo e(t('vendor-onboarding.label_opened_by')); ?></strong> <?php echo e($note['created_by_name'] ?? t('vendor-onboarding.unknown')); ?></div>
                                        <div style="font-size: 13px; color: #555; margin-bottom: 4px;">
                                            <strong><?php echo e(t('vendor-onboarding.label_assigned_to')); ?></strong>
                                            <?php if (!empty($note['assigned_to_name'])): ?>
                                                <?php echo e($note['assigned_to_name']); ?>
                                            <?php else: ?>
                                                <span style="color: #999;"><?php echo e(t('vendor-onboarding.unassigned')); ?></span>
                                            <?php endif; ?>
                                            <?php if ($canAddNotes): ?>
                                                <button type="button" data-action="openCaseReassignModal" data-case-id="<?php echo intval($note['id']); ?>" data-current-assignee="<?php echo e($note['assigned_to_name'] ?? ''); ?>" style="padding: 2px 8px; font-size: 11px; background: #6b7280; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 6px;"><?php echo !empty($note['assigned_to']) ? t('vendor-onboarding.btn_reassign') : t('vendor-onboarding.btn_assign'); ?></button>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 12px; color: #999; margin-top: 6px;">
                                            <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?>
                                        </div>
                                    </div>

                                    <!-- Inline case edit form (author only, hidden by default; frozen once closed) -->
                                    <?php if ($canAddNotes && ($isAdmin || (int)$note['created_by'] === (int)$user['id']) && $note['status'] !== 'closed'): ?>
                                    <div id="editCase_<?php echo intval($note['id']); ?>" style="display: none; padding: 0 16px 14px 16px;">
                                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=notes">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="edit_case" value="1">
                                            <input type="hidden" name="case_id" value="<?php echo intval($note['id']); ?>">
                                            <label style="display: block; font-size: 12px; font-weight: 500; color: #333; margin-bottom: 4px;"><?php echo e(t('vendor-onboarding.field_title')); ?> <span style="color: #dc3545;">*</span></label>
                                            <input type="text" name="note_title" required value="<?php echo e($note['title']); ?>" style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box; margin-bottom: 8px;">
                                            <label style="display: block; font-size: 12px; font-weight: 500; color: #333; margin-bottom: 4px;"><?php echo e(t('vendor-onboarding.label_description')); ?></label>
                                            <textarea name="note_description" rows="3" style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; box-sizing: border-box; margin-bottom: 8px;"><?php echo e($note['description']); ?></textarea>
                                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                                <button type="button" data-close="editCase_<?php echo intval($note['id']); ?>" style="padding: 6px 14px; border: 1px solid #d1d5db; background: #fff; color: #666; border-radius: 6px; cursor: pointer; font-size: 12px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                                                <button type="submit" style="padding: 6px 14px; border: none; background: var(--theme-button-color, #35a0a3); color: white; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500;"><?php echo e(t('vendor-onboarding.btn_save_changes')); ?></button>
                                            </div>
                                        </form>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Responses section -->
                                    <?php if ($replyCount > 0): ?>
                                    <div style="border-top: 1px solid #e5e7eb; padding: 12px 16px; background: #f9fafb;">
                                        <div style="font-size: 12px; font-weight: 600; color: #666; margin-bottom: 10px;"><?php echo e(t('vendor-onboarding.responses_label')); ?> (<?php echo $replyCount; ?>)</div>
                                        <div style="display: flex; flex-direction: column; gap: 8px;">
                                        <?php foreach ($replies as $reply): ?>
                                            <div style="padding: 8px 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; border-left: 3px solid #9ca3af;">
                                                <div style="font-size: 11px; color: #999; margin-bottom: 4px;">
                                                    <strong><?php echo e($reply['created_by_name'] ?? t('vendor-onboarding.unknown')); ?></strong>
                                                    &middot; <?php echo date('M j, Y g:i A', strtotime($reply['created_at'])); ?>
                                                </div>
                                                <div style="font-size: 13px; color: #333; line-height: 1.5;"><?php echo nl2br(e($reply['description'])); ?></div>
                                                <?php if ($canAddNotes && ($isAdmin || (int)$reply['created_by'] === (int)$user['id']) && $note['status'] !== 'closed'): ?>
                                                <div style="margin-top: 6px; display: flex; gap: 10px;">
                                                    <button type="button" data-toggle="editReply_<?php echo intval($reply['id']); ?>" style="font-size: 11px; color: #6b7280; background: none; border: none; cursor: pointer; padding: 0;"><?php echo e(t('vendor-onboarding.btn_edit')); ?></button>
                                                    <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=notes" style="display: inline; margin: 0;" data-confirm="<?php echo e(t('vendor-onboarding.confirm_delete_response')); ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                        <input type="hidden" name="delete_reply" value="1">
                                                        <input type="hidden" name="reply_id" value="<?php echo intval($reply['id']); ?>">
                                                        <button type="submit" style="font-size: 11px; color: #991b1b; background: none; border: none; cursor: pointer; padding: 0;"><?php echo e(t('vendor-onboarding.btn_delete')); ?></button>
                                                    </form>
                                                </div>
                                                <div id="editReply_<?php echo intval($reply['id']); ?>" style="display: none; margin-top: 8px;">
                                                    <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=notes">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                        <input type="hidden" name="edit_reply" value="1">
                                                        <input type="hidden" name="reply_id" value="<?php echo intval($reply['id']); ?>">
                                                        <textarea name="reply_description" rows="2" required style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; box-sizing: border-box; margin-bottom: 8px;"><?php echo e($reply['description']); ?></textarea>
                                                        <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                                            <button type="button" data-close="editReply_<?php echo intval($reply['id']); ?>" style="padding: 6px 14px; border: 1px solid #d1d5db; background: #fff; color: #666; border-radius: 6px; cursor: pointer; font-size: 12px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                                                            <button type="submit" style="padding: 6px 14px; border: none; background: var(--theme-button-color, #35a0a3); color: white; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500;"><?php echo e(t('vendor-onboarding.btn_save')); ?></button>
                                                        </div>
                                                    </form>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Reply link and inline form -->
                                    <?php if ($canAddNotes): ?>
                                    <div style="border-top: 1px solid #e5e7eb; padding: 10px 16px;">
                                        <button type="button" data-action="toggleReplyForm" data-arg="<?php echo intval($note['id']); ?>" style="font-size: 12px; color: var(--theme-header-color, #35a0a3); background: none; border: none; cursor: pointer; padding: 0; font-weight: 500;"><?php echo e(t('vendor-onboarding.btn_reply')); ?></button>
                                        <div id="replyForm_<?php echo intval($note['id']); ?>" style="display: none; margin-top: 10px;">
                                            <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=notes">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="add_reply" value="1">
                                                <input type="hidden" name="parent_case_id" value="<?php echo intval($note['id']); ?>">
                                                <textarea name="reply_description" rows="2" required placeholder="<?php echo e(t('vendor-onboarding.placeholder_write_response')); ?>" style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; box-sizing: border-box; margin-bottom: 8px;"></textarea>
                                                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                                    <button type="button" data-action="toggleReplyForm" data-arg="<?php echo intval($note['id']); ?>" style="padding: 6px 14px; border: 1px solid #d1d5db; background: #fff; color: #666; border-radius: 6px; cursor: pointer; font-size: 12px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                                                    <button type="submit" style="padding: 6px 14px; border: none; background: var(--theme-button-color, #35a0a3); color: white; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500;"><?php echo e(t('vendor-onboarding.btn_send_reply')); ?></button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>


                </div>

                <?php if ($canAddNotes): ?>
                <!-- Create Case Modal -->
                <div id="caseNoteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 30px; border-radius: 8px; max-width: 550px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto;">
                        <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;"><?php echo e(t('vendor-onboarding.modal_create_case')); ?></h3>
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=notes" id="caseNoteForm">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="add_note" value="1">
                            <input type="hidden" name="note_assigned_to" id="caseNoteAssignedTo" value="">

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.field_title')); ?> <span style="color: #dc3545;">*</span></label>
                                <input type="text" name="note_title" id="caseNoteTitle" required placeholder="<?php echo e(t('vendor-onboarding.placeholder_case_title')); ?>" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_description')); ?></label>
                                <textarea name="note_description" id="caseNoteDescription" rows="3" placeholder="<?php echo e(t('vendor-onboarding.placeholder_add_details')); ?>" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; box-sizing: border-box;"></textarea>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_due_date')); ?></label>
                                <input type="date" name="note_due_date" id="caseNoteDueDate" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_assign_to')); ?></label>
                                <div class="autocomplete-wrapper" style="position: relative;">
                                    <input type="text" id="caseNoteAssigneeInput" placeholder="<?php echo e(t('vendor-onboarding.placeholder_search_name_username')); ?>" autocomplete="off" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                    <div id="caseNoteAssigneeSuggestions" class="autocomplete-suggestions" style="display: none;"></div>
                                </div>
                                <div style="font-size: 11px; color: #999; margin-top: 4px;"><?php echo e(t('vendor-onboarding.assign_search_hint')); ?></div>
                            </div>

                            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                <button type="button" id="caseNoteCancelBtn" style="padding: 10px 20px; border: 1px solid #d1d5db; background: white; color: #666; border-radius: 6px; cursor: pointer; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                                <button type="submit" style="padding: 10px 20px; border: none; background: var(--theme-button-color, #35a0a3); color: white; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;"><?php echo e(t('vendor-onboarding.modal_create_case')); ?></button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Case Reassignment Modal -->
                <div id="caseReassignModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                        <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;"><?php echo e(t('vendor-onboarding.modal_reassign_case')); ?></h3>
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=notes" id="caseReassignForm">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="reassign_case" value="1">
                            <input type="hidden" name="reassign_case_id" id="caseReassignCaseId" value="">
                            <input type="hidden" name="reassign_case_user_id" id="caseReassignUserId" value="">

                            <div style="margin-bottom: 20px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;"><?php echo e(t('vendor-onboarding.label_search_user')); ?></label>
                                <div class="autocomplete-wrapper" style="position: relative;">
                                    <input type="text" id="caseReassignSearch" placeholder="<?php echo e(t('vendor-onboarding.placeholder_type_name_username')); ?>" autocomplete="off" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                                    <div id="caseReassignSuggestions" class="autocomplete-suggestions" style="display: none;"></div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                <button type="button" id="caseReassignCancelBtn" style="padding: 10px 20px; border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 14px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                                <button type="submit" id="caseReassignSubmitBtn" style="padding: 10px 20px; border: none; background: #6b7280; color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; opacity: 0.5;" disabled><?php echo e(t('vendor-onboarding.btn_reassign')); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab: Action Plan (Vendor Remediation Schedule) -->
                <?php if ($canManageScheduledActions): ?>
                <?php
                    $saActionLabels = [
                        'contact_vendor'      => t('vendor-onboarding.sa_action_contact_vendor'),
                        'contact_stakeholder' => t('vendor-onboarding.sa_action_contact_stakeholder'),
                        'send_assessment'     => t('vendor-onboarding.sa_action_send_assessment'),
                        'force_annual_review' => t('vendor-onboarding.sa_action_force_annual_review'),
                    ];
                    $saStatusLabels = [
                        'pending'     => t('vendor-onboarding.sa_status_pending'),
                        'in_progress' => t('vendor-onboarding.sa_status_in_progress'),
                        'problem'     => t('vendor-onboarding.sa_status_problem'),
                        'completed'   => t('vendor-onboarding.sa_status_completed'),
                        'cancelled'   => t('vendor-onboarding.sa_status_cancelled'),
                    ];
                    $saStatusColors = [
                        'pending'     => '#2563eb',
                        'in_progress' => '#d97706',
                        'problem'     => '#dc2626',
                        'completed'   => '#16a34a',
                        'cancelled'   => '#6b7280',
                    ];
                    // id => display name / email lookup for assignee chips + prefill
                    $saAssigneeNames = [];
                    $saAssigneeEmails = [];
                    foreach ($scheduledActionAssignees as $u) {
                        $saAssigneeNames[(int)$u['id']] = $u['full_name'] ?: $u['username'];
                        if (!empty($u['email'])) { $saAssigneeEmails[(int)$u['id']] = $u['email']; }
                    }
                ?>
                <div id="vendorTabContent_action_plan" style="<?php echo $activeTab !== 'action_plan' ? 'display: none;' : ''; ?>">
                    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 25px;">
                        <?php if ($selectedAction):
                            $selStatus = $selectedAction['status'];
                            $selFired = !empty($selectedAction['executed_at']);
                            $selAssigneeIds = !empty($selectedAction['assignees']) ? array_map('intval', (json_decode($selectedAction['assignees'], true) ?: [])) : [];
                            $selActionLabel = $saActionLabels[$selectedAction['action']] ?? $selectedAction['action'];
                            $selColor = $saStatusColors[$selectedAction['status']] ?? '#6b7280';
                        ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;"><?php echo e($selActionLabel); ?></h3>
                            <a href="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=action_plan" style="font-size: 13px; color: var(--theme-header-color); text-decoration: none;"><?php echo t('vendor-onboarding.back_to_action_plan'); ?></a>
                        </div>

                        <div style="display: flex; flex-wrap: wrap; gap: 18px; margin-bottom: 20px; font-size: 13px; color: #374151;">
                            <div><span style="color:#6b7280;"><?php echo e(t('vendor-onboarding.label_due_date_colon')); ?></span> <strong><?php echo e(date('M j, Y', strtotime($selectedAction['scheduled_date']))); ?></strong></div>
                            <div><span style="color:#6b7280;"><?php echo e(t('vendor-onboarding.label_status_colon')); ?></span> <span style="display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; color: white; background: <?php echo $selColor; ?>;"><?php echo e($saStatusLabels[$selStatus] ?? ucfirst($selStatus)); ?></span></div>
                            <?php if ($selectedAction['action'] === 'send_assessment' && !empty($selectedAction['assessment_name'])): ?>
                            <div><span style="color:#6b7280;"><?php echo e(t('vendor-onboarding.label_assessment_colon')); ?></span> <strong><?php echo e($selectedAction['assessment_name']); ?></strong></div>
                            <?php endif; ?>
                            <?php if ($selFired): ?>
                            <div style="color:#16a34a;"><?php echo e(t('vendor-onboarding.fired_prefix')); ?> <?php echo e(formatLocalTime($selectedAction['executed_at'])); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($selStatus === 'problem' && !empty($selectedAction['error'])): ?>
                        <div style="background:#fef2f2; border:1px solid #fecaca; color:#dc2626; padding:10px 12px; border-radius:6px; font-size:13px; margin-bottom:18px;"><?php echo e(t('vendor-onboarding.error_prefix')); ?> <?php echo e($selectedAction['error']); ?></div>
                        <?php endif; ?>

                        <!-- Edit / status form -->
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=action_plan&action_id=<?php echo (int)$selectedAction['id']; ?>" style="border:1px solid #e5e7eb; border-radius:8px; padding:18px; margin-bottom:22px;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="edit_scheduled_action" value="1">
                            <input type="hidden" name="scheduled_action_id" value="<?php echo (int)$selectedAction['id']; ?>">
                            <div style="font-weight:600; font-size:14px; color:#333; margin-bottom:14px;"><?php echo $selFired ? t('vendor-onboarding.btn_update_status') : t('vendor-onboarding.btn_edit_action'); ?></div>

                            <?php if (!$selFired): ?>
                            <div style="margin-bottom: 14px;">
                                <label style="display:block; font-size:13px; font-weight:500; color:#333; margin-bottom:6px;"><?php echo e(t('vendor-onboarding.label_action')); ?> <span style="color:#dc2626;">*</span></label>
                                <select name="sa_action" id="saEditActionSelect" required style="width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:6px; font-size:14px; background:white;">
                                    <?php foreach ($saActionLabels as $akey => $alabel): ?>
                                    <option value="<?php echo e($akey); ?>" <?php echo $selectedAction['action'] === $akey ? 'selected' : ''; ?>><?php echo e($alabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="margin-bottom: 14px; <?php echo $selectedAction['action'] === 'send_assessment' ? '' : 'display:none;'; ?>" id="saEditAssessmentGroup">
                                <label style="display:block; font-size:13px; font-weight:500; color:#333; margin-bottom:6px;"><?php echo e(t('vendor-onboarding.label_vendor_assessment')); ?> <span style="color:#dc2626;">*</span></label>
                                <select name="sa_assessment_template_id" id="saEditAssessmentSelect" style="width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:6px; font-size:14px; background:white;">
                                    <option value=""><?php echo e(t('vendor-onboarding.opt_select_assessment')); ?></option>
                                    <?php foreach ($scheduledActionAssessments as $tpl): ?>
                                    <option value="<?php echo (int)$tpl['id']; ?>" <?php echo (int)$selectedAction['assessment_template_id'] === (int)$tpl['id'] ? 'selected' : ''; ?>><?php echo e($tpl['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="margin-bottom: 14px;">
                                <label style="display:block; font-size:13px; font-weight:500; color:#333; margin-bottom:6px;"><?php echo e(t('vendor-onboarding.label_due_date')); ?> <span style="color:#dc2626;">*</span></label>
                                <input type="date" name="sa_date" required value="<?php echo e($selectedAction['scheduled_date']); ?>" style="width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:6px; font-size:14px;">
                            </div>
                            <div style="margin-bottom: 14px;">
                                <label style="display:block; font-size:13px; font-weight:500; color:#333; margin-bottom:6px;"><?php echo e(t('vendor-onboarding.label_assign_cyber')); ?></label>
                                <?php if (empty($scheduledActionAssignees)): ?>
                                    <div style="font-size:12px; color:#9ca3af;"><?php echo e(t('vendor-onboarding.no_cyber_users')); ?></div>
                                <?php else: ?>
                                    <div style="max-height:140px; overflow-y:auto; border:1px solid #ddd; border-radius:6px; padding:8px 10px;">
                                        <?php foreach ($scheduledActionAssignees as $u): ?>
                                        <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#333; padding:3px 0; cursor:pointer;">
                                            <input type="checkbox" name="sa_assignees[]" value="<?php echo (int)$u['id']; ?>" <?php echo in_array((int)$u['id'], $selAssigneeIds, true) ? 'checked' : ''; ?>>
                                            <?php echo e($u['full_name'] ?: $u['username']); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#333; margin-top:8px; cursor:pointer;">
                                        <input type="checkbox" name="sa_notify" value="1" <?php echo !empty($selectedAction['notify_assignees']) ? 'checked' : ''; ?>> <?php echo e(t('vendor-onboarding.email_on_fire_label')); ?>
                                    </label>
                                <?php endif; ?>
                            </div>
                            <?php
                                $selNotifyEmails = trim((string)($selectedAction['notify_emails'] ?? ''));
                                if ($selNotifyEmails === '' && !empty($selAssigneeIds)) {
                                    $tmpEmails = [];
                                    foreach ($selAssigneeIds as $aid) { if (!empty($saAssigneeEmails[$aid])) { $tmpEmails[] = $saAssigneeEmails[$aid]; } }
                                    $selNotifyEmails = implode(', ', $tmpEmails);
                                }
                            ?>
                            <div style="margin-bottom: 14px;">
                                <label style="display:block; font-size:13px; font-weight:500; color:#333; margin-bottom:6px;"><?php echo e(t('vendor-onboarding.label_notify_emails')); ?></label>
                                <input type="text" name="sa_notify_emails" value="<?php echo e($selNotifyEmails); ?>" placeholder="<?php echo e(t('vendor-onboarding.placeholder_notify_emails')); ?>" style="width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:6px; font-size:14px;">
                                <div style="font-size:11px; color:#9ca3af; margin-top:4px;"><?php echo e(t('vendor-onboarding.notify_emails_hint')); ?></div>
                            </div>
                            <div style="margin-bottom: 14px;">
                                <label style="display:block; font-size:13px; font-weight:500; color:#333; margin-bottom:6px;"><?php echo e(t('vendor-onboarding.label_description')); ?> <span style="color:#dc2626;">*</span></label>
                                <textarea name="sa_description" required rows="3" style="width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:6px; font-size:14px; resize:vertical;"><?php echo e($selectedAction['description'] ?? ''); ?></textarea>
                            </div>
                            <?php else: ?>
                            <p style="font-size:12px; color:#6b7280; margin:0 0 14px 0;"><?php echo e(t('vendor-onboarding.action_fired_note')); ?></p>
                            <?php endif; ?>

                            <div style="margin-bottom: 16px;">
                                <label style="display:block; font-size:13px; font-weight:500; color:#333; margin-bottom:6px;"><?php echo e(t('vendor-onboarding.label_status')); ?></label>
                                <select name="sa_status" style="width:100%; max-width:260px; padding:10px 12px; border:1px solid #ddd; border-radius:6px; font-size:14px; background:white;">
                                    <?php foreach ($saStatusLabels as $skey => $slabel): ?>
                                    <option value="<?php echo e($skey); ?>" <?php echo $selStatus === $skey ? 'selected' : ''; ?>><?php echo e($slabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" style="padding:9px 18px; background:var(--theme-button-color); color:white; border:none; border-radius:6px; font-size:14px; font-weight:500; cursor:pointer;"><?php echo e(t('vendor-onboarding.btn_save_changes')); ?></button>
                        </form>

                        <!-- Status notes thread -->
                        <div style="font-weight:600; font-size:14px; color:#333; margin-bottom:10px;"><?php echo e(t('vendor-onboarding.status_notes_heading')); ?></div>
                        <?php if (empty($selectedActionNotes)): ?>
                            <div style="color:#9ca3af; font-size:13px; margin-bottom:14px;"><?php echo e(t('vendor-onboarding.no_status_notes')); ?></div>
                        <?php else: ?>
                            <div style="margin-bottom:14px;">
                                <?php foreach ($selectedActionNotes as $n):
                                    $canManageNote = $isAdmin || (int)$n['created_by'] === (int)$user['id'];
                                ?>
                                <div style="border-left:3px solid #e5e7eb; padding:6px 12px; margin-bottom:10px;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                                        <div style="font-size:12px; color:#6b7280; margin-bottom:3px;"><?php echo e($n['created_by_name'] ?? 'User'); ?> &middot; <?php echo e(formatLocalTime($n['created_at'])); ?></div>
                                        <?php if ($canManageNote): ?>
                                        <div style="display:flex; gap:10px; white-space:nowrap;">
                                            <button type="button" data-toggle="editSaNote_<?php echo (int)$n['id']; ?>" style="font-size:11px; color:#6b7280; background:none; border:none; cursor:pointer; padding:0;"><?php echo e(t('vendor-onboarding.btn_edit')); ?></button>
                                            <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=action_plan&action_id=<?php echo (int)$selectedAction['id']; ?>" style="display:inline; margin:0;" onsubmit="return confirm('Delete this status note?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="delete_scheduled_action_note" value="1">
                                                <input type="hidden" name="scheduled_action_id" value="<?php echo (int)$selectedAction['id']; ?>">
                                                <input type="hidden" name="note_id" value="<?php echo (int)$n['id']; ?>">
                                                <button type="submit" style="font-size:11px; color:#dc2626; background:none; border:none; cursor:pointer; padding:0;"><?php echo e(t('vendor-onboarding.btn_delete')); ?></button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:13px; color:#374151; word-break:break-word;"><?php echo nl2br(e($n['description'] ?? '')); ?></div>
                                    <?php if ($canManageNote): ?>
                                    <div id="editSaNote_<?php echo (int)$n['id']; ?>" style="display:none; margin-top:8px;">
                                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=action_plan&action_id=<?php echo (int)$selectedAction['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="edit_scheduled_action_note" value="1">
                                            <input type="hidden" name="scheduled_action_id" value="<?php echo (int)$selectedAction['id']; ?>">
                                            <input type="hidden" name="note_id" value="<?php echo (int)$n['id']; ?>">
                                            <textarea name="sa_note" required rows="2" style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; resize:vertical; box-sizing:border-box; margin-bottom:6px;"><?php echo e($n['description'] ?? ''); ?></textarea>
                                            <div style="display:flex; gap:8px; justify-content:flex-end;">
                                                <button type="button" data-close="editSaNote_<?php echo (int)$n['id']; ?>" style="padding:5px 12px; border:1px solid #d1d5db; background:#fff; color:#666; border-radius:6px; cursor:pointer; font-size:12px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                                                <button type="submit" style="padding:5px 12px; border:none; background:var(--theme-button-color); color:white; border-radius:6px; cursor:pointer; font-size:12px; font-weight:500;"><?php echo e(t('vendor-onboarding.btn_save')); ?></button>
                                            </div>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=action_plan&action_id=<?php echo (int)$selectedAction['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="add_scheduled_action_note" value="1">
                            <input type="hidden" name="scheduled_action_id" value="<?php echo (int)$selectedAction['id']; ?>">
                            <textarea name="sa_note" required rows="2" placeholder="<?php echo e(t('vendor-onboarding.placeholder_add_status_note')); ?>" style="width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:6px; font-size:14px; resize:vertical; margin-bottom:8px;"></textarea>
                            <button type="submit" style="padding:8px 16px; background:#374151; color:white; border:none; border-radius:6px; font-size:13px; cursor:pointer;"><?php echo e(t('vendor-onboarding.btn_add_note')); ?></button>
                        </form>

                        <script nonce="<?php echo cspNonce(); ?>">
                        (function() {
                            var sel = document.getElementById('saEditActionSelect');
                            var grp = document.getElementById('saEditAssessmentGroup');
                            var asel = document.getElementById('saEditAssessmentSelect');
                            if (!sel || !grp) return;
                            sel.addEventListener('change', function() {
                                var isSend = sel.value === 'send_assessment';
                                grp.style.display = isSend ? 'block' : 'none';
                                if (asel) asel.required = isSend;
                            });
                        })();
                        </script>
                        <?php else: ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;"><?php echo e(t('vendor-onboarding.tab_action_plan')); ?></h3>
                            <button type="button" id="createScheduledActionBtn" class="btn btn-primary" style="padding: 8px 18px; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_create_action')); ?></button>
                        </div>
                        <p style="margin: 0 0 20px 0; font-size: 13px; color: #6b7280;"><?php echo t('vendor-onboarding.action_plan_intro_html'); ?></p>

                        <?php if (empty($scheduledActions)): ?>
                            <div style="text-align: center; padding: 40px 20px; color: #9ca3af; font-size: 14px;"><?php echo t('vendor-onboarding.no_scheduled_actions_html'); ?></div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                <thead>
                                    <tr style="text-align: left; color: #6b7280; border-bottom: 1px solid #e5e7eb;">
                                        <th style="padding: 8px 10px;"><?php echo e(t('vendor-onboarding.label_due_date')); ?></th>
                                        <th style="padding: 8px 10px;"><?php echo e(t('vendor-onboarding.label_action')); ?></th>
                                        <th style="padding: 8px 10px;"><?php echo e(t('vendor-onboarding.label_description')); ?></th>
                                        <th style="padding: 8px 10px;"><?php echo e(t('vendor-onboarding.th_assignees')); ?></th>
                                        <th style="padding: 8px 10px;"><?php echo e(t('vendor-onboarding.label_status')); ?></th>
                                        <th style="padding: 8px 10px; text-align: right;"><?php echo e(t('vendor-onboarding.th_manage')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($scheduledActions as $sa):
                                    $saLabel = $saActionLabels[$sa['action']] ?? $sa['action'];
                                    $saColor = $saStatusColors[$sa['status']] ?? '#6b7280';
                                ?>
                                    <?php
                                        $saDetailUrl = 'vendor-onboarding.php?id=' . $requestId . '&tab=action_plan&action_id=' . (int)$sa['id'];
                                        $saRowNotes = $scheduledActionNotesByAction[(int)$sa['id']] ?? [];
                                        $saToggle = 'saRowNotes_' . (int)$sa['id'];
                                        $saAssigneeIds = !empty($sa['assignees']) ? (json_decode($sa['assignees'], true) ?: []) : [];
                                        $saNames = [];
                                        foreach ($saAssigneeIds as $aid) { if (isset($saAssigneeNames[(int)$aid])) { $saNames[] = $saAssigneeNames[(int)$aid]; } }
                                    ?>
                                    <tr style="border-bottom: 1px solid #f3f4f6; vertical-align: top;">
                                        <td data-toggle="<?php echo $saToggle; ?>" style="padding: 10px; cursor: pointer;"><?php echo e(date('M j, Y', strtotime($sa['scheduled_date']))); ?></td>
                                        <td data-toggle="<?php echo $saToggle; ?>" style="padding: 10px; cursor: pointer;">
                                            <span style="color: var(--theme-header-color); font-weight: 500;"><?php echo e($saLabel); ?></span>
                                            <?php if (!empty($saRowNotes)): ?><span style="font-size:11px; color:#6b7280;"> (<?php echo count($saRowNotes); ?> note<?php echo count($saRowNotes) === 1 ? '' : 's'; ?>)</span><?php endif; ?>
                                            <?php if ($sa['action'] === 'send_assessment' && !empty($sa['assessment_name'])): ?>
                                                <div style="color: #6b7280; font-size: 12px;"><?php echo e($sa['assessment_name']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-toggle="<?php echo $saToggle; ?>" style="padding: 10px; max-width: 320px; word-break: break-word; color: #374151; cursor: pointer;"><?php echo nl2br(e($sa['description'] ?? '')); ?>
                                            <?php if ($sa['status'] === 'problem' && !empty($sa['error'])): ?>
                                                <div style="color: #dc2626; font-size: 12px; margin-top: 4px;"><?php echo e(t('vendor-onboarding.error_prefix')); ?> <?php echo e($sa['error']); ?></div>
                                            <?php elseif (!empty($sa['executed_at'])): ?>
                                                <div style="color: #16a34a; font-size: 12px; margin-top: 4px;"><?php echo e(t('vendor-onboarding.fired_prefix')); ?> <?php echo e(formatLocalTime($sa['executed_at'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-toggle="<?php echo $saToggle; ?>" style="padding: 10px; color: #374151; cursor: pointer;">
                                            <?php if (!empty($saNames)): ?>
                                                <?php echo e(implode(', ', $saNames)); ?>
                                                <?php if (!empty($sa['notify_assignees'])): ?>
                                                    <div style="color: #2563eb; font-size: 11px; margin-top: 2px;"><?php echo t('vendor-onboarding.email_on_fire_badge'); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #9ca3af; font-size: 12px;"><?php echo e(t('vendor-onboarding.label_stakeholder')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-toggle="<?php echo $saToggle; ?>" style="padding: 10px; cursor: pointer;"><span style="display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; color: white; background: <?php echo $saColor; ?>;"><?php echo e($saStatusLabels[$sa['status']] ?? ucfirst($sa['status'])); ?></span></td>
                                        <td style="padding: 10px; text-align: right; white-space: nowrap;">
                                            <a href="<?php echo e($saDetailUrl); ?>" style="padding: 4px 10px; font-size: 12px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 4px; cursor: pointer; text-decoration: none;"><?php echo e(t('vendor-onboarding.btn_edit')); ?></a>
                                            <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=action_plan" style="display: inline;" onsubmit="return confirm('Delete this scheduled action?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="delete_scheduled_action" value="1">
                                                <input type="hidden" name="scheduled_action_id" value="<?php echo (int)$sa['id']; ?>">
                                                <button type="submit" style="padding: 4px 10px; font-size: 12px; border: 1px solid #fecaca; background: white; color: #dc2626; border-radius: 4px; cursor: pointer;"><?php echo e(t('vendor-onboarding.btn_delete')); ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <tr id="<?php echo $saToggle; ?>" style="display: none; background: #f9fafb;">
                                        <td colspan="6" style="padding: 12px 16px;">
                                            <div style="font-size: 12px; font-weight: 600; color: #666; margin-bottom: 8px;"><?php echo e(t('vendor-onboarding.status_notes_heading')); ?></div>
                                            <?php if (empty($saRowNotes)): ?>
                                                <div style="font-size: 13px; color: #9ca3af; margin-bottom: 8px;"><?php echo e(t('vendor-onboarding.no_status_notes')); ?></div>
                                            <?php else: ?>
                                                <?php foreach ($saRowNotes as $rn): ?>
                                                <div style="border-left: 3px solid #e5e7eb; padding: 4px 10px; margin-bottom: 8px;">
                                                    <div style="font-size: 11px; color: #6b7280;"><?php echo e($rn['created_by_name'] ?? 'User'); ?> &middot; <?php echo e(formatLocalTime($rn['created_at'])); ?></div>
                                                    <div style="font-size: 13px; color: #374151; word-break: break-word;"><?php echo nl2br(e($rn['description'] ?? '')); ?></div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <a href="<?php echo e($saDetailUrl); ?>" style="font-size: 12px; color: var(--theme-header-color); text-decoration: none;"><?php echo t('vendor-onboarding.edit_manage_notes_link'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php endif; ?>
                        <?php endif; /* end selectedAction detail vs list */ ?>
                    </div>

                    <!-- Create Scheduled Action Modal -->
                    <div id="scheduledActionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 560px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto;">
                            <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;"><?php echo e(t('vendor-onboarding.modal_create_scheduled_action')); ?></h3>
                            <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=action_plan" id="scheduledActionForm">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="create_scheduled_action" value="1">

                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-size: 13px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_action')); ?> <span style="color: #dc2626;">*</span></label>
                                    <select name="sa_action" id="saActionSelect" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; background: white;">
                                        <option value=""><?php echo e(t('vendor-onboarding.opt_select_action')); ?></option>
                                        <option value="contact_vendor"><?php echo e(t('vendor-onboarding.sa_action_contact_vendor')); ?></option>
                                        <option value="contact_stakeholder"><?php echo e(t('vendor-onboarding.sa_action_contact_stakeholder')); ?></option>
                                        <option value="send_assessment"><?php echo e(t('vendor-onboarding.sa_action_send_assessment')); ?></option>
                                        <option value="force_annual_review"><?php echo e(t('vendor-onboarding.sa_action_force_annual_review')); ?></option>
                                    </select>
                                </div>

                                <div style="margin-bottom: 16px; display: none;" id="saAssessmentGroup">
                                    <label style="display: block; font-size: 13px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_vendor_assessment')); ?> <span style="color: #dc2626;">*</span></label>
                                    <select name="sa_assessment_template_id" id="saAssessmentSelect" style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; background: white;">
                                        <option value=""><?php echo e(t('vendor-onboarding.opt_select_assessment')); ?></option>
                                        <?php foreach ($scheduledActionAssessments as $tpl): ?>
                                        <option value="<?php echo (int)$tpl['id']; ?>"><?php echo e($tpl['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-size: 13px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_due_date')); ?> <span style="color: #dc2626;">*</span></label>
                                    <input type="date" name="sa_date" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                                </div>

                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-size: 13px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_assign_cyber')); ?></label>
                                    <?php if (empty($scheduledActionAssignees)): ?>
                                        <div style="font-size: 12px; color: #9ca3af;"><?php echo e(t('vendor-onboarding.no_cyber_users_fallback')); ?></div>
                                    <?php else: ?>
                                        <div style="max-height: 140px; overflow-y: auto; border: 1px solid #ddd; border-radius: 6px; padding: 8px 10px;">
                                            <?php foreach ($scheduledActionAssignees as $u): ?>
                                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #333; padding: 3px 0; cursor: pointer;">
                                                <input type="checkbox" name="sa_assignees[]" value="<?php echo (int)$u['id']; ?>">
                                                <?php echo e($u['full_name'] ?: $u['username']); ?>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #333; margin-top: 8px; cursor: pointer;">
                                            <input type="checkbox" name="sa_notify" value="1"> <?php echo e(t('vendor-onboarding.email_on_fire_label')); ?>
                                        </label>
                                    <?php endif; ?>
                                </div>

                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-size: 13px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_notify_emails')); ?></label>
                                    <input type="text" name="sa_notify_emails" placeholder="<?php echo e(t('vendor-onboarding.placeholder_notify_emails')); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;"><?php echo e(t('vendor-onboarding.notify_emails_hint')); ?></div>
                                </div>

                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; font-size: 13px; font-weight: 500; color: #333; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_description')); ?> <span style="color: #dc2626;">*</span></label>
                                    <textarea name="sa_description" required rows="4" style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; resize: vertical;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_describe_action')); ?>"></textarea>
                                </div>

                                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                    <button type="button" id="scheduledActionCancelBtn" style="padding: 10px 20px; background: #6b7280; color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                                    <button type="submit" style="padding: 10px 20px; background: var(--theme-button-color); color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer;"><?php echo e(t('vendor-onboarding.btn_create_action_submit')); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <script nonce="<?php echo cspNonce(); ?>">
                (function() {
                    var modal = document.getElementById('scheduledActionModal');
                    var openBtn = document.getElementById('createScheduledActionBtn');
                    var cancelBtn = document.getElementById('scheduledActionCancelBtn');
                    var actionSelect = document.getElementById('saActionSelect');
                    var assessmentGroup = document.getElementById('saAssessmentGroup');
                    var assessmentSelect = document.getElementById('saAssessmentSelect');
                    if (!modal || !openBtn) return;

                    function toggleAssessment() {
                        var isSend = actionSelect.value === 'send_assessment';
                        assessmentGroup.style.display = isSend ? 'block' : 'none';
                        assessmentSelect.required = isSend;
                        if (!isSend) assessmentSelect.value = '';
                    }
                    function openModal() { modal.style.display = 'flex'; }
                    function closeModal() { modal.style.display = 'none'; }

                    openBtn.addEventListener('click', openModal);
                    cancelBtn.addEventListener('click', closeModal);
                    actionSelect.addEventListener('change', toggleAssessment);
                    modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
                    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });
                    toggleAssessment();
                })();
                </script>
                <?php endif; ?>

                <!-- Tab: Assessments -->
                <?php if (!$isProcurementOnly): ?>
                <div id="vendorTabContent_assessments" style="<?php echo $activeTab !== 'assessments' ? 'display: none;' : ''; ?>">
                    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 25px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;"><?php echo e(t('vendor-onboarding.assessments_heading')); ?></h3>
                            <a href="vendor-assessments.php?link_vendor=<?php echo $requestId; ?>" class="btn btn-primary" style="padding: 8px 18px; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_new_assessment')); ?></a>
                        </div>

                        <?php if (!empty($linkedAssessments)): ?>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                                <?php foreach ($linkedAssessments as $assessment):
                                    $isExpired = $assessment['expires_at'] && strtotime($assessment['expires_at']) < time() && $assessment['status'] !== 'completed';
                                    $displayStatus = $isExpired ? 'expired' : $assessment['status'];
                                    $statusColors = [
                                        'pending' => 'background: #fef3c7; color: #92400e',
                                        'in_progress' => 'background: #dbeafe; color: #1e40af',
                                        'completed' => 'background: #dcfce7; color: #166534',
                                        'expired' => 'background: #fef2f2; color: #991b1b'
                                    ];
                                    $statusStyle = $statusColors[$displayStatus] ?? 'background: #f3f4f6; color: #333';
                                    $assessId = (int)$assessment['id'];
                                ?>
                                <div style="border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
                                    <div class="assessment-collapse-header" data-target="assessmentBody_<?php echo $assessId; ?>" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f8f9fa; cursor: pointer; user-select: none;">
                                        <div style="display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0;">
                                            <span class="assessment-chevron" style="font-size: 11px; color: #999; transition: transform 0.2s;">&#9654;</span>
                                            <span style="font-weight: 500; color: #333; font-size: 13px;">
                                                <?php echo e($assessment['template_name']); ?>
                                                <?php if (!empty($assessment['triggered_by_rule_id']) || !empty($assessment['triggered_by_assessment_id'])): ?>
                                                <span style="font-size:10px; color:#92400e; background:#fef3c7; padding:2px 6px; border-radius:4px; margin-left:5px;"><?php echo e(t('vendor-onboarding.badge_workflow')); ?></span>
                                                <?php endif; ?>
                                            </span>
                                            <span style="padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 500; <?php echo $statusStyle; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $displayStatus)); ?>
                                            </span>
                                            <?php if ($assessment['certificate_uploaded']): ?>
                                            <small style="color: #22c55e; font-size: 11px;"><?php echo e(t('vendor-onboarding.certificate_uploaded')); ?></small>
                                            <?php endif; ?>
                                            <span style="color: #666; font-size: 12px;"><?php echo e(t('vendor-onboarding.created_prefix')); ?> <?php echo date('M j, Y', strtotime($assessment['created_at'])); ?></span>
                                            <?php if ($assessment['expires_at']): ?>
                                            <span style="color: #666; font-size: 12px;"><?php echo e(t('vendor-onboarding.expires_prefix')); ?> <?php echo date('M j, Y', strtotime($assessment['expires_at'])); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="display: flex; gap: 10px; align-items: center;" onclick="event.stopPropagation();">
                                            <a href="vendor-assessment-view.php?id=<?php echo $assessId; ?>" style="color: var(--theme-header-color); text-decoration: none; font-weight: 500; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_view')); ?></a>
                                            <?php if (!empty($assessment['uuid'])): ?>
                                            <a href="vendor-assessment.php?token=<?php echo urlencode($assessment['uuid']); ?>&edit=1" style="color: #f59e0b; text-decoration: none; font-weight: 500; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_edit')); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div id="assessmentBody_<?php echo $assessId; ?>" style="display: none;">
                                        <div style="padding: 16px; border-top: 1px solid #e5e7eb;">
                                            <div style="display: flex; gap: 24px; font-size: 13px; color: #555; margin-bottom: 8px;">
                                                <span><strong><?php echo e(t('vendor-onboarding.label_status_colon')); ?></strong> <?php echo ucfirst(str_replace('_', ' ', $displayStatus)); ?></span>
                                                <span><strong><?php echo e(t('vendor-onboarding.label_created_colon')); ?></strong> <?php echo date('M j, Y', strtotime($assessment['created_at'])); ?></span>
                                                <span><strong><?php echo e(t('vendor-onboarding.label_expires_colon')); ?></strong> <?php echo $assessment['expires_at'] ? date('M j, Y', strtotime($assessment['expires_at'])) : '-'; ?></span>
                                            </div>
                                            <?php if (!empty($assessmentExtraData[$assessment['id']])): ?>
                                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f0f0f0;">
                                                <strong style="font-size: 12px; color: #666;"><?php echo e(t('vendor-onboarding.additional_responses_heading')); ?></strong>
                                                <?php foreach ($assessmentExtraData[$assessment['id']] as $section): ?>
                                                <div style="margin-top: 10px;">
                                                    <div style="font-weight: 600; font-size: 12px; color: #444; margin-bottom: 6px;"><?php echo e($section['section_name']); ?></div>
                                                    <?php foreach ($section['items'] as $item): ?>
                                                    <div style="display: flex; gap: 8px; padding: 4px 0; font-size: 13px;">
                                                        <span style="color: #666; min-width: 200px;"><?php echo e($item['question']); ?>:</span>
                                                        <span style="color: #333; font-weight: 500;"><?php echo nl2br(e($item['value'])); ?></span>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php else: ?>
                                            <p style="color: #999; font-size: 13px; margin: 8px 0 0;"><?php echo e(t('vendor-onboarding.no_additional_data')); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                            <p style="color: #999; text-align: center; padding: 30px 0;"><?php echo e(t('vendor-onboarding.no_assessments_linked')); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab: FAIR Assessment -->
                <?php if (!$isProcurementOnly): ?>
                <div id="vendorTabContent_fair" style="<?php echo $activeTab !== 'fair' ? 'display: none;' : ''; ?>">
                    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 25px;">
                        <h3 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 600; color: #333;"><?php echo e(t('vendor-onboarding.fair_analyses_heading')); ?></h3>
                        <?php if (empty($vendorFairAnalyses)): ?>
                            <p style="color: #999; text-align: center; padding: 30px 0;"><?php echo e(t('vendor-onboarding.no_fair_analyses')); ?></p>
                        <?php else: ?>
                            <?php
                            // Compute security score for display
                            $hasUpguardScore = !empty($request['current_srs_score']);
                            $hasShodanScore = !empty($request['current_shodan_score']);
                            $fairSecurityScore = null;
                            $fairSecurityLabel = '';
                            if ($hasUpguardScore && $hasShodanScore) {
                                $ugMax = $upguardConfig['max_score'] ?: 950;
                                $shMax = $shodanConfig['max_score'] ?: 100;
                                $avgPct = (int)floor(((intval($request['current_srs_score']) / $ugMax) * 100 + (intval($request['current_shodan_score']) / $shMax) * 100) / 2);
                                $fairSecurityScore = $avgPct . '%';
                                $fairSecurityLabel = t('vendor-onboarding.label_average_srs');
                            } elseif ($hasUpguardScore) {
                                $fairSecurityScore = intval($request['current_srs_score']);
                                $fairSecurityLabel = $upguardDisplayName;
                            } elseif ($hasShodanScore) {
                                $fairSecurityScore = intval($request['current_shodan_score']);
                                $fairSecurityLabel = $shodanDisplayName;
                            }
                            ?>
                            <?php foreach ($vendorFairAnalyses as $fair):
                                $fairStatusStyle = $fair['status'] === 'completed' ? 'background: #dcfce7; color: #166534' : 'background: #fef3c7; color: #92400e';
                                // Risk level color coding
                                $riskLevel = $fair['risk_output'] ?? '';
                                $riskColors = [
                                    'Very Low'  => ['bg' => '#f0fdf4', 'color' => '#166534'],
                                    'Low'       => ['bg' => '#ecfdf5', 'color' => '#065f46'],
                                    'Medium'    => ['bg' => '#fefce8', 'color' => '#854d0e'],
                                    'High'      => ['bg' => '#fff7ed', 'color' => '#9a3412'],
                                    'Very High' => ['bg' => '#fef2f2', 'color' => '#991b1b'],
                                    'Critical'  => ['bg' => '#450a0a', 'color' => '#fecaca'],
                                ];
                                $rc = $riskColors[$riskLevel] ?? ['bg' => '#f3f4f6', 'color' => '#374151'];
                            ?>
                            <div style="border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 16px; overflow: hidden;">
                                <!-- Header row -->
                                <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <span style="font-weight: 600; color: #333; font-size: 15px;"><?php echo e($fair['vendor_name'] ?: $fair['vendor_domain']); ?></span>
                                        <span style="padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; <?php echo $fairStatusStyle; ?>"><?php echo ucfirst($fair['status']); ?></span>
                                        <?php if (!empty($riskLevel)): ?>
                                        <span style="padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; background: <?php echo $rc['bg']; ?>; color: <?php echo $rc['color']; ?>;">
                                            <?php echo e($riskLevel); ?> <?php echo e(t('vendor-onboarding.risk_suffix')); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <span style="font-size: 12px; color: #999;"><?php echo e(t('vendor-onboarding.created_prefix')); ?> <?php echo date('M j, Y', strtotime($fair['created_at'])); ?></span>
                                        <?php if (!$isProcurement || $isAdmin || $isCyberTPRM): ?>
                                        <a href="view-result.php?id=<?php echo $fair['id']; ?>" style="padding: 4px 12px; border: 1px solid var(--theme-header-color); color: var(--theme-header-color); border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500; transition: all 0.2s;"><?php echo e(t('vendor-onboarding.btn_view_full_report')); ?></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($fair['status'] === 'completed'): ?>
                                <!-- Data grid -->
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0; padding: 0;">
                                    <div style="padding: 16px 20px; border-right: 1px solid #f3f4f6; border-bottom: 1px solid #f3f4f6;">
                                        <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-bottom: 4px;">ALE</div>
                                        <div style="font-size: 16px; font-weight: 600; color: #111;">
                                            <?php echo !empty($fair['ale']) ? '$' . number_format((float)$fair['ale'], 0) : '-'; ?>
                                        </div>
                                    </div>
                                    <div style="padding: 16px 20px; border-right: 1px solid #f3f4f6; border-bottom: 1px solid #f3f4f6;">
                                        <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-bottom: 4px;">LEF</div>
                                        <div style="font-size: 16px; font-weight: 600; color: #111;">
                                            <?php echo !empty($fair['loss_event_frequency']) ? number_format((float)$fair['loss_event_frequency'], 2) : '-'; ?>
                                        </div>
                                    </div>
                                    <div style="padding: 16px 20px; border-right: 1px solid #f3f4f6; border-bottom: 1px solid #f3f4f6;">
                                        <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-bottom: 4px;">PLM</div>
                                        <div style="font-size: 16px; font-weight: 600; color: #111;">
                                            <?php echo !empty($fair['primary_loss_magnitude']) ? '$' . number_format((float)$fair['primary_loss_magnitude'], 0) : '-'; ?>
                                        </div>
                                    </div>
                                    <div style="padding: 16px 20px; border-bottom: 1px solid #f3f4f6;">
                                        <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-bottom: 4px;">SLM</div>
                                        <div style="font-size: 16px; font-weight: 600; color: #111;">
                                            <?php echo !empty($fair['secondary_loss_magnitude']) ? '$' . number_format((float)$fair['secondary_loss_magnitude'], 0) : '-'; ?>
                                        </div>
                                    </div>
                                    <div style="padding: 16px 20px; border-right: 1px solid #f3f4f6;">
                                        <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-bottom: 4px;"><?php echo e(t('vendor-onboarding.label_recommended_insurance')); ?></div>
                                        <div style="font-size: 16px; font-weight: 600; color: #111;">
                                            <?php echo !empty($fair['recommended_liability']) ? '$' . number_format((float)$fair['recommended_liability'], 0) : '-'; ?>
                                        </div>
                                    </div>
                                    <?php if ($fairSecurityScore !== null): ?>
                                    <div style="padding: 16px 20px;">
                                        <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-bottom: 4px;"><?php echo e(t('vendor-onboarding.security_score_prefix')); ?> (<?php echo e($fairSecurityLabel); ?>)</div>
                                        <div style="font-size: 16px; font-weight: 600; color: #111;">
                                            <?php echo $fairSecurityScore; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div style="padding: 20px; text-align: center; color: #9ca3af; font-size: 13px;">
                                    <?php echo e(t('vendor-onboarding.analysis_draft_note')); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab: Risks -->
                <div id="vendorTabContent_risks" style="<?php echo $activeTab !== 'risks' ? 'display: none;' : ''; ?>">
                <?php if (!$isProcurementOnly): ?>
                    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 25px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;"><?php echo e(t('vendor-onboarding.risk_overview_heading')); ?></h3>
                            <?php if (($isAdmin || $isCyberTPRM) && !empty($request['vendor_domain']) && ($upguardAvailable || $shodanAvailable)):
                                if (!isset($isRescoring)) {
                                    $isRescoring = !empty($request['rescore_status']);
                                    if ($isRescoring && !empty($request['rescore_started_at'])) {
                                        $startedAt = strtotime($request['rescore_started_at']);
                                        if ($startedAt && (time() - $startedAt) > 600) { $isRescoring = false; }
                                    }
                                }
                                $bothAvail = $upguardAvailable && $shodanAvailable;
                            ?>
                            <?php if ($bothAvail): ?>
                            <div style="display: inline-block; position: relative;" id="onbScoreDropdown">
                                <button type="button" class="btn btn-primary" style="padding: 6px 14px; font-size: 12px;" data-action="toggleOnbScoreMenu" <?php echo $isRescoring ? 'disabled' : ''; ?>>
                                    <?php echo $isRescoring ? t('vendor-onboarding.btn_scoring_html') : t('vendor-onboarding.btn_score_menu_html'); ?>
                                </button>
                                <div id="onbScoreMenu" style="display: none; position: absolute; top: 100%; right: 0; margin-top: 4px; background: white; border: 1px solid #d1d5db; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 100; min-width: 180px; overflow: hidden;">
                                    <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=risks">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <button type="submit" name="rescore" style="display: block; width: 100%; padding: 8px 14px; border: none; background: none; text-align: left; cursor: pointer; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6;" class="hover-bg-gray"><?php echo e(t('vendor-onboarding.btn_score_all')); ?></button>
                                        <button type="submit" name="rescore_upguard" style="display: block; width: 100%; padding: 8px 14px; border: none; background: none; text-align: left; cursor: pointer; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6;" class="hover-bg-gray"><?php echo e($upguardDisplayName); ?></button>
                                        <button type="submit" name="rescore_shodan" style="display: block; width: 100%; padding: 8px 14px; border: none; background: none; text-align: left; cursor: pointer; font-size: 13px; color: #374151;" class="hover-bg-gray"><?php echo e($shodanDisplayName); ?></button>
                                    </form>
                                </div>
                            </div>
                            <?php else: ?>
                            <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=risks" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <button type="submit" name="rescore" class="btn btn-primary" style="padding: 6px 14px; font-size: 12px;" <?php echo $isRescoring ? 'disabled' : ''; ?>>
                                    <?php echo $isRescoring ? t('vendor-onboarding.btn_scoring_html') : t('vendor-onboarding.btn_score_now_html'); ?>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php
                        // Recent breach alerts affecting this vendor
                        $vendorBreachAlerts = [];
                        if ($requestId > 0) {
                            try {
                                $vendorBreachAlerts = $db->fetchAll(
                                    "SELECT id, title, severity, alert_type, status, affected_entity, affected_vendor_ids, created_at
                                     FROM cyber_breach_alerts
                                     WHERE status NOT IN ('resolved', 'false_positive')
                                       AND (affected_vendor_ids LIKE :idPattern
                                            OR LOWER(affected_entity) LIKE LOWER(:vendorName))
                                     ORDER BY FIELD(severity, 'critical','high','medium','low'), created_at DESC
                                     LIMIT 5",
                                    [
                                        ':idPattern' => '%' . $requestId . '%',
                                        ':vendorName' => '%' . ($request['vendor_name'] ?? '') . '%',
                                    ]
                                );
                                // Filter to only alerts that actually contain this vendor ID or match by name
                                $vendorBreachAlerts = array_values(array_filter($vendorBreachAlerts, function($a) use ($requestId, $request) {
                                    $ids = json_decode($a['affected_vendor_ids'] ?? '[]', true) ?: [];
                                    if (in_array($requestId, $ids)) return true;
                                    if (stripos($a['affected_entity'], $request['vendor_name'] ?? '___') !== false) return true;
                                    return false;
                                }));
                            } catch (Exception $e) {}
                        }
                        ?>
                        <?php if (!empty($vendorBreachAlerts)): ?>
                        <div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;padding:16px 20px;margin-bottom:20px;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                                <img src="app/icons/announcement-03.svg" alt="" width="18" height="18" style="opacity:0.8;">
                                <h4 style="margin:0;font-size:14px;font-weight:600;color:#991b1b;"><?php echo e(t('vendor-onboarding.breach_alerts_heading')); ?></h4>
                                <span style="background:#dc2626;color:#fff;font-size:10px;padding:2px 7px;border-radius:10px;font-weight:600;"><?php echo count($vendorBreachAlerts); ?></span>
                            </div>
                            <?php
                            $severityBadgeColors = ['critical'=>'#dc2626','high'=>'#ea580c','medium'=>'#ca8a04','low'=>'#16a34a'];
                            $lastBreachAlert = end($vendorBreachAlerts);
                            foreach ($vendorBreachAlerts as $ba):
                                $bColor = $severityBadgeColors[$ba['severity']] ?? '#6b7280';
                            ?>
                            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;<?php echo $ba['id'] !== $lastBreachAlert['id'] ? 'border-bottom:1px solid #fecaca;' : ''; ?>">
                                <span style="background:<?php echo $bColor; ?>;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;text-transform:uppercase;flex-shrink:0;"><?php echo e($ba['severity']); ?></span>
                                <a href="breach-alerts.php" style="font-size:13px;color:#991b1b;font-weight:500;text-decoration:none;flex:1;"><?php echo e($ba['title']); ?></a>
                                <span style="font-size:11px;color:#b91c1c;flex-shrink:0;"><?php echo date('M j, Y', strtotime($ba['created_at'])); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($latestScore || $shodanLatestScore): ?>
                        <!-- Score Cards -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 20px;">
                            <?php if ($latestScore): ?>
                            <div class="card" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px;">
                                <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #333;"><?php echo e($upguardDisplayName); ?> <?php echo e(t('vendor-onboarding.score_suffix')); ?></h3>
                                <div class="score-display" style="text-align: center; margin-bottom: 12px;">
                                    <?php
                                    $score = intval($latestScore['score']);
                                    $grade = $srsService->calculateGrade($score);
                                    $gradeClass = 'grade-' . strtolower($grade);
                                    ?>
                                    <span class="score-value" style="font-size: 36px; font-weight: 700; color: #333;"><?php echo displayUpguardScore($score, $upguardDisplayMode, $upguardMaxScore); ?></span>
                                    <span class="score-grade <?php echo $gradeClass; ?>" style="font-size: 24px; font-weight: 700; margin-left: 8px;"><?php echo $grade; ?></span>
                                    <div style="font-size: 11px; color: #6b7280; margin-top: 2px;"><?php echo $upguardDisplayMode === 'percentage' ? t('vendor-onboarding.percentage_score') : t('vendor-onboarding.out_of_prefix') . e($upguardMaxScore); ?></div>
                                    <div style="font-size: 11px; color: #9ca3af;"><?php echo e(t('vendor-onboarding.scored_on_prefix')); ?> <?php echo date('M j, Y g:i A', strtotime($latestScore['scored_at'])); ?></div>
                                </div>

                                <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; text-align: center;">
                                    <div style="padding: 6px; background: #fef2f2; border-radius: 6px;">
                                        <div style="font-size: 18px; font-weight: 700; color: #991b1b;"><?php echo intval($latestScore['critical_risks'] ?? 0); ?></div>
                                        <div style="font-size: 10px; color: #666;"><?php echo e(t('vendor-onboarding.sev_critical')); ?></div>
                                    </div>
                                    <div style="padding: 6px; background: #fef2f2; border-radius: 6px;">
                                        <div style="font-size: 18px; font-weight: 700; color: #dc2626;"><?php echo intval($latestScore['high_risks']); ?></div>
                                        <div style="font-size: 10px; color: #666;"><?php echo e(t('vendor-onboarding.sev_high')); ?></div>
                                    </div>
                                    <div style="padding: 6px; background: #fff7ed; border-radius: 6px;">
                                        <div style="font-size: 18px; font-weight: 700; color: #ea580c;"><?php echo intval($latestScore['medium_risks']); ?></div>
                                        <div style="font-size: 10px; color: #666;"><?php echo e(t('vendor-onboarding.sev_medium')); ?></div>
                                    </div>
                                    <div style="padding: 6px; background: #fefce8; border-radius: 6px;">
                                        <div style="font-size: 18px; font-weight: 700; color: #ca8a04;"><?php echo intval($latestScore['low_risks']); ?></div>
                                        <div style="font-size: 10px; color: #666;"><?php echo e(t('vendor-onboarding.sev_low')); ?></div>
                                    </div>
                                    <div style="padding: 6px; background: #f9fafb; border-radius: 6px;">
                                        <div style="font-size: 18px; font-weight: 700; color: #6b7280;"><?php echo intval($latestScore['info_risks']); ?></div>
                                        <div style="font-size: 10px; color: #666;"><?php echo e(t('vendor-onboarding.sev_info')); ?></div>
                                    </div>
                                </div>

                                <?php
                                $categoryScores = [];
                                if (!empty($latestScore['category_scores'])) {
                                    $categoryScores = is_string($latestScore['category_scores'])
                                        ? json_decode($latestScore['category_scores'], true)
                                        : $latestScore['category_scores'];
                                }
                                if (!empty($categoryScores)):
                                ?>
                                <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #f0f0f0;">
                                    <h4 style="font-size: 11px; color: #666; margin: 0 0 8px 0; font-weight: 600;"><?php echo e(t('vendor-onboarding.category_scores_heading')); ?></h4>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 6px;">
                                        <?php foreach ($categoryScores as $category => $catScore): ?>
                                            <?php
                                            $categoryName = preg_replace('/([a-z])([A-Z])/', '$1 $2', $category);
                                            $categoryName = ucwords($categoryName);
                                            $catScoreInt = is_numeric($catScore) ? intval($catScore) : 0;
                                            $catDisplayVal = ($upguardDisplayMode === 'percentage' && $upguardMaxScore > 0)
                                                ? (int)floor(($catScoreInt / $upguardMaxScore) * 100)
                                                : $catScoreInt;
                                            $catPct = $upguardMaxScore > 0 ? ($catScoreInt / $upguardMaxScore) * 100 : 0;
                                            $scoreColor = '#6b7280';
                                            if ($catPct >= 85) $scoreColor = '#166534';
                                            elseif ($catPct >= 65) $scoreColor = '#15803d';
                                            elseif ($catPct >= 45) $scoreColor = '#ca8a04';
                                            elseif ($catPct >= 25) $scoreColor = '#ea580c';
                                            else $scoreColor = '#dc2626';
                                            ?>
                                            <div style="background: #f8f9fa; padding: 6px; border-radius: 4px; text-align: center;">
                                                <div style="font-size: 9px; color: #666; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.3px;"><?php echo e($categoryName); ?></div>
                                                <div style="font-size: 16px; font-weight: 700; color: <?php echo $scoreColor; ?>;"><?php echo $catDisplayVal; ?><?php echo $upguardDisplayMode === 'percentage' ? '%' : ''; ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($shodanLatestScore): ?>
                            <div class="card" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px;">
                                <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #333;"><?php echo e($shodanDisplayName); ?> <?php echo e(t('vendor-onboarding.score_suffix')); ?></h3>
                                <div class="score-display" style="text-align: center; margin-bottom: 12px;">
                                    <?php
                                    $shodanScore = intval($shodanLatestScore['score']);
                                    $shodanGrade = $shodanService->calculateGrade($shodanScore);
                                    $shodanGradeClass = 'grade-' . strtolower($shodanGrade);
                                    $shodanTrafficLight = $shodanLatestScore['traffic_light'] ?? null;
                                    $tlColors = ['green' => '#16a34a', 'yellow' => '#eab308', 'red' => '#dc2626'];
                                    $tlLabels = ['green' => t('vendor-onboarding.tl_acceptable'), 'yellow' => t('vendor-onboarding.tl_needs_improvement'), 'red' => t('vendor-onboarding.tl_unacceptable')];
                                    ?>
                                    <span class="score-value" style="font-size: 36px; font-weight: 700; color: #333;"><?php echo $shodanScore; ?>%</span>
                                    <span class="score-grade <?php echo $shodanGradeClass; ?>" style="font-size: 24px; font-weight: 700; margin-left: 8px;"><?php echo $shodanGrade; ?></span>
                                    <?php if ($shodanTrafficLight): ?>
                                    <span style="display: inline-block; width: 18px; height: 18px; border-radius: 50%; background: <?php echo $tlColors[$shodanTrafficLight] ?? '#9ca3af'; ?>; vertical-align: middle; margin-left: 8px; box-shadow: 0 0 6px <?php echo $tlColors[$shodanTrafficLight] ?? '#9ca3af'; ?>;" title="<?php echo e(t('vendor-onboarding.title_risk_prefix')); ?> <?php echo e($tlLabels[$shodanTrafficLight] ?? t('vendor-onboarding.unknown')); ?>"></span>
                                    <?php endif; ?>
                                    <div style="font-size: 11px; color: #6b7280; margin-top: 2px;"><?php echo e(t('vendor-onboarding.percentage_score')); ?> (<?php echo e($shodanDisplayName); ?>)</div>
                                    <div style="font-size: 11px; color: #9ca3af;"><?php echo e(t('vendor-onboarding.scored_on_prefix')); ?> <?php echo date('M j, Y g:i A', strtotime($shodanLatestScore['scored_at'])); ?></div>
                                </div>

                                <?php
                                $shodanCategoryScores = null;
                                if (!empty($shodanLatestScore['category_scores'])) {
                                    $shodanCategoryScores = is_string($shodanLatestScore['category_scores'])
                                        ? json_decode($shodanLatestScore['category_scores'], true)
                                        : $shodanLatestScore['category_scores'];
                                }
                                if ($shodanCategoryScores):
                                    $categoryLabels = [
                                        'tls_crypto' => t('vendor-onboarding.cat_tls_crypto'),
                                        'network_security' => t('vendor-onboarding.cat_network_security'),
                                        'app_hardening' => t('vendor-onboarding.cat_app_hardening'),
                                        'vuln_exposure' => t('vendor-onboarding.cat_vuln_exposure'),
                                        'email_security' => t('vendor-onboarding.cat_email_security'),
                                    ];
                                    $shodanWeights = $shodanService->getCategoryWeights();
                                    $categoryWeights = [];
                                    foreach ($shodanWeights as $wk => $wv) {
                                        $categoryWeights[$wk] = $wv . '%';
                                    }
                                ?>
                                <div style="margin-top: 15px; padding-top: 12px; border-top: 1px solid #f0f0f0;">
                                    <h4 style="font-size: 12px; color: #666; margin: 0 0 10px 0; font-weight: 600;"><?php echo e(t('vendor-onboarding.category_scores_heading')); ?></h4>
                                    <?php foreach ($shodanCategoryScores as $catKey => $catScore):
                                        $catScoreInt = intval($catScore);
                                        $barColor = '#6b7280';
                                        if ($catScoreInt >= 80) $barColor = '#16a34a';
                                        elseif ($catScoreInt >= 60) $barColor = '#65a30d';
                                        elseif ($catScoreInt >= 40) $barColor = '#eab308';
                                        elseif ($catScoreInt >= 20) $barColor = '#ea580c';
                                        else $barColor = '#dc2626';
                                    ?>
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                                        <div style="width: 120px; font-size: 11px; color: #555; text-align: right;">
                                            <?php echo e($categoryLabels[$catKey] ?? ucwords(str_replace('_', ' ', $catKey))); ?>
                                            <span style="color: #999; font-size: 9px;">(<?php echo e($categoryWeights[$catKey] ?? ''); ?>)</span>
                                        </div>
                                        <div style="flex: 1; background: #f3f4f6; border-radius: 4px; height: 16px; overflow: hidden;">
                                            <div style="width: <?php echo $catScoreInt; ?>%; height: 100%; background: <?php echo $barColor; ?>; border-radius: 4px; transition: width 0.5s;"></div>
                                        </div>
                                        <div style="width: 36px; font-size: 12px; font-weight: 700; color: <?php echo $barColor; ?>; text-align: right;"><?php echo $catScoreInt; ?>%</div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 15px;">
                                    <?php
                                    $shodanPositive = intval($shodanLatestScore['positive_count'] ?? 0);
                                    $shodanNegative = intval($shodanLatestScore['negative_count'] ?? 0);
                                    if ($shodanPositive > 0 || $shodanNegative > 0):
                                    ?>
                                    <div style="text-align: center; padding: 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;">
                                        <div style="font-size: 24px; font-weight: 700; color: #16a34a;"><?php echo $shodanPositive; ?></div>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-onboarding.label_positive_signals')); ?></div>
                                    </div>
                                    <div style="text-align: center; padding: 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
                                        <div style="font-size: 24px; font-weight: 700; color: #dc2626;"><?php echo $shodanNegative; ?></div>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-onboarding.label_negative_signals')); ?></div>
                                    </div>
                                    <div style="text-align: center; padding: 10px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;">
                                        <div style="font-size: 24px; font-weight: 700; color: #0284c7;"><?php echo intval($shodanLatestScore['open_ports_count']); ?></div>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-onboarding.label_open_ports')); ?></div>
                                    </div>
                                    <?php if (!empty($shodanWaivers)): ?>
                                    <div style="text-align: center; padding: 10px; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px;">
                                        <div style="font-size: 24px; font-weight: 700; color: #4f46e5;"><?php echo count($shodanWaivers); ?></div>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-onboarding.label_waived_risks')); ?></div>
                                    </div>
                                    <?php else: ?>
                                    <div style="text-align: center; padding: 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
                                        <div style="font-size: 24px; font-weight: 700; color: #dc2626;"><?php echo intval($shodanLatestScore['vuln_count']); ?></div>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-onboarding.label_total_cves')); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <div style="text-align: center; padding: 10px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;">
                                        <div style="font-size: 24px; font-weight: 700; color: #0284c7;"><?php echo intval($shodanLatestScore['open_ports_count']); ?></div>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-onboarding.label_open_ports')); ?></div>
                                    </div>
                                    <div style="text-align: center; padding: 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
                                        <div style="font-size: 24px; font-weight: 700; color: #dc2626;"><?php echo intval($shodanLatestScore['critical_vulns']); ?></div>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-onboarding.label_critical_cves')); ?></div>
                                    </div>
                                    <div style="text-align: center; padding: 10px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px;">
                                        <div style="font-size: 24px; font-weight: 700; color: #ea580c;"><?php echo intval($shodanLatestScore['high_vulns']); ?></div>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-onboarding.label_high_cves')); ?></div>
                                    </div>
                                    <div style="text-align: center; padding: 10px; background: #fefce8; border: 1px solid #fef08a; border-radius: 8px;">
                                        <div style="font-size: 24px; font-weight: 700; color: #ca8a04;"><?php echo intval($shodanLatestScore['medium_vulns'] + $shodanLatestScore['low_vulns']); ?></div>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-onboarding.label_medlow_cves')); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($vendorRisksCount === 0 && empty($vendorFairAnalyses) && !$latestScore && !$shodanLatestScore): ?>
                            <p style="color: #999; text-align: center; padding: 30px 0;"><?php echo e(t('vendor-onboarding.no_risk_data')); ?> <?php if (($isAdmin || $isCyberTPRM) && !empty($request['vendor_domain']) && ($upguardAvailable || $shodanAvailable)): ?><?php echo e(t('vendor-onboarding.use_score_button')); ?><?php endif; ?></p>
                        <?php else: ?>

                        <?php
                        $sevColors = ['critical' => '#991b1b', 'high' => '#dc2626', 'medium' => '#ea580c', 'low' => '#ca8a04', 'info' => '#6b7280', 'positive' => '#166534', 'negative' => '#991b1b'];
                        $sevBgs = ['critical' => '#fef2f2', 'high' => '#fef2f2', 'medium' => '#fff7ed', 'low' => '#fefce8', 'info' => '#f9fafb', 'positive' => '#dcfce7', 'negative' => '#fef2f2'];
                        $sevBorders = ['critical' => '#fecaca', 'high' => '#fed7aa', 'medium' => '#fde68a', 'low' => '#fef08a', 'info' => '#e5e7eb', 'positive' => '#bbf7d0', 'negative' => '#fecaca'];
                        $srsDetailUrl = 'vendor-srs-details.php?id=' . $requestId;
                        ?>

                        <!-- Summary Bar -->
                        <?php if (!empty($vendorSrsRisks) || !empty($vendorShodanFindings)): ?>
                        <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
                            <?php if (!empty($vendorSrsRisks)): ?>
                            <div style="flex: 1; min-width: 240px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 14px;">
                                <div style="font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo e($upguardDisplayName); ?> <?php echo e(t('vendor-onboarding.risks_suffix')); ?></div>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <?php foreach ($vendorSrsRisks as $risk):
                                        $sev = strtolower($risk['severity']);
                                        $sc = $sevColors[$sev] ?? '#6b7280';
                                        $sb = $sevBgs[$sev] ?? '#f9fafb';
                                        $sbr = $sevBorders[$sev] ?? '#e5e7eb';
                                    ?>
                                    <button type="button" data-filter-severity="<?php echo e($sev); ?>" data-filter-table="srsRiskTable" style="cursor: pointer; display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: <?php echo $sb; ?>; border: 1px solid <?php echo $sbr; ?>; border-radius: 12px; font-size: 12px; color: <?php echo $sc; ?>; font-weight: 500; transition: opacity 0.15s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                                        <span style="font-weight: 700;"><?php echo $risk['count']; ?></span>
                                        <?php echo ucfirst($sev); ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($vendorShodanFindings)): ?>
                            <div style="flex: 1; min-width: 240px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 14px;">
                                <div style="font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo e($shodanDisplayName); ?> <?php echo e(t('vendor-onboarding.findings_suffix')); ?></div>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <?php foreach ($vendorShodanFindings as $finding):
                                        $sev = strtolower($finding['severity'] ?? '');
                                        $sc = $sevColors[$sev] ?? '#6b7280';
                                        $sb = $sevBgs[$sev] ?? '#f9fafb';
                                        $sbr = $sevBorders[$sev] ?? '#e5e7eb';
                                    ?>
                                    <button type="button" data-filter-severity="<?php echo e($sev); ?>" data-filter-table="shodanFindingTable" style="cursor: pointer; display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: <?php echo $sb; ?>; border: 1px solid <?php echo $sbr; ?>; border-radius: 12px; font-size: 12px; color: <?php echo $sc; ?>; font-weight: 500; transition: opacity 0.15s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                                        <span style="font-weight: 700;"><?php echo $finding['count']; ?></span>
                                        <?php echo ucfirst($sev); ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($vendorSrsRisks)): ?>
                        <!-- SRS Risks -->
                        <details id="srsRiskDetails" style="margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px;">
                            <summary style="padding: 12px 16px; cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; background: #f9fafb; border-radius: 8px; user-select: none;">
                                <h4 style="margin: 0; font-size: 15px; font-weight: 600; color: #333;"><?php echo e($upguardDisplayName); ?> <?php echo e(t('vendor-onboarding.risks_suffix')); ?></h4>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <a href="vendor-srs-details.php?id=<?php echo $requestId; ?>" style="color: var(--theme-header-color); text-decoration: none; font-size: 13px;" onclick="event.stopPropagation();"><?php echo t('vendor-onboarding.view_details_link'); ?></a>
                                    <span style="font-size: 10px; color: #9ca3af; transition: transform 0.2s;" class="details-arrow">&#9654;</span>
                                </div>
                            </summary>
                            <div style="padding: 16px;">
                                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px;">
                                    <?php foreach ($vendorSrsRisks as $risk):
                                        $sev = strtolower($risk['severity']);
                                    ?>
                                    <div data-filter-severity="<?php echo e($sev); ?>" data-filter-table="srsRiskTable" style="background: <?php echo $sevBgs[$sev] ?? '#f9fafb'; ?>; border: 1px solid <?php echo $sevColors[$sev] ?? '#ddd'; ?>33; border-radius: 8px; padding: 12px 18px; text-align: center; min-width: 90px; cursor: pointer; transition: opacity 0.15s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                                        <div style="font-size: 22px; font-weight: 700; color: <?php echo $sevColors[$sev] ?? '#333'; ?>;"><?php echo $risk['count']; ?></div>
                                        <div style="font-size: 11px; color: <?php echo $sevColors[$sev] ?? '#666'; ?>; font-weight: 500; text-transform: uppercase;"><?php echo e(ucfirst($sev)); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!empty($vendorSrsRiskDetails)): ?>
                                <table id="srsRiskTable" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                    <thead>
                                        <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                            <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;"><?php echo e(t('vendor-onboarding.th_severity')); ?></th>
                                            <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;"><?php echo e(t('vendor-onboarding.risk_suffix')); ?></th>
                                            <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;"><?php echo e(t('vendor-onboarding.grip_category')); ?></th>
                                            <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;"><?php echo e(t('vendor-onboarding.th_first_seen')); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($vendorSrsRiskDetails as $rd):
                                            $rdSev = strtolower($rd['severity'] ?? 'info');
                                        ?>
                                        <tr data-severity="<?php echo e($rdSev); ?>" style="border-bottom: 1px solid #f3f4f6;">
                                            <td style="padding: 8px 12px;">
                                                <span style="display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; text-transform: uppercase; background: <?php echo $sevBgs[$rdSev] ?? '#f9fafb'; ?>; color: <?php echo $sevColors[$rdSev] ?? '#666'; ?>;"><?php echo e(ucfirst($rdSev)); ?></span>
                                            </td>
                                            <td style="padding: 8px 12px; color: #333;">
                                                <?php echo e($rd['risk_name'] ?: t('vendor-onboarding.unknown')); ?>
                                                <?php if (!empty($rd['description'])): ?>
                                                    <div style="font-size: 11px; color: #999; margin-top: 2px; max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo e($rd['description']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 8px 12px; color: #666;"><?php echo e($rd['risk_category'] ?: '-'); ?></td>
                                            <td style="padding: 8px 12px; color: #999;"><?php echo $rd['first_seen'] ? date('M j, Y', strtotime($rd['first_seen'])) : '-'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>
                        </details>
                        <?php endif; ?>

                        <?php if (!empty($vendorShodanFindings)): ?>
                        <!-- Shodan Findings -->
                        <details id="shodanFindingDetails" style="margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px;">
                            <summary style="padding: 12px 16px; cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; background: #f9fafb; border-radius: 8px; user-select: none;">
                                <h4 style="margin: 0; font-size: 15px; font-weight: 600; color: #333;"><?php echo e($shodanDisplayName); ?> <?php echo e(t('vendor-onboarding.findings_suffix')); ?></h4>
                                <span style="font-size: 10px; color: #9ca3af; transition: transform 0.2s;" class="details-arrow">&#9654;</span>
                            </summary>
                            <div style="padding: 16px;">
                                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px;">
                                    <?php foreach ($vendorShodanFindings as $finding):
                                        $sev = strtolower($finding['severity'] ?? '');
                                    ?>
                                    <div data-filter-severity="<?php echo e($sev); ?>" data-filter-table="shodanFindingTable" style="background: <?php echo $sevBgs[$sev] ?? '#f9fafb'; ?>; border: 1px solid <?php echo $sevColors[$sev] ?? '#ddd'; ?>33; border-radius: 8px; padding: 12px 18px; text-align: center; min-width: 90px; cursor: pointer; transition: opacity 0.15s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                                        <div style="font-size: 22px; font-weight: 700; color: <?php echo $sevColors[$sev] ?? '#333'; ?>;"><?php echo $finding['count']; ?></div>
                                        <div style="font-size: 11px; color: <?php echo $sevColors[$sev] ?? '#666'; ?>; font-weight: 500; text-transform: uppercase;"><?php echo e(ucfirst($sev)); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!empty($vendorShodanFindingDetails)): ?>
                                <table id="shodanFindingTable" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                    <thead>
                                        <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                            <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;"><?php echo e(t('vendor-onboarding.th_severity')); ?></th>
                                            <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;"><?php echo e(t('vendor-onboarding.th_finding')); ?></th>
                                            <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;"><?php echo e(t('vendor-onboarding.grip_category')); ?></th>
                                            <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;"><?php echo e(t('vendor-onboarding.th_target')); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($vendorShodanFindingDetails as $fd):
                                            $fdSev = strtolower($fd['severity'] ?? 'info');
                                            $fdTarget = '';
                                            if (!empty($fd['subdomain'])) $fdTarget = $fd['subdomain'];
                                            if (!empty($fd['ip_address'])) $fdTarget .= ($fdTarget ? ' / ' : '') . $fd['ip_address'];
                                            if (!empty($fd['port'])) $fdTarget .= ':' . $fd['port'];
                                        ?>
                                        <tr data-severity="<?php echo e($fdSev); ?>" style="border-bottom: 1px solid #f3f4f6;">
                                            <td style="padding: 8px 12px;">
                                                <span style="display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; text-transform: uppercase; background: <?php echo $sevBgs[$fdSev] ?? '#f9fafb'; ?>; color: <?php echo $sevColors[$fdSev] ?? '#666'; ?>;"><?php echo e(ucfirst($fdSev)); ?></span>
                                            </td>
                                            <td style="padding: 8px 12px; color: #333;">
                                                <?php echo e($fd['description'] ?: ucwords(str_replace('_', ' ', $fd['finding_type']))); ?>
                                                <?php if (!empty($fd['cve_id'])): ?>
                                                    <a href="https://nvd.nist.gov/vuln/detail/<?php echo e(strtolower($fd['cve_id'])); ?>" target="_blank" rel="noopener noreferrer" style="font-size: 11px; color: #dc2626; font-weight: 500; margin-left: 4px; text-decoration: none; border-bottom: 1px dashed #dc2626;"><?php echo e($fd['cve_id']); ?><?php echo $fd['cvss_score'] ? ' (' . $fd['cvss_score'] . ')' : ''; ?></a>
                                                <?php endif; ?>
                                                <?php if (!empty($fd['service_name'])): ?>
                                                    <div style="font-size: 11px; color: #999; margin-top: 2px;"><?php echo e(t('vendor-onboarding.service_prefix')); ?> <?php echo e($fd['service_name']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 8px 12px; color: #666;"><?php echo e(ucwords(str_replace('_', ' ', $fd['category'] ?: $fd['finding_type']))); ?></td>
                                            <td style="padding: 8px 12px; color: #999; font-size: 12px;"><?php echo e($fdTarget ?: '-'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>
                        </details>
                        <?php endif; ?>

                        <?php if (!empty($vendorFairAnalyses)): ?>
                        <!-- FAIR Analyses -->
                        <div>
                            <h4 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #333;"><?php echo e(t('vendor-onboarding.fair_analyses_heading')); ?></h4>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <?php foreach ($vendorFairAnalyses as $fair):
                                    $fairStatusStyle = $fair['status'] === 'completed' ? 'background: #dcfce7; color: #166534' : 'background: #fef3c7; color: #92400e';
                                ?>
                                <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;">
                                    <div>
                                        <span style="font-weight: 500; color: #333;"><?php echo e($fair['vendor_name'] ?: $fair['vendor_domain']); ?></span>
                                        <span style="padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 500; margin-left: 8px; <?php echo $fairStatusStyle; ?>"><?php echo ucfirst($fair['status']); ?></span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <span style="font-size: 12px; color: #999;"><?php echo date('M j, Y', strtotime($fair['created_at'])); ?></span>
                                        <a href="view-result.php?id=<?php echo $fair['id']; ?>" style="color: var(--theme-header-color); text-decoration: none; font-size: 13px; font-weight: 500;"><?php echo e(t('vendor-onboarding.btn_view')); ?></a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- ============================================================ -->
                    <!-- PROCUREMENT: Embedded Vendor Detailed Summary Report         -->
                    <!-- ============================================================ -->
                    <style>
                        /* Scoped report styles for procurement detailed summary */
                        @media print {
                            @page {
                                size: letter;
                                margin: 0.6in 0.5in 0.8in 0.5in;
                            }
                            body { margin: 0; padding: 0; }
                            .no-print { display: none !important; }
                            .proc-page-break { page-break-after: always; }
                            .proc-avoid-break { page-break-inside: avoid; }
                            .proc-report-footer { display: none; }
                            canvas { max-width: 100% !important; max-height: 250px !important; }
                            /* Hide everything except the report during print */
                            .main-sidebar, .top-header, .vendor-section-tab, #vendorTabContent_vendor,
                            #vendorTabContent_notes, #vendorTabContent_assessments, #vendorTabContent_fair,
                            #vendorTabContent_documents, #vendorTabContent_action_plan { display: none !important; }
                        }
                        .proc-report .proc-container { max-width: 8.5in; margin: 0 auto; padding: 20px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 10pt; line-height: 1.5; color: #1f2937; }
                        .proc-report .proc-report-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 3px solid <?php echo e($headerColor); ?>; margin-bottom: 16px; }
                        .proc-report .proc-report-header .logo { width: 200px; height: 38px; object-fit: contain; }
                        .proc-report .proc-report-header .report-info { text-align: right; }
                        .proc-report .proc-report-header .report-info h1 { font-size: 18pt; color: #111827; margin-bottom: 2px; }
                        .proc-report .proc-report-header .report-info .subtitle { font-size: 11pt; color: #4b5563; font-weight: 600; }
                        .proc-report .proc-report-header .report-info p { font-size: 8pt; color: #9ca3af; margin-top: 2px; }
                        .proc-report .proc-section-title { font-size: 13pt; font-weight: 700; color: #111827; border-bottom: 2px solid <?php echo e($headerColor); ?>; padding-bottom: 4px; margin: 18px 0 10px 0; }
                        .proc-report .proc-section-title-sm { font-size: 11pt; font-weight: 700; color: #374151; margin: 14px 0 8px 0; }
                        .proc-report .proc-vendor-bar { background: #111827; color: white; padding: 12px 18px; border-radius: 6px; margin-bottom: 16px; text-align: center; }
                        .proc-report .proc-vendor-bar h2 { font-size: 16pt; margin-bottom: 2px; }
                        .proc-report .proc-vendor-bar .sub { font-size: 9pt; opacity: 0.85; }
                        .proc-report .proc-info-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-bottom: 16px; }
                        .proc-report .proc-info-item { background: #f9fafb; padding: 8px 10px; border-radius: 4px; border-left: 3px solid <?php echo e($headerColor); ?>; }
                        .proc-report .proc-info-item .label { font-size: 7pt; text-transform: uppercase; color: #6b7280; letter-spacing: 0.5px; }
                        .proc-report .proc-info-item .value { font-size: 10pt; font-weight: 600; color: #111827; }
                        .proc-report .proc-score-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
                        .proc-report .proc-score-card { background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 8px; padding: 14px; text-align: center; }
                        .proc-report .proc-score-card .source { font-size: 8pt; text-transform: uppercase; color: #6b7280; letter-spacing: 0.5px; margin-bottom: 6px; }
                        .proc-report .proc-score-card .score-value { font-size: 24pt; font-weight: 800; color: #111827; }
                        .proc-report .proc-score-card .grade { display: inline-block; font-size: 14pt; font-weight: 800; width: 36px; height: 36px; line-height: 36px; border-radius: 50%; color: white; margin-top: 4px; }
                        .proc-report .proc-score-card .scored-date { font-size: 7pt; color: #9ca3af; margin-top: 4px; }
                        .proc-report .proc-chart-container { margin-bottom: 16px; }
                        .proc-report .proc-chart-container canvas { max-height: 260px; }
                        .proc-report .proc-chart-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
                        .proc-report .proc-chart-half { position: relative; }
                        .proc-report .proc-chart-half canvas { max-height: 220px; width: 100% !important; height: auto !important; }
                        .proc-report table.proc-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 12px; }
                        .proc-report table.proc-table th, .proc-report table.proc-table td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
                        .proc-report table.proc-table th { background: #f3f4f6; font-weight: 700; color: #374151; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.3px; }
                        .proc-report table.proc-table td { color: #4b5563; }
                        .proc-report .severity-critical { color: #dc2626; font-weight: 700; }
                        .proc-report .severity-high { color: #ea580c; font-weight: 700; }
                        .proc-report .severity-medium { color: #d97706; font-weight: 600; }
                        .proc-report .severity-low { color: #2563eb; }
                        .proc-report .severity-info { color: #6b7280; }
                        .proc-report .proc-score-bar { display: flex; align-items: center; gap: 6px; }
                        .proc-report .proc-score-bar-bg { flex: 1; height: 10px; border-radius: 5px; background: #e5e7eb; overflow: hidden; }
                        .proc-report .proc-score-bar-fill { height: 10px; border-radius: 5px; background: <?php echo e($headerColor); ?>; }
                        .proc-report .proc-fair-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
                        .proc-report .proc-fair-metric { background: #fff; padding: 10px 12px; border-radius: 4px; border-left: 4px solid <?php echo e($headerColor); ?>; border: 1px solid #e5e7eb; }
                        .proc-report .proc-fair-metric .label { font-size: 7pt; text-transform: uppercase; color: #6b7280; letter-spacing: 0.3px; }
                        .proc-report .proc-fair-metric .value { font-size: 13pt; font-weight: 700; color: #111827; }
                        .proc-report .proc-fair-metric .desc { font-size: 7pt; color: #9ca3af; font-style: italic; }
                        .proc-report .proc-tech-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
                        .proc-report .proc-tech-category { font-size: 8pt; font-weight: 700; color: #374151; text-transform: uppercase; margin-bottom: 3px; letter-spacing: 0.3px; }
                        .proc-report .proc-tech-item { font-size: 8.5pt; color: #4b5563; padding: 2px 0; }
                        .proc-report .proc-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: none; border-radius: 6px; font-size: 10pt; font-weight: 600; cursor: pointer; text-decoration: none; }
                        .proc-report .proc-btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
                        .proc-report .proc-btn-secondary:hover { background: #e5e7eb; }
                        .proc-report .proc-action-bar { display: flex; gap: 8px; margin-bottom: 16px; }
                        .proc-report .proc-report-footer { margin-top: 24px; padding-top: 10px; border-top: 2px solid #e5e7eb; font-size: 7pt; color: #9ca3af; text-align: center; }
                        .proc-report .proc-placeholder { color: #94a3b8; font-style: italic; font-size: 9pt; padding: 12px; text-align: center; }
                    </style>

                    <div class="proc-report">
                        <div class="proc-container">

                            <!-- Action Bar (screen only) -->
                            <div class="proc-action-bar no-print">
                                <button type="button" class="proc-btn proc-btn-secondary" id="procPrintBtn"><?php echo e(t('vendor-onboarding.btn_print_pdf')); ?></button>
                                <?php
                                // Check if AI commentary is available (OpenWebUI or LibreChat)
                                $procAiEnabled = false;
                                try {
                                    $procAiEnabled = AIPlatformService::getInstance()->isEnabled();
                                } catch (Exception $e) {}
                                ?>
                                <?php if ($procAiEnabled && ($latestScore || $shodanLatestScore)): ?>
                                <button type="button" class="proc-btn proc-btn-secondary" id="procCommentaryBtn"><?php echo e(t('vendor-onboarding.btn_generate_commentary')); ?></button>
                                <?php endif; ?>
                            </div>

                            <!-- Report Header -->
                            <div class="proc-report-header">
                                <img src="<?php echo e($logoUrl); ?>" alt="<?php echo e(t('vendor-onboarding.alt_logo')); ?>" class="logo">
                                <div class="report-info">
                                    <h1><?php echo e(t('vendor-onboarding.report_title')); ?></h1>
                                    <div class="subtitle"><?php echo e($request['vendor_name']); ?> | <?php echo e($request['vendor_domain'] ?? t('vendor-onboarding.no_domain')); ?></div>
                                    <p><?php echo e($reportDate); ?> <?php echo e(t('vendor-onboarding.at_connector')); ?> <?php echo e($reportTime); ?> | <?php echo e(t('vendor-onboarding.confidential')); ?></p>
                                </div>
                            </div>

                            <!-- Vendor Information -->
                            <div class="proc-vendor-bar">
                                <h2><?php echo e($request['vendor_name']); ?></h2>
                                <div class="sub"><?php echo e($request['vendor_domain'] ?? t('vendor-onboarding.no_domain_specified')); ?></div>
                            </div>

                            <div class="proc-info-grid">
                                <div class="proc-info-item">
                                    <div class="label"><?php echo e(t('vendor-onboarding.field_vendor_type')); ?></div>
                                    <div class="value"><?php echo e($request['vendor_type'] ?? t('vendor-onboarding.not_set')); ?></div>
                                </div>
                                <div class="proc-info-item">
                                    <div class="label"><?php echo e(t('vendor-onboarding.tier_label')); ?></div>
                                    <div class="value"><?php echo $request['vendor_tier'] ? 'Tier ' . e($request['vendor_tier']) : 'Not tiered'; ?></div>
                                </div>
                                <div class="proc-info-item">
                                    <div class="label"><?php echo e(t('vendor-onboarding.label_status')); ?></div>
                                    <div class="value"><?php echo e(ucfirst(str_replace('_', ' ', $request['status'] ?? t('vendor-onboarding.unknown')))); ?></div>
                                </div>
                                <div class="proc-info-item">
                                    <div class="label"><?php echo e(t('vendor-onboarding.tab_assessments')); ?></div>
                                    <div class="value"><?php echo $assessmentCount; ?> <?php echo e(t('vendor-onboarding.completed_suffix')); ?></div>
                                </div>
                                <div class="proc-info-item">
                                    <div class="label"><?php echo e(t('vendor-onboarding.label_technologies')); ?></div>
                                    <div class="value"><?php echo count($technologies); ?> <?php echo e(t('vendor-onboarding.detected_suffix')); ?></div>
                                </div>
                            </div>

                            <!-- Score Overview -->
                            <h3 class="proc-section-title"><?php echo e(t('vendor-onboarding.section_score_overview')); ?></h3>
                            <div class="proc-score-grid">
                                <?php if ($latestScore): ?>
                                <div class="proc-score-card">
                                    <div class="source"><?php echo e($upguardDisplayName); ?></div>
                                    <div class="score-value"><?php echo displayUpguardScore(intval($latestScore['score']), $upguardDisplayMode, $upguardMaxScore); ?></div>
                                    <div class="grade" style="background: <?php echo gradeClass($ugGrade); ?>"><?php echo $ugGrade; ?></div>
                                    <div class="scored-date"><?php echo e(t('vendor-onboarding.scored_prefix')); ?> <?php echo date('M j, Y', strtotime($latestScore['scored_at'])); ?></div>
                                </div>
                                <?php else: ?>
                                <div class="proc-score-card"><div class="source"><?php echo e($upguardDisplayName); ?></div><div class="proc-placeholder"><?php echo e(t('vendor-onboarding.no_score_available')); ?></div></div>
                                <?php endif; ?>

                                <?php if ($shodanLatestScore): ?>
                                <div class="proc-score-card">
                                    <div class="source"><?php echo e($shodanDisplayName); ?></div>
                                    <div class="score-value"><?php echo intval($shodanLatestScore['score']); ?>%</div>
                                    <div class="grade" style="background: <?php echo gradeClass($shGrade); ?>"><?php echo $shGrade; ?></div>
                                    <div class="scored-date"><?php echo e(t('vendor-onboarding.scored_prefix')); ?> <?php echo date('M j, Y', strtotime($shodanLatestScore['scored_at'])); ?></div>
                                </div>
                                <?php else: ?>
                                <div class="proc-score-card"><div class="source"><?php echo e($shodanDisplayName); ?></div><div class="proc-placeholder"><?php echo e(t('vendor-onboarding.no_score_available')); ?></div></div>
                                <?php endif; ?>

                                <div class="proc-score-card">
                                    <div class="source"><?php echo e(t('vendor-onboarding.label_combined_score')); ?></div>
                                    <?php if ($combinedScore['score'] !== null): ?>
                                    <div class="score-value"><?php echo $combinedScore['score']; ?>%</div>
                                    <div class="grade" style="background: <?php echo gradeClass($combinedScore['grade']); ?>"><?php echo $combinedScore['grade']; ?></div>
                                    <div class="scored-date"><?php echo $combinedScore['source_count']; ?> <?php echo e(t('vendor-onboarding.sources_suffix')); ?></div>
                                    <?php else: ?>
                                    <div class="proc-placeholder"><?php echo e(t('vendor-onboarding.no_scores_available')); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- PAGE BREAK -->
                            <div class="proc-page-break"></div>

                            <!-- NIST CSF 2.0 Analysis -->
                            <h3 class="proc-section-title"><?php echo e(t('vendor-onboarding.section_nist')); ?></h3>
                            <div class="proc-chart-row proc-avoid-break">
                                <div class="proc-chart-half">
                                    <canvas id="procNistRadarChart"></canvas>
                                </div>
                                <div class="proc-chart-half">
                                    <table class="proc-table">
                                        <thead><tr><th><?php echo e(t('vendor-onboarding.th_nist_function')); ?></th><th><?php echo e(t('vendor-onboarding.score_suffix')); ?></th><th><?php echo e(t('vendor-onboarding.th_key_data_sources')); ?></th></tr></thead>
                                        <tbody>
                                            <tr>
                                                <td><strong><?php echo e(t('vendor-onboarding.nist_identify')); ?></strong></td>
                                                <td><div class="proc-score-bar"><span><?php echo $nistScores['Identify']; ?></span><div class="proc-score-bar-bg"><div class="proc-score-bar-fill" style="width: <?php echo $nistScores['Identify']; ?>%"></div></div></div></td>
                                                <td>Techs: <?php echo count($technologies); ?>, FAIR: <?php echo $fairAnalysis ? 'Yes' : 'No'; ?>, Tier: <?php echo $request['vendor_tier'] ?? 'N/A'; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong><?php echo e(t('vendor-onboarding.nist_protect')); ?></strong></td>
                                                <td><div class="proc-score-bar"><span><?php echo $nistScores['Protect']; ?></span><div class="proc-score-bar-bg"><div class="proc-score-bar-fill" style="width: <?php echo $nistScores['Protect']; ?>%"></div></div></div></td>
                                                <td><?php echo e(t('vendor-onboarding.nist_protect_sources')); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong><?php echo e(t('vendor-onboarding.nist_detect')); ?></strong></td>
                                                <td><div class="proc-score-bar"><span><?php echo $nistScores['Detect']; ?></span><div class="proc-score-bar-bg"><div class="proc-score-bar-fill" style="width: <?php echo $nistScores['Detect']; ?>%"></div></div></div></td>
                                                <td><?php echo e(t('vendor-onboarding.nist_detect_sources')); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong><?php echo e(t('vendor-onboarding.nist_respond')); ?></strong></td>
                                                <td><div class="proc-score-bar"><span><?php echo $nistScores['Respond']; ?></span><div class="proc-score-bar-bg"><div class="proc-score-bar-fill" style="width: <?php echo $nistScores['Respond']; ?>%"></div></div></div></td>
                                                <td>Assessments: <?php echo $assessmentCount; ?>, Governance: <?php echo $govAnswered > 0 ? 'Yes' : 'No'; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong><?php echo e(t('vendor-onboarding.nist_recover')); ?></strong></td>
                                                <td><div class="proc-score-bar"><span><?php echo $nistScores['Recover']; ?></span><div class="proc-score-bar-bg"><div class="proc-score-bar-fill" style="width: <?php echo $nistScores['Recover']; ?>%"></div></div></div></td>
                                                <td>Insurance: <?php echo $fairAnalysis && !empty($fairAnalysis['vendor_cyber_insurance_coverage']) ? 'Yes' : 'No'; ?>, BC Plan: <?php echo $fairAnalysis ? 'Yes' : 'N/A'; ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Score Trend -->
                            <?php if (!empty($trendLabels)): ?>
                            <div class="proc-avoid-break">
                                <h4 class="proc-section-title-sm"><?php echo e(t('vendor-onboarding.section_score_trend')); ?></h4>
                                <div class="proc-chart-container">
                                    <canvas id="procTrendChart"></canvas>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Risk Severity Distribution -->
                            <?php if (array_sum($severityCounts) > 0): ?>
                            <div class="proc-chart-row proc-avoid-break">
                                <div class="proc-chart-half">
                                    <h4 class="proc-section-title-sm"><?php echo e(t('vendor-onboarding.section_severity_dist')); ?></h4>
                                    <canvas id="procSeverityChart"></canvas>
                                </div>
                                <div class="proc-chart-half">
                                    <h4 class="proc-section-title-sm"><?php echo e(t('vendor-onboarding.section_severity_breakdown')); ?></h4>
                                    <table class="proc-table">
                                        <thead><tr><th><?php echo e(t('vendor-onboarding.th_severity')); ?></th><th><?php echo e(t('vendor-onboarding.th_count')); ?></th><th><?php echo e(t('vendor-onboarding.th_percentage')); ?></th></tr></thead>
                                        <tbody>
                                            <?php
                                            $totalRisks = array_sum($severityCounts);
                                            foreach ($severityCounts as $sev => $count):
                                                $pct = $totalRisks > 0 ? round(($count / $totalRisks) * 100, 1) : 0;
                                            ?>
                                            <tr>
                                                <td><span class="severity-<?php echo $sev; ?>"><?php echo ucfirst($sev); ?></span></td>
                                                <td><?php echo $count; ?></td>
                                                <td><?php echo $pct; ?>%</td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr style="font-weight: 700; border-top: 2px solid #d1d5db;">
                                                <td><?php echo e(t('vendor-onboarding.label_total')); ?></td>
                                                <td><?php echo $totalRisks; ?></td>
                                                <td>100%</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Commentary placeholder (populated by Generate Commentary) -->
                            <div id="procCommentaryPlaceholder"></div>

                            <!-- Risk Findings Summary -->
                            <h3 class="proc-section-title"><?php echo e(t('vendor-onboarding.section_risk_findings')); ?></h3>

                            <?php if (!empty($topRisks)): ?>
                            <div class="proc-avoid-break">
                                <h4 class="proc-section-title-sm"><?php echo t('vendor-onboarding.top_critical_high_prefix'); ?> <?php echo e($upguardDisplayName); ?> <?php echo e(t('vendor-onboarding.risks_suffix')); ?></h4>
                                <table class="proc-table">
                                    <thead><tr><th><?php echo e(t('vendor-onboarding.th_severity')); ?></th><th><?php echo e(t('vendor-onboarding.risk_suffix')); ?></th><th><?php echo e(t('vendor-onboarding.label_description')); ?></th></tr></thead>
                                    <tbody>
                                        <?php foreach (array_slice($topRisks, 0, 10) as $r): ?>
                                        <tr>
                                            <td><span class="severity-<?php echo e($r['severity'] ?? 'info'); ?>"><?php echo ucfirst(e($r['severity'] ?? 'Info')); ?></span></td>
                                            <td><strong><?php echo e($r['risk_name'] ?? 'Unknown'); ?></strong></td>
                                            <td><?php echo e(mb_substr($r['description'] ?? '', 0, 120)); ?><?php echo strlen($r['description'] ?? '') > 120 ? '...' : ''; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($negativeSignals)): ?>
                            <div class="proc-avoid-break">
                                <h4 class="proc-section-title-sm"><?php echo e(t('vendor-onboarding.top_negative_prefix')); ?> <?php echo e($shodanDisplayName); ?> <?php echo e(t('vendor-onboarding.signals_suffix')); ?></h4>
                                <table class="proc-table">
                                    <thead><tr><th><?php echo e(t('vendor-onboarding.grip_category')); ?></th><th><?php echo e(t('vendor-onboarding.th_signal')); ?></th><th><?php echo e(t('vendor-onboarding.th_points')); ?></th></tr></thead>
                                    <tbody>
                                        <?php foreach (array_slice($negativeSignals, 0, 10) as $f): ?>
                                        <tr>
                                            <td><?php echo e(ucwords(str_replace('_', ' ', $f['category'] ?? 'General'))); ?></td>
                                            <td><?php echo e($f['description'] ?? $f['service_name'] ?? 'Unknown'); ?></td>
                                            <td><?php echo (int)($f['points'] ?? 0); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($highCVEs)): ?>
                            <div class="proc-avoid-break">
                                <h4 class="proc-section-title-sm"><?php echo t('vendor-onboarding.section_high_cves'); ?></h4>
                                <table class="proc-table">
                                    <thead><tr><th><?php echo e(t('vendor-onboarding.th_cve_id')); ?></th><th><?php echo e(t('vendor-onboarding.th_cvss')); ?></th><th><?php echo e(t('vendor-onboarding.th_service')); ?></th><th><?php echo e(t('vendor-onboarding.label_description')); ?></th></tr></thead>
                                    <tbody>
                                        <?php foreach (array_slice($highCVEs, 0, 10) as $c): ?>
                                        <tr>
                                            <td><strong><?php echo e($c['cve_id']); ?></strong></td>
                                            <td><span class="severity-<?php echo ($c['cvss_score'] ?? 0) >= 9 ? 'critical' : 'high'; ?>"><?php echo number_format((float)($c['cvss_score'] ?? 0), 1); ?></span></td>
                                            <td><?php echo e($c['service_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo e(mb_substr($c['description'] ?? '', 0, 100)); ?><?php echo strlen($c['description'] ?? '') > 100 ? '...' : ''; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <?php if (empty($topRisks) && empty($negativeSignals) && empty($highCVEs)): ?>
                            <div class="proc-placeholder"><?php echo e(t('vendor-onboarding.no_risk_findings')); ?></div>
                            <?php endif; ?>

                            <!-- Financial Impact (FAIR) -->
                            <div class="proc-page-break"></div>
                            <h3 class="proc-section-title"><?php echo e(t('vendor-onboarding.section_financial_impact')); ?></h3>
                            <?php if ($fairAnalysis): ?>
                            <div class="proc-avoid-break">
                                <div class="proc-fair-grid">
                                    <div class="proc-fair-metric">
                                        <div class="label"><?php echo e(t('vendor-onboarding.label_risk_level')); ?></div>
                                        <div class="value"><?php echo e($fairAnalysis['risk_output'] ?? 'N/A'); ?></div>
                                        <div class="desc"><?php echo e(t('vendor-onboarding.desc_fair_risk_output')); ?></div>
                                    </div>
                                    <div class="proc-fair-metric">
                                        <div class="label"><?php echo e(t('vendor-onboarding.label_ale')); ?></div>
                                        <div class="value"><?php echo !empty($fairAnalysis['ale']) ? '$' . number_format((float)$fairAnalysis['ale'], 0) : 'N/A'; ?></div>
                                        <div class="desc"><?php echo e(t('vendor-onboarding.desc_ale')); ?></div>
                                    </div>
                                    <div class="proc-fair-metric">
                                        <div class="label"><?php echo e(t('vendor-onboarding.label_lef')); ?></div>
                                        <div class="value"><?php echo !empty($fairAnalysis['loss_event_frequency']) ? $fairAnalysis['loss_event_frequency'] . t('vendor-onboarding.events_yr_suffix') : 'N/A'; ?></div>
                                        <div class="desc"><?php echo e(t('vendor-onboarding.desc_lef')); ?></div>
                                    </div>
                                    <div class="proc-fair-metric">
                                        <div class="label"><?php echo e(t('vendor-onboarding.label_plm')); ?></div>
                                        <div class="value"><?php echo !empty($fairAnalysis['primary_loss_magnitude']) ? '$' . number_format((float)$fairAnalysis['primary_loss_magnitude'], 0) : 'N/A'; ?></div>
                                        <div class="desc"><?php echo e(t('vendor-onboarding.desc_plm')); ?></div>
                                    </div>
                                    <div class="proc-fair-metric">
                                        <div class="label"><?php echo e(t('vendor-onboarding.label_slm')); ?></div>
                                        <div class="value"><?php echo !empty($fairAnalysis['secondary_loss_magnitude']) ? '$' . number_format((float)$fairAnalysis['secondary_loss_magnitude'], 0) : 'N/A'; ?></div>
                                        <div class="desc"><?php echo e(t('vendor-onboarding.desc_slm')); ?></div>
                                    </div>
                                    <div class="proc-fair-metric">
                                        <div class="label"><?php echo e(t('vendor-onboarding.label_rec_insurance')); ?></div>
                                        <div class="value"><?php echo !empty($fairAnalysis['recommended_liability']) ? '$' . number_format((float)$fairAnalysis['recommended_liability'], 0) : 'N/A'; ?></div>
                                        <div class="desc"><?php echo e(t('vendor-onboarding.desc_rec_insurance')); ?></div>
                                    </div>
                                </div>
                                <?php
                                $recIns = (float)($fairAnalysis['recommended_liability'] ?? 0);
                                $actIns = (float)($fairAnalysis['vendor_cyber_insurance_coverage'] ?? 0);
                                if ($recIns > 0):
                                    $gap = $recIns - $actIns;
                                    $gapPct = round(($gap / $recIns) * 100);
                                ?>
                                <div style="margin-top: 10px; padding: 10px; background: <?php echo $gap > 0 ? '#fef2f2' : '#f0fdf4'; ?>; border-radius: 6px; border: 1px solid <?php echo $gap > 0 ? '#fecaca' : '#bbf7d0'; ?>;">
                                    <strong><?php echo e(t('vendor-onboarding.label_gap_analysis')); ?></strong>
                                    <?php echo e(t('vendor-onboarding.gap_current_prefix')); ?> $<?php echo number_format($actIns, 0); ?> |
                                    <?php echo e(t('vendor-onboarding.gap_recommended_prefix')); ?> $<?php echo number_format($recIns, 0); ?>
                                    <?php if ($gap > 0): ?>
                                    | <span style="color: #dc2626; font-weight: 700;"><?php echo e(t('vendor-onboarding.gap_prefix')); ?> $<?php echo number_format($gap, 0); ?> (<?php echo $gapPct; ?>% <?php echo e(t('vendor-onboarding.undercovered_suffix')); ?>)</span>
                                    <?php else: ?>
                                    | <span style="color: #16a34a; font-weight: 700;"><?php echo e(t('vendor-onboarding.adequately_covered')); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="proc-placeholder"><?php echo e(t('vendor-onboarding.no_fair_completed')); ?></div>
                            <?php endif; ?>

                            <!-- Vendor Provided Assessment Overview -->
                            <?php if (!empty($certifications) || !empty($assessmentMeta) || !empty($shCategoryScores)): ?>
                            <div style="page-break-before: always;"></div>
                            <div class="proc-avoid-break">
                                <h3 class="proc-section-title"><?php echo e(t('vendor-onboarding.section_vendor_assessment_overview')); ?></h3>

                                <?php if (!empty($certifications)): ?>
                                <h4 class="proc-section-title-sm"><?php echo e(t('vendor-onboarding.section_active_certs')); ?></h4>
                                <table class="proc-table">
                                    <thead><tr><th><?php echo e(t('vendor-onboarding.th_certification')); ?></th><th><?php echo e(t('vendor-onboarding.th_expiration')); ?></th><th><?php echo e(t('vendor-onboarding.label_status')); ?></th><th><?php echo e(t('vendor-onboarding.th_document')); ?></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($certifications as $cert):
                                            $expDate = $cert['certification_expiration_date'] ?? null;
                                            $certStatus = t('vendor-onboarding.unknown'); $certColor = '#6b7280';
                                            if ($expDate) {
                                                $expTs = strtotime($expDate); $now = time(); $daysLeft = ($expTs - $now) / 86400;
                                                if ($daysLeft < 0) { $certStatus = t('vendor-onboarding.cert_expired'); $certColor = '#dc2626'; }
                                                elseif ($daysLeft < 30) { $certStatus = t('vendor-onboarding.cert_expiring_soon'); $certColor = '#d97706'; }
                                                else { $certStatus = t('vendor-onboarding.cert_valid'); $certColor = '#16a34a'; }
                                            }
                                        ?>
                                        <tr>
                                            <td><?php echo e($cert['certification_type'] ?? 'N/A'); ?></td>
                                            <td><?php echo $expDate ? date('M j, Y', strtotime($expDate)) : 'N/A'; ?></td>
                                            <td><span style="color: <?php echo $certColor; ?>; font-weight: 600;"><?php echo $certStatus; ?></span></td>
                                            <td><?php echo e($cert['original_filename'] ?? '—'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>

                                <?php if (!empty($assessmentMeta)): ?>
                                <h4 class="proc-section-title-sm" style="margin-top: 18px;"><?php echo e(t('vendor-onboarding.section_assessment_completion')); ?></h4>
                                <table class="proc-table">
                                    <thead><tr><th><?php echo e(t('vendor-onboarding.th_assessment')); ?></th><th><?php echo e(t('vendor-onboarding.th_completed')); ?></th><th><?php echo e(t('vendor-onboarding.th_sections')); ?></th><th><?php echo e(t('vendor-onboarding.th_questions_answered')); ?></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($assessmentMeta as $am): ?>
                                        <tr>
                                            <td><?php echo e($am['template_name']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($am['completed_at'])); ?></td>
                                            <td><?php echo (int)$am['sections']; ?></td>
                                            <td><?php echo (int)$am['answered']; ?> / <?php echo (int)$am['total']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>

                                <?php
                                // Vendor Claims vs. External Evidence (Reality Check Matrix)
                                $domainLabels = [
                                    'tls_crypto'       => t('vendor-onboarding.dl_encryption_tls'),
                                    'network_security' => t('vendor-onboarding.cat_network_security'),
                                    'app_hardening'    => t('vendor-onboarding.dl_app_hardening'),
                                    'email_security'   => t('vendor-onboarding.cat_email_security'),
                                    'vuln_exposure'    => t('vendor-onboarding.dl_vuln_management'),
                                ];
                                $domainEvidence = [];
                                if (!empty($shodanFindings)) {
                                    foreach ($shodanFindings as $f) {
                                        $cat = $f['category'] ?? $f['finding_type'] ?? '';
                                        if (!isset($domainLabels[$cat])) continue;
                                        $type = $f['signal_type'] ?? '';
                                        if (!isset($domainEvidence[$cat])) {
                                            $domainEvidence[$cat] = ['positive' => 0, 'negative' => 0, 'details' => []];
                                        }
                                        if ($type === 'positive') { $domainEvidence[$cat]['positive']++; }
                                        elseif ($type === 'negative') {
                                            $domainEvidence[$cat]['negative']++;
                                            if (count($domainEvidence[$cat]['details']) < 3) {
                                                $detail = trim($f['description'] ?? $f['service_name'] ?? '');
                                                if ($detail !== '') $domainEvidence[$cat]['details'][] = mb_substr($detail, 0, 80);
                                            }
                                        }
                                    }
                                }
                                $ugRiskSummary = '';
                                if (!empty($risks)) {
                                    $sevCounts = ['critical' => 0, 'high' => 0];
                                    foreach ($risks as $r) {
                                        $sev = strtolower($r['severity'] ?? '');
                                        if (isset($sevCounts[$sev])) $sevCounts[$sev]++;
                                    }
                                    $parts = [];
                                    if ($sevCounts['critical'] > 0) $parts[] = $sevCounts['critical'] . ' critical';
                                    if ($sevCounts['high'] > 0) $parts[] = $sevCounts['high'] . ' high';
                                    if (!empty($parts)) $ugRiskSummary = $upguardDisplayName . ': ' . implode(', ', $parts) . ' risks';
                                }
                                $certValid = 0; $certExpired = 0; $certTypes = [];
                                foreach ($certifications as $cert) {
                                    $certTypes[] = $cert['certification_type'] ?? 'Unknown';
                                    $expDate = $cert['certification_expiration_date'] ?? null;
                                    if ($expDate && strtotime($expDate) < time()) { $certExpired++; } else { $certValid++; }
                                }
                                $hasExternalData = !empty($shCategoryScores) || !empty($risks);
                                $hasAnyClaims = !empty($vendorClaims) || !empty($assessmentMeta);
                                ?>

                                <?php if ($hasExternalData || $hasAnyClaims): ?>
                                <h4 class="proc-section-title-sm" style="margin-top: 18px;"><?php echo e(t('vendor-onboarding.section_claims_vs_evidence')); ?></h4>
                                <table class="proc-table" style="font-size: 0.85em;">
                                    <thead>
                                        <tr>
                                            <th style="width: 16%;"><?php echo e(t('vendor-onboarding.th_security_domain')); ?></th>
                                            <th style="width: 10%; text-align: center;"><?php echo e(t('vendor-onboarding.score_suffix')); ?></th>
                                            <th style="width: 26%;"><?php echo e(t('vendor-onboarding.th_external_evidence')); ?></th>
                                            <th style="width: 28%;"><?php echo e(t('vendor-onboarding.th_vendor_claim')); ?></th>
                                            <th style="width: 20%; text-align: center;"><?php echo e(t('vendor-onboarding.th_assessment')); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($domainLabels as $domainKey => $domainLabel):
                                            $score = isset($shCategoryScores[$domainKey]) ? (int)$shCategoryScores[$domainKey] : null;
                                            $evidenceText = '';
                                            if (isset($domainEvidence[$domainKey])) {
                                                $ev = $domainEvidence[$domainKey];
                                                $evidenceParts = [];
                                                if ($ev['negative'] > 0) $evidenceParts[] = $ev['negative'] . ' negative signal' . ($ev['negative'] > 1 ? 's' : '');
                                                if ($ev['positive'] > 0) $evidenceParts[] = $ev['positive'] . ' positive signal' . ($ev['positive'] > 1 ? 's' : '');
                                                $evidenceText = $shodanDisplayName . ': ' . implode(', ', $evidenceParts);
                                                if (!empty($ev['details'])) $evidenceText .= ' — ' . implode('; ', $ev['details']);
                                            }
                                            if ($domainKey === 'vuln_exposure' && !empty($ugRiskSummary)) {
                                                $evidenceText = $evidenceText ? $evidenceText . '. ' . $ugRiskSummary : $ugRiskSummary;
                                            }
                                            if (empty($evidenceText) && $score !== null) {
                                                $evidenceText = $shodanDisplayName . ': Score ' . $score . '/100';
                                            }
                                            $claimText = '(No response)';
                                            $hasPositiveClaim = false;
                                            $isNegative = false;
                                            if (isset($vendorClaims[$domainKey])) {
                                                $claim = $vendorClaims[$domainKey];
                                                $claimParts = [];
                                                if (!empty($claim['answer'])) $claimParts[] = '[Assessment] ' . $claim['answer'];
                                                if (!empty($claim['vsm_answer'])) {
                                                    $vsmLabel = !empty($claim['vsm_source']) ? $claim['vsm_source'] : 'Document';
                                                    $claimParts[] = '[VSM] "' . $claim['vsm_answer'] . '" (' . $vsmLabel . ')';
                                                }
                                                $claimText = !empty($claimParts) ? implode("\n", $claimParts) : '(No response)';
                                                $assessAnswer = strtolower($claim['answer'] ?? '');
                                                $negativeIndicators = ['no', 'n/a', 'none', 'not applicable', 'not implemented', 'false'];
                                                $isNegative = in_array($assessAnswer, $negativeIndicators);
                                                $hasPositiveClaim = (!$isNegative && $assessAnswer !== '' && $assessAnswer !== '(no response)') || !empty($claim['vsm_answer']);
                                            }
                                            $negCount = isset($domainEvidence[$domainKey]) ? $domainEvidence[$domainKey]['negative'] : 0;
                                            $alignIcon = '— No Data'; $alignColor = '#6b7280';
                                            if ($score !== null && isset($vendorClaims[$domainKey])) {
                                                if ($score < 30 && $hasPositiveClaim) { $alignIcon = '&#10007; Contradiction'; $alignColor = '#dc2626'; }
                                                elseif (($score < 50 || $negCount >= 3) && $hasPositiveClaim) { $alignIcon = '&#9888; Discrepancy'; $alignColor = '#d97706'; }
                                                elseif ($score >= 70 && $hasPositiveClaim) { $alignIcon = '&#10003; Aligned'; $alignColor = '#16a34a'; }
                                                elseif ($hasPositiveClaim) { $alignIcon = '&#9888; Partial'; $alignColor = '#d97706'; }
                                                elseif ($isNegative ?? false) { $alignIcon = '&#10003; Acknowledged'; $alignColor = '#6b7280'; }
                                                else { $alignIcon = '— Review'; $alignColor = '#6b7280'; }
                                            } elseif ($score !== null && !isset($vendorClaims[$domainKey])) {
                                                $alignIcon = '— Unverified'; $alignColor = '#6b7280';
                                            }
                                        ?>
                                        <tr>
                                            <td style="font-weight: 600;"><?php echo $domainLabel; ?></td>
                                            <td style="text-align: center;">
                                                <?php if ($score !== null): ?>
                                                <span style="font-weight: 700; color: <?php echo $score >= 70 ? '#16a34a' : ($score >= 50 ? '#d97706' : '#dc2626'); ?>;"><?php echo $score; ?>/100</span>
                                                <?php else: ?>
                                                <span style="color: #9ca3af;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size: 0.9em; color: #374151;"><?php echo !empty($evidenceText) ? e($evidenceText) : '<span style="color:#9ca3af;">' . e(t('vendor-onboarding.no_data')) . '</span>'; ?></td>
                                            <td style="font-size: 0.9em;"><?php echo nl2br(e($claimText)); ?></td>
                                            <td style="text-align: center; font-weight: 600; color: <?php echo $alignColor; ?>;"><?php echo $alignIcon; ?></td>
                                        </tr>
                                        <?php endforeach; ?>

                                        <!-- Certifications row -->
                                        <tr>
                                            <td style="font-weight: 600;"><?php echo e(t('vendor-onboarding.label_certifications')); ?></td>
                                            <td style="text-align: center;"><span style="color: #9ca3af;">—</span></td>
                                            <td style="font-size: 0.9em; color: #374151;">
                                                <?php if (!empty($certifications)):
                                                    $certParts = [];
                                                    if ($certValid > 0) $certParts[] = $certValid . ' valid';
                                                    if ($certExpired > 0) $certParts[] = $certExpired . ' expired';
                                                    echo e(implode(', ', $certParts) . ' on file');
                                                else: ?>
                                                <span style="color:#9ca3af;"><?php echo e(t('vendor-onboarding.none_on_file')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size: 0.9em;">
                                                <?php
                                                $certClaimParts = [];
                                                if (isset($vendorClaims['certifications'])) {
                                                    if (!empty($vendorClaims['certifications']['answer'])) $certClaimParts[] = '[Assessment] ' . $vendorClaims['certifications']['answer'];
                                                    if (!empty($vendorClaims['certifications']['vsm_answer'])) {
                                                        $vsmSrc = $vendorClaims['certifications']['vsm_source'] ?? 'Document';
                                                        $certClaimParts[] = '[VSM] "' . $vendorClaims['certifications']['vsm_answer'] . '" (' . $vsmSrc . ')';
                                                    }
                                                }
                                                if (!empty($certClaimParts)):
                                                    echo nl2br(e(implode("\n", $certClaimParts)));
                                                elseif (!empty($certTypes)):
                                                    echo e(implode(', ', array_slice($certTypes, 0, 3)) . ' provided');
                                                else: ?>
                                                (No response)
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: center; font-weight: 600; color: <?php
                                                if (!empty($certifications) && $certExpired > 0 && $certValid > 0) { echo '#d97706;">&#9888; Partial'; }
                                                elseif (!empty($certifications) && $certExpired === 0) { echo '#16a34a;">&#10003; Aligned'; }
                                                elseif (!empty($certifications) && $certValid === 0) { echo '#dc2626;">&#10007; Expired'; }
                                                else { echo '#6b7280;">— No Data'; }
                                            ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div style="margin-top: 6px; font-size: 0.8em; color: #6b7280;">
                                    <strong>Legend:</strong>
                                    <span style="color: #16a34a;">&#10003; Aligned</span> = Score &ge; 70 &amp; positive claim |
                                    <span style="color: #d97706;">&#9888; Discrepancy</span> = Score &lt; 50 &amp; positive claim |
                                    <span style="color: #dc2626;">&#10007; Contradiction</span> = Score &lt; 30 &amp; positive claim |
                                    <span style="color: #6b7280;">— Unverified</span> = No vendor response
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Category Scores (Horizontal Bars) -->
                            <?php if (!empty($ugCategoryScores) || !empty($shCategoryScores)): ?>
                            <div class="proc-avoid-break">
                                <h3 class="proc-section-title"><?php echo e(t('vendor-onboarding.category_scores_heading')); ?></h3>
                                <div class="proc-chart-container">
                                    <canvas id="procCategoryChart"></canvas>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Technology Inventory -->
                            <?php if (!empty($technologies)): ?>
                            <div class="proc-avoid-break">
                                <h3 class="proc-section-title"><?php echo e(t('vendor-onboarding.section_tech_inventory')); ?></h3>
                                <?php
                                $techByCategory = [];
                                foreach (array_slice($technologies, 0, 20) as $tech) {
                                    $cat = $tech['technology_category'] ?? 'other';
                                    $techByCategory[$cat][] = $tech;
                                }
                                ?>
                                <div class="proc-tech-grid">
                                    <?php foreach ($techByCategory as $cat => $techs): ?>
                                    <div>
                                        <div class="proc-tech-category"><?php echo e(ucwords(str_replace('_', ' ', $cat))); ?></div>
                                        <?php foreach ($techs as $t): ?>
                                        <div class="proc-tech-item"><?php echo e($t['technology_name']); ?><?php echo !empty($t['technology_version']) ? ' <span style="color:#9ca3af;">v' . e($t['technology_version']) . '</span>' : ''; ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Footer -->
                            <div class="proc-report-footer">
                                <?php echo e(t('vendor-onboarding.footer_confidential_prefix')); ?> <?php echo e($reportDate); ?> <?php echo e(t('vendor-onboarding.footer_generated_suffix')); ?><br>
                                <?php echo e(t('vendor-onboarding.footer_nist_disclaimer')); ?>
                            </div>

                        </div><!-- /.proc-container -->
                    </div><!-- /.proc-report -->
                <?php endif; ?>
                </div>

                <!-- Tab: Documents -->
                <div id="vendorTabContent_documents" style="<?php echo $activeTab !== 'documents' ? 'display: none;' : ''; ?>">
                    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 25px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;"><?php echo e(t('vendor-onboarding.tab_documents')); ?></h3>
                            <label for="docFileInput" style="padding: 8px 16px; background: var(--theme-button-color, #35a0a3); color: white; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer;">
                                <?php echo e(t('vendor-onboarding.btn_upload_document')); ?>
                            </label>
                            <input type="file" id="docFileInput" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.csv,.xls,.xlsx" style="display: none;">
                        </div>

                        <?php if (empty($vendorDocuments)): ?>
                        <div style="padding: 40px 0; text-align: center;">
                            <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.3;">&#128196;</div>
                            <p style="color: #999; font-size: 14px; margin: 0;"><?php echo e(t('vendor-onboarding.no_documents')); ?></p>
                        </div>
                        <?php else: ?>
                        <?php
                            $docCounts = ['all' => count($vendorDocuments), 'contract' => 0, 'certification' => 0, 'other' => 0, 'active' => 0, 'inactive' => 0, 'expired' => 0];
                            foreach ($vendorDocuments as $d) {
                                $docCounts[$d['document_type']]++;
                                if (!empty($d['is_active'])) { $docCounts['active']++; } else { $docCounts['inactive']++; }
                                $cExp = $d['document_type'] === 'contract' && !empty($d['contract_expiration_date']) && strtotime($d['contract_expiration_date']) < time();
                                $certExp = $d['document_type'] === 'certification' && !empty($d['certification_expiration_date']) && strtotime($d['certification_expiration_date']) < time();
                                if ($cExp || $certExp) { $docCounts['expired']++; }
                            }
                        ?>
                        <div id="docFilterBar" style="display: flex; gap: 6px; margin-bottom: 16px; flex-wrap: wrap;">
                            <button type="button" class="doc-filter-btn doc-filter-active" data-filter="all" style="padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 16px; font-size: 12px; cursor: pointer; font-weight: 500; background: #374151; color: white;"><?php echo e(t('vendor-onboarding.filter_all')); ?> (<?php echo $docCounts['all']; ?>)</button>
                            <button type="button" class="doc-filter-btn" data-filter="contract" style="padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 16px; font-size: 12px; cursor: pointer; font-weight: 500; background: white; color: #374151;"><?php echo e(t('vendor-onboarding.filter_contract')); ?> (<?php echo $docCounts['contract']; ?>)</button>
                            <?php if (!$isProcurementOnly): ?>
                            <button type="button" class="doc-filter-btn" data-filter="certification" style="padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 16px; font-size: 12px; cursor: pointer; font-weight: 500; background: white; color: #374151;"><?php echo e(t('vendor-onboarding.th_certification')); ?> (<?php echo $docCounts['certification']; ?>)</button>
                            <button type="button" class="doc-filter-btn" data-filter="other" style="padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 16px; font-size: 12px; cursor: pointer; font-weight: 500; background: white; color: #374151;"><?php echo e(t('vendor-onboarding.vtype_other')); ?> (<?php echo $docCounts['other']; ?>)</button>
                            <?php endif; ?>
                            <span style="border-left: 1px solid #e5e7eb; margin: 0 4px;"></span>
                            <button type="button" class="doc-filter-btn" data-filter="active" style="padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 16px; font-size: 12px; cursor: pointer; font-weight: 500; background: white; color: #374151;"><?php echo e(t('vendor-onboarding.label_active')); ?> (<?php echo $docCounts['active']; ?>)</button>
                            <button type="button" class="doc-filter-btn" data-filter="inactive" style="padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 16px; font-size: 12px; cursor: pointer; font-weight: 500; background: white; color: #374151;"><?php echo e(t('vendor-onboarding.label_inactive')); ?> (<?php echo $docCounts['inactive']; ?>)</button>
                            <?php if ($docCounts['expired'] > 0): ?>
                            <button type="button" class="doc-filter-btn" data-filter="expired" style="padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 16px; font-size: 12px; cursor: pointer; font-weight: 500; background: white; color: #374151;"><?php echo e(t('vendor-onboarding.cert_expired')); ?> (<?php echo $docCounts['expired']; ?>)</button>
                            <?php endif; ?>
                        </div>
                        <div id="docNoResults" style="display: none; padding: 30px 0; text-align: center; color: #999; font-size: 14px;"><?php echo e(t('vendor-onboarding.no_documents_match')); ?></div>
                        <div id="docCardList" style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach ($vendorDocuments as $doc):
                                $borderColor = '#9ca3af';
                                $typeBadgeBg = '#f3f4f6'; $typeBadgeColor = '#374151';
                                if ($doc['document_type'] === 'contract') {
                                    $borderColor = '#3b82f6'; $typeBadgeBg = '#dbeafe'; $typeBadgeColor = '#1e40af';
                                } elseif ($doc['document_type'] === 'certification') {
                                    $borderColor = '#22c55e'; $typeBadgeBg = '#dcfce7'; $typeBadgeColor = '#166534';
                                }

                                $docTitle = $doc['original_filename'];
                                if ($doc['document_type'] === 'contract' && !empty($doc['contract_name'])) {
                                    $docTitle = $doc['contract_name'];
                                } elseif ($doc['document_type'] === 'certification' && !empty($doc['certification_type'])) {
                                    $docTitle = $doc['certification_type'];
                                }

                                $fileSize = $doc['file_size'];
                                if ($fileSize >= 1048576) {
                                    $fileSizeStr = round($fileSize / 1048576, 1) . ' MB';
                                } elseif ($fileSize >= 1024) {
                                    $fileSizeStr = round($fileSize / 1024, 1) . ' KB';
                                } else {
                                    $fileSizeStr = $fileSize . ' B';
                                }
                            ?>
                            <?php
                                $isExpired = false;
                                if ($doc['document_type'] === 'contract' && !empty($doc['contract_expiration_date']) && strtotime($doc['contract_expiration_date']) < time()) $isExpired = true;
                                if ($doc['document_type'] === 'certification' && !empty($doc['certification_expiration_date']) && strtotime($doc['certification_expiration_date']) < time()) $isExpired = true;
                            ?>
                            <div class="doc-card" data-doc-type="<?php echo e($doc['document_type']); ?>" data-doc-status="<?php echo !empty($doc['is_active']) ? 'active' : 'inactive'; ?>" data-doc-expired="<?php echo $isExpired ? '1' : '0'; ?>" style="border: 1px solid #e5e7eb; border-left: 4px solid <?php echo $borderColor; ?>; border-radius: 8px; padding: 14px 18px;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                                            <span style="font-weight: 600; font-size: 14px; color: #333;"><?php echo e($docTitle); ?></span>
                                            <span style="padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 500; background: <?php echo $typeBadgeBg; ?>; color: <?php echo $typeBadgeColor; ?>;">
                                                <?php echo ucfirst($doc['document_type']); ?>
                                            </span>
                                            <?php if (!empty($doc['is_active'])): ?>
                                            <span style="padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 500; background: #dcfce7; color: #166534;"><?php echo e(t('vendor-onboarding.label_active')); ?></span>
                                            <?php else: ?>
                                            <span style="padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 500; background: #fef2f2; color: #dc2626;"><?php echo e(t('vendor-onboarding.label_inactive')); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                                            <?php echo e($doc['original_filename']); ?> &middot; <?php echo $fileSizeStr; ?>
                                        </div>
                                        <?php if ($doc['document_type'] === 'contract'): ?>
                                        <div style="font-size: 12px; color: #6b7280;">
                                            <?php echo e($doc['contract_type']); ?>
                                            <?php if (!empty($doc['contract_creation_date'])): ?>
                                                &middot; <?php echo date('M j, Y', strtotime($doc['contract_creation_date'])); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($doc['contract_expiration_date'])): ?>
                                                &ndash; <?php echo date('M j, Y', strtotime($doc['contract_expiration_date'])); ?>
                                                <?php if (strtotime($doc['contract_expiration_date']) < time()): ?>
                                                    <span style="color: #dc2626; font-weight: 500; margin-left: 4px;"><?php echo e(t('vendor-onboarding.cert_expired')); ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php
                                        // Contract Pricing Summary
                                        if (!empty($doc['contract_pricing'])):
                                            $pricing = json_decode($doc['contract_pricing'], true);
                                            if (is_array($pricing)):
                                                $annualFee = floatval($pricing['annualFee'] ?? 0);
                                                $unitPrice = floatval($pricing['unitPrice'] ?? 0);
                                                $includedUnits = intval($pricing['includedUnits'] ?? 0);
                                                $oneTimeFees = floatval($pricing['oneTimeFees'] ?? 0);
                                                $priceIncreaseCap = floatval($pricing['priceIncreaseCap'] ?? 0);
                                                $renewalUplift = ($pricing['renewalUplift'] ?? '') === 'Y';
                                                $unitTotal = $unitPrice * $includedUnits;

                                                // Annual recurring cost: annualFee is the total annual spend.
                                                // Unit pricing is the per-unit breakdown (informational).
                                                // Only fall back to unit math if no annualFee is set.
                                                if ($annualFee > 0) {
                                                    $recurringAnnual = $annualFee;
                                                } elseif ($unitTotal > 0) {
                                                    $recurringAnnual = $unitTotal;
                                                } else {
                                                    $recurringAnnual = 0;
                                                }

                                                // Contract term in years from creation/expiration dates
                                                $termYears = 1;
                                                if (!empty($doc['contract_creation_date']) && !empty($doc['contract_expiration_date'])) {
                                                    $dtStart = new DateTime($doc['contract_creation_date']);
                                                    $dtEnd = new DateTime($doc['contract_expiration_date']);
                                                    $diffDays = max(1, $dtStart->diff($dtEnd)->days);
                                                    $termYears = round($diffDays / 365.25, 1);
                                                    if ($termYears < 0.5) $termYears = 1;
                                                }

                                                $currentTermCost = ($recurringAnnual * $termYears) + $oneTimeFees;
                                                $forecastPerYear = $priceIncreaseCap > 0 ? $recurringAnnual * (1 + $priceIncreaseCap / 100) : $recurringAnnual;
                                                $forecastTerm = $forecastPerYear * $termYears;
                                                $hasCostData = ($annualFee > 0 || $unitTotal > 0 || $oneTimeFees > 0);
                                        ?>
                                        <?php if ($hasCostData): ?>
                                        <div style="margin-top: 8px; padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px;">
                                            <?php if (!empty($pricing['contractId'])): ?>
                                            <div style="color: #64748b; margin-bottom: 4px;"><?php echo e(t('vendor-onboarding.label_contract_id')); ?> <span style="color: #334155; font-weight: 500;"><?php echo e($pricing['contractId']); ?></span>
                                                <?php if (!empty($pricing['systemOfRecordLink'])): ?>
                                                    &middot; <a href="<?php echo e($pricing['systemOfRecordLink']); ?>" target="_blank" rel="noopener" style="color: #2563eb; text-decoration: none;"><?php echo e(t('vendor-onboarding.link_system_of_record')); ?></a>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            <div style="color: #64748b; margin-bottom: 4px;">
                                                <?php if (!empty($pricing['billingFrequency'])): ?>
                                                    <?php echo e($pricing['billingFrequency']); ?> <?php echo e(t('vendor-onboarding.billing_suffix')); ?>
                                                <?php endif; ?>
                                                <?php if ($annualFee > 0): ?>
                                                    &middot; <?php echo e(t('vendor-onboarding.label_annual')); ?> $<?php echo number_format($annualFee, 2); ?>
                                                <?php endif; ?>
                                                <?php if ($oneTimeFees > 0): ?>
                                                    &middot; <?php echo e(t('vendor-onboarding.label_onetime')); ?> $<?php echo number_format($oneTimeFees, 2); ?>
                                                <?php endif; ?>
                                                <?php if ($termYears > 1): ?>
                                                    &middot; <?php echo rtrim(rtrim(number_format($termYears, 1), '0'), '.'); ?><?php echo e(t('vendor-onboarding.year_term_suffix')); ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                                $overagePrice = floatval($pricing['overagePrice'] ?? 0);
                                            if ($unitPrice > 0 || $includedUnits > 0 || $overagePrice > 0): ?>
                                            <div style="color: #475569; margin-bottom: 4px;">
                                                <?php if ($unitPrice > 0): ?>
                                                    <?php echo e(t('vendor-onboarding.label_price_unit')); ?> <span style="font-weight: 600;">$<?php echo number_format($unitPrice, 2); ?></span>
                                                <?php endif; ?>
                                                <?php if ($includedUnits > 0): ?>
                                                    <?php echo $unitPrice > 0 ? '&middot;' : ''; ?> <?php echo e(t('vendor-onboarding.label_units')); ?> <span style="font-weight: 600;"><?php echo number_format($includedUnits); ?></span>
                                                <?php endif; ?>
                                                <?php if ($unitPrice > 0 && $includedUnits > 0): ?>
                                                    &middot; <?php echo e(t('vendor-onboarding.label_unit_total')); ?> <span style="font-weight: 600;">$<?php echo number_format($unitTotal, 2); ?></span>
                                                <?php endif; ?>
                                                <?php if ($overagePrice > 0): ?>
                                                    &middot; <?php echo e(t('vendor-onboarding.label_overage')); ?> <span style="font-weight: 600;">$<?php echo number_format($overagePrice, 2); ?><?php echo e(t('vendor-onboarding.per_unit_suffix')); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            <div style="font-weight: 600; color: #1e293b;">
                                                <?php echo e(t('vendor-onboarding.label_current_term')); ?> $<?php echo number_format($currentTermCost, 2); ?>
                                                <span style="font-weight: 400; font-size: 11px; color: #64748b;">($<?php echo number_format($recurringAnnual, 2); ?><?php echo e(t('vendor-onboarding.per_yr_suffix')); ?>)</span>
                                            </div>
                                            <?php if ($priceIncreaseCap > 0): ?>
                                            <div style="font-weight: 600; color: <?php echo ($forecastPerYear > $recurringAnnual) ? '#d97706' : '#16a34a'; ?>; margin-top: 2px;">
                                                <?php echo e(t('vendor-onboarding.label_forecast_renewal')); ?> $<?php echo number_format($forecastTerm, 2); ?>
                                                <span style="font-weight: 400; font-size: 11px;">($<?php echo number_format($forecastPerYear, 2); ?><?php echo e(t('vendor-onboarding.per_yr_with')); ?> <?php echo rtrim(rtrim(number_format($priceIncreaseCap, 2), '0'), '.'); ?>% <?php echo e(t('vendor-onboarding.cap_suffix')); ?>)</span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($pricing['renewalType'])): ?>
                                            <div style="color: #64748b; margin-top: 4px; font-size: 11px;">
                                                <?php echo e($pricing['renewalType']); ?>
                                                <?php if (!empty($pricing['renewalNoticeWindow'])): ?>
                                                    &middot; <?php echo e(t('vendor-onboarding.label_notice')); ?> <?php echo e($pricing['renewalNoticeWindow']); ?>
                                                <?php endif; ?>
                                                <?php if ($renewalUplift): ?>
                                                    &middot; <span style="color: #d97706;"><?php echo e(t('vendor-onboarding.uplift_clause')); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php
                                            endif;
                                        endif;
                                        ?>
                                        <?php elseif ($doc['document_type'] === 'certification'): ?>
                                        <div style="font-size: 12px; color: #6b7280;">
                                            <?php echo e($doc['certification_type']); ?>
                                            <?php if (!empty($doc['certification_expiration_date'])): ?>
                                                &middot; <?php echo e(t('vendor-onboarding.expires_prefix')); ?> <?php echo date('M j, Y', strtotime($doc['certification_expiration_date'])); ?>
                                                <?php if (strtotime($doc['certification_expiration_date']) < time()): ?>
                                                    <span style="color: #dc2626; font-weight: 500; margin-left: 4px;"><?php echo e(t('vendor-onboarding.cert_expired')); ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php elseif ($doc['document_type'] === 'other' && !empty($doc['description'])): ?>
                                        <div style="font-size: 12px; color: #6b7280;"><?php echo e($doc['description']); ?></div>
                                        <?php endif; ?>
                                        <div style="font-size: 11px; color: #9ca3af; margin-top: 6px;">
                                            <?php echo e(t('vendor-onboarding.uploaded_by_prefix')); ?> <?php echo e($doc['uploaded_by_name'] ?? t('vendor-onboarding.unknown')); ?> <?php echo e(t('vendor-onboarding.on_connector')); ?> <?php echo date('M j, Y g:i A', strtotime($doc['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 8px; align-items: center; flex-shrink: 0;">
                                        <?php if (in_array($doc['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'])): ?>
                                        <a href="api/vendor-document-download.php?uuid=<?php echo urlencode($doc['file_uuid']); ?>&view=1" target="_blank"
                                           style="padding: 6px 12px; background: #eff6ff; color: #1d4ed8; border-radius: 4px; font-size: 12px; text-decoration: none; font-weight: 500;">
                                            <?php echo e(t('vendor-onboarding.btn_view')); ?>
                                        </a>
                                        <?php endif; ?>
                                        <a href="api/vendor-document-download.php?uuid=<?php echo urlencode($doc['file_uuid']); ?>"
                                           style="padding: 6px 12px; background: #f3f4f6; color: #374151; border-radius: 4px; font-size: 12px; text-decoration: none; font-weight: 500;">
                                            <?php echo e(t('vendor-onboarding.btn_download')); ?>
                                        </a>
                                        <button type="button" data-action="toggleDocumentStatus" data-arg="<?php echo $doc['id']; ?>"
                                                style="padding: 6px 12px; background: <?php echo !empty($doc['is_active']) ? '#fefce8' : '#f0fdf4'; ?>; color: <?php echo !empty($doc['is_active']) ? '#a16207' : '#16a34a'; ?>; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 500;">
                                            <?php echo !empty($doc['is_active']) ? t('vendor-onboarding.btn_deactivate') : t('vendor-onboarding.btn_activate'); ?>
                                        </button>
                                        <?php if ($isAdmin || $isCyberTPRM || ($isProcurement && $doc['document_type'] === 'contract')): ?>
                                        <button type="button" data-action="editDocumentMeta" data-arg="<?php echo $doc['id']; ?>"
                                                data-doc-type="<?php echo e($doc['document_type']); ?>"
                                                data-contract-name="<?php echo e($doc['contract_name'] ?? ''); ?>"
                                                data-contract-type="<?php echo e($doc['contract_type'] ?? ''); ?>"
                                                data-contract-creation="<?php echo e($doc['contract_creation_date'] ?? ''); ?>"
                                                data-contract-expiration="<?php echo e($doc['contract_expiration_date'] ?? ''); ?>"
                                                data-cert-type="<?php echo e($doc['certification_type'] ?? ''); ?>"
                                                data-cert-expiration="<?php echo e($doc['certification_expiration_date'] ?? ''); ?>"
                                                data-description="<?php echo e($doc['description'] ?? ''); ?>"
                                                data-contract-pricing="<?php echo e($doc['contract_pricing'] ?? ''); ?>"
                                                data-is-active="<?php echo $doc['is_active'] ? '1' : '0'; ?>"
                                                style="padding: 6px 12px; background: #eff6ff; color: #1d4ed8; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 500;">
                                            <?php echo e(t('vendor-onboarding.btn_edit')); ?>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($isAdmin || $isCyberTPRM || ($isProcurement && $doc['document_type'] === 'contract')): ?>
                                        <button type="button" data-action="deleteDocument" data-arg="<?php echo $doc['id']; ?>,<?php echo e($doc['original_filename']); ?>"
                                                style="padding: 6px 12px; background: #fef2f2; color: #dc2626; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 500;">
                                            <?php echo e(t('vendor-onboarding.btn_delete')); ?>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab: Subprocessors (4th Party Supply Chain) -->
                <div id="vendorTabContent_subprocessors" style="<?php echo ($showVendorTabs && $activeTab !== 'subprocessors') ? 'display: none;' : ''; ?>">
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <div>
                            <h3 style="margin: 0 0 4px 0; font-size: 16px; color: #1f2937;"><?php echo e(t('vendor-onboarding.section_subprocessors')); ?></h3>
                            <p style="margin: 0; font-size: 13px; color: #6b7280;"><?php echo e(t('vendor-onboarding.subprocessors_intro')); ?></p>
                        </div>
                        <?php if ($isAdmin || $isCyberTPRM || $isProcurement): ?>
                        <button type="button" id="addSubprocessorBtn"
                                style="padding: 8px 16px; background: #7c3aed; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap;">
                            <?php echo e(t('vendor-onboarding.btn_add_subprocessor')); ?>
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($vendorSubprocessors)): ?>
                    <div style="text-align: center; padding: 40px 20px; color: #9ca3af;">
                        <p style="font-size: 15px; font-weight: 600; margin: 0 0 6px 0;"><?php echo e(t('vendor-onboarding.no_subprocessors_heading')); ?></p>
                        <p style="font-size: 13px; margin: 0;"><?php echo e(t('vendor-onboarding.no_subprocessors_intro')); ?></p>
                    </div>
                    <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($vendorSubprocessors as $sp): ?>
                        <div style="border: 1px solid #e5e7eb; border-left: 4px solid #7c3aed; border-radius: 8px; padding: 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 200px;">
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
                                        <span style="font-weight: 700; font-size: 15px; color: #1f2937;"><?php echo e($sp['subprocessor_name']); ?></span>
                                        <?php if ((int)$sp['vendor_count'] > 1): ?>
                                        <span style="background: #fef3c7; color: #92400e; font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 600;"><?php echo e(t('vendor-onboarding.shared_by_prefix')); ?> <?php echo (int)$sp['vendor_count']; ?> <?php echo e(t('vendor-onboarding.vendors_suffix')); ?></span>
                                        <?php endif; ?>
                                        <?php if ($sp['linked_vendor_id']): ?>
                                        <a href="vendor-onboarding.php?id=<?php echo (int)$sp['linked_vendor_id']; ?>" style="background: #dbeafe; color: #1d4ed8; font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 600; text-decoration: none;"><?php echo e(t('vendor-onboarding.badge_direct_vendor')); ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($sp['subprocessor_domain']): ?>
                                    <div style="font-size: 13px; color: #6b7280; margin-bottom: 4px;"><?php echo e(t('vendor-onboarding.label_domain')); ?> <?php echo e($sp['subprocessor_domain']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($sp['country']): ?>
                                    <div style="font-size: 13px; color: #6b7280; margin-bottom: 4px;"><?php echo e(t('vendor-onboarding.label_country')); ?> <?php echo e($sp['country']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($sp['service_description']): ?>
                                    <div style="font-size: 13px; color: #374151; margin-top: 6px;"><strong><?php echo e(t('vendor-onboarding.service_prefix')); ?></strong> <?php echo e($sp['service_description']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($sp['data_shared']): ?>
                                    <div style="font-size: 13px; color: #374151; margin-top: 4px;"><strong><?php echo e(t('vendor-onboarding.label_data_shared')); ?></strong> <?php echo e($sp['data_shared']); ?></div>
                                    <?php endif; ?>
                                    <div style="font-size: 11px; color: #9ca3af; margin-top: 6px;">
                                        <?php echo e(t('vendor-onboarding.added_prefix')); ?> <?php echo date('M j, Y', strtotime($sp['created_at'])); ?>
                                        <?php if ($sp['added_by_name']): ?> <?php echo e(t('vendor-onboarding.by_connector')); ?> <?php echo e($sp['added_by_name']); ?><?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($isAdmin || $isCyberTPRM || $isProcurement): ?>
                                <div style="display: flex; gap: 6px; flex-shrink: 0;">
                                    <button type="button" data-action="editSubprocessor" data-arg="<?php echo (int)$sp['mapping_id']; ?>"
                                            data-sp-name="<?php echo e($sp['subprocessor_name']); ?>"
                                            data-sp-domain="<?php echo e($sp['subprocessor_domain'] ?? ''); ?>"
                                            data-sp-country="<?php echo e($sp['country'] ?? ''); ?>"
                                            data-sp-linked="<?php echo (int)($sp['linked_vendor_id'] ?? 0); ?>"
                                            data-sp-linked-name="<?php echo e($sp['linked_vendor_name'] ?? ''); ?>"
                                            data-sp-service="<?php echo e($sp['service_description'] ?? ''); ?>"
                                            data-sp-data="<?php echo e($sp['data_shared'] ?? ''); ?>"
                                            style="padding: 6px 12px; background: #eff6ff; color: #1d4ed8; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 500;">
                                        <?php echo e(t('vendor-onboarding.btn_edit')); ?>
                                    </button>
                                    <button type="button" data-action="removeSubprocessor" data-arg="<?php echo (int)$sp['mapping_id']; ?>,<?php echo e($sp['subprocessor_name']); ?>"
                                            style="padding: 6px 12px; background: #fef2f2; color: #dc2626; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: 500;">
                                        <?php echo e(t('vendor-onboarding.btn_remove')); ?>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                </div>

                <!-- Add Subprocessor Modal -->
                <div id="addSubprocessorModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
                    <div style="background: white; border-radius: 12px; width: 95%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.25);">
                        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; font-size: 18px; color: #1f2937;"><?php echo e(t('vendor-onboarding.modal_add_subprocessor')); ?></h3>
                            <button type="button" class="spModalClose" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                        </div>
                        <div style="padding: 24px;">
                            <div id="addSpError" style="display: none; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px; color: #991b1b;"></div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_subprocessor_name')); ?> *</label>
                                <div style="position: relative;">
                                    <input type="text" id="addSpName" placeholder="<?php echo e(t('vendor-onboarding.placeholder_sp_name')); ?>" autocomplete="off"
                                           style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                                    <div id="addSpNameDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-top: none; border-radius: 0 0 8px 8px; max-height: 200px; overflow-y: auto; z-index: 10; box-shadow: 0 4px 12px rgba(0,0,0,0.1);"></div>
                                </div>
                                <input type="hidden" id="addSpId" value="">
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_domain_plain')); ?></label>
                                <input type="text" id="addSpDomain" placeholder="<?php echo e(t('vendor-onboarding.placeholder_eg_domain')); ?>"
                                       style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_country_plain')); ?></label>
                                <input type="text" id="addSpCountry" placeholder="<?php echo e(t('vendor-onboarding.placeholder_eg_country')); ?>"
                                       style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_linked_vendor_long')); ?></label>
                                <div style="position: relative;">
                                    <input type="text" id="addSpLinkedVendorSearch" placeholder="<?php echo e(t('vendor-onboarding.search_vendors_placeholder')); ?>" autocomplete="off"
                                           style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                                    <div id="addSpLinkedVendorDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-top: none; border-radius: 0 0 8px 8px; max-height: 200px; overflow-y: auto; z-index: 10; box-shadow: 0 4px 12px rgba(0,0,0,0.1);"></div>
                                </div>
                                <input type="hidden" id="addSpLinkedVendorId" value="">
                                <div id="addSpLinkedVendorSelected" style="display: none; margin-top: 6px; padding: 6px 10px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; font-size: 12px; color: #166534;">
                                    <span id="addSpLinkedVendorLabel"></span>
                                    <button type="button" id="addSpLinkedVendorClear" style="float: right; background: none; border: none; color: #991b1b; cursor: pointer; font-size: 11px; padding: 0;"><?php echo e(t('vendor-onboarding.btn_clear')); ?></button>
                                </div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_service_description')); ?></label>
                                <textarea id="addSpService" rows="2" placeholder="<?php echo e(t('vendor-onboarding.placeholder_sp_service')); ?>"
                                          style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; outline: none; resize: vertical; box-sizing: border-box;"></textarea>
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_data_shared_plain')); ?></label>
                                <textarea id="addSpData" rows="2" placeholder="<?php echo e(t('vendor-onboarding.placeholder_sp_data')); ?>"
                                          style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; outline: none; resize: vertical; box-sizing: border-box;"></textarea>
                            </div>

                            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                                <button type="button" class="spModalClose" style="padding: 10px 20px; background: #f3f4f6; color: #374151; border: none; border-radius: 8px; font-size: 14px; cursor: pointer;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                                <button type="button" id="addSpSubmit" style="padding: 10px 20px; background: #7c3aed; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;"><?php echo e(t('vendor-onboarding.modal_add_subprocessor')); ?></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Subprocessor Modal -->
                <div id="editSubprocessorModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
                    <div style="background: white; border-radius: 12px; width: 95%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.25);">
                        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; font-size: 18px; color: #1f2937;"><?php echo e(t('vendor-onboarding.modal_edit_subprocessor')); ?></h3>
                            <button type="button" class="spModalClose" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                        </div>
                        <div style="padding: 24px;">
                            <div id="editSpError" style="display: none; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px; color: #991b1b;"></div>
                            <input type="hidden" id="editSpMappingId" value="">

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_subprocessor_name')); ?> *</label>
                                <input type="text" id="editSpName"
                                       style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                                <p style="margin: 4px 0 0; font-size: 11px; color: #9ca3af;"><?php echo e(t('vendor-onboarding.sp_name_hint')); ?></p>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_domain_plain')); ?></label>
                                <input type="text" id="editSpDomain"
                                       style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_country_plain')); ?></label>
                                <input type="text" id="editSpCountry"
                                       style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_linked_vendor')); ?></label>
                                <div style="position: relative;">
                                    <input type="text" id="editSpLinkedVendorSearch" placeholder="<?php echo e(t('vendor-onboarding.search_vendors_placeholder')); ?>" autocomplete="off"
                                           style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                                    <div id="editSpLinkedVendorDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-top: none; border-radius: 0 0 8px 8px; max-height: 200px; overflow-y: auto; z-index: 10; box-shadow: 0 4px 12px rgba(0,0,0,0.1);"></div>
                                </div>
                                <input type="hidden" id="editSpLinkedVendorId" value="">
                                <div id="editSpLinkedVendorSelected" style="display: none; margin-top: 6px; padding: 6px 10px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; font-size: 12px; color: #166534;">
                                    <span id="editSpLinkedVendorLabel"></span>
                                    <button type="button" id="editSpLinkedVendorClear" style="float: right; background: none; border: none; color: #991b1b; cursor: pointer; font-size: 11px; padding: 0;"><?php echo e(t('vendor-onboarding.btn_clear')); ?></button>
                                </div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_service_description')); ?></label>
                                <textarea id="editSpService" rows="2"
                                          style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; outline: none; resize: vertical; box-sizing: border-box;"></textarea>
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('vendor-onboarding.label_data_shared_plain')); ?></label>
                                <textarea id="editSpData" rows="2"
                                          style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; outline: none; resize: vertical; box-sizing: border-box;"></textarea>
                            </div>

                            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                                <button type="button" class="spModalClose" style="padding: 10px 20px; background: #f3f4f6; color: #374151; border: none; border-radius: 8px; font-size: 14px; cursor: pointer;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                                <button type="button" id="editSpSubmit" style="padding: 10px 20px; background: #7c3aed; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;"><?php echo e(t('vendor-onboarding.btn_save_changes')); ?></button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php
                // Check if AI extraction is available for contract pricing
                // Check if AI extraction is available (OpenWebUI or LibreChat)
                $docAiEnabled = false;
                try {
                    $docAiEnabled = AIPlatformService::getInstance()->isEnabled();
                } catch (Exception $e) {}
                ?>
                <!-- Document Upload Modal -->
                <div id="docUploadModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 30px; border-radius: 8px; max-width: 550px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto;">
                        <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;"><?php echo e(t('vendor-onboarding.btn_upload_document')); ?></h3>

                        <div id="docFileInfo" style="margin-bottom: 16px; padding: 10px 14px; background: #f9fafb; border-radius: 6px; font-size: 13px; color: #374151;"></div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_document_type')); ?> <span style="color: #dc2626;">*</span></label>
                            <select id="docType" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                <option value=""><?php echo e(t('vendor-onboarding.opt_select_type')); ?></option>
                                <option value="contract"><?php echo e(t('vendor-onboarding.filter_contract')); ?></option>
                                <?php if (!$isProcurementOnly): ?>
                                <option value="certification"><?php echo e(t('vendor-onboarding.th_certification')); ?></option>
                                <option value="other"><?php echo e(t('vendor-onboarding.vtype_other')); ?></option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- Contract fields -->
                        <div id="docContractFields" style="display: none;">
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_contract_name')); ?> <span style="color: #dc2626;">*</span></label>
                                <input type="text" id="docContractName" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_contract_name')); ?>">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_contract_type')); ?> <span style="color: #dc2626;">*</span></label>
                                <select id="docContractType" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                    <option value=""><?php echo e(t('vendor-onboarding.opt_select_generic')); ?></option>
                                    <option value="NDA"><?php echo e(t('vendor-onboarding.opt_nda')); ?></option>
                                    <option value="DPA"><?php echo e(t('vendor-onboarding.opt_dpa')); ?></option>
                                    <option value="Master Service Agreement"><?php echo e(t('vendor-onboarding.opt_msa')); ?></option>
                                    <option value="Privacy"><?php echo e(t('vendor-onboarding.opt_privacy')); ?></option>
                                    <option value="Order Form"><?php echo e(t('vendor-onboarding.opt_order_form')); ?></option>
                                    <option value="PO"><?php echo e(t('vendor-onboarding.opt_po')); ?></option>
                                </select>
                            </div>
                            <div style="display: flex; gap: 12px; margin-bottom: 12px;">
                                <div style="flex: 1;">
                                    <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_creation_date')); ?> <span style="color: #dc2626;">*</span></label>
                                    <input type="date" id="docContractCreation" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                </div>
                                <div style="flex: 1;">
                                    <label id="docContractExpirationLabel" style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_expiration_date')); ?> <span id="docContractExpirationReq" style="color: #dc2626;">*</span></label>
                                    <input type="date" id="docContractExpiration" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                </div>
                            </div>

                            <!-- Contract Pricing Fields (Order Form / PO only) -->
                            <div id="docPricingFields" style="display: none; border-top: 1px solid #e5e7eb; padding-top: 12px; margin-top: 4px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                                    <div style="font-weight: 600; font-size: 13px; color: #333;"><?php echo t('vendor-onboarding.pricing_terms_heading'); ?></div>
                                    <?php if ($docAiEnabled): ?>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <button type="button" id="docPricingAiBtn" style="padding: 4px 10px; background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; border-radius: 4px; font-size: 11px; cursor: pointer; font-weight: 500; white-space: nowrap;"><?php echo e(t('vendor-onboarding.btn_extract_document')); ?></button>
                                        <span id="docPricingAiStatus" style="font-size: 11px; color: #6b7280; display: none;"></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_contract_id_plain')); ?></label>
                                        <input type="text" id="docPricing_contractId" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_contract_id')); ?>">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_sor_link')); ?></label>
                                        <input type="url" id="docPricing_systemOfRecordLink" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="https://...">
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_renewal_type')); ?></label>
                                        <select id="docPricing_renewalType" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px;">
                                            <option value=""><?php echo e(t('vendor-onboarding.opt_select_generic')); ?></option>
                                            <option value="Auto-Renew"><?php echo e(t('vendor-onboarding.opt_auto_renew')); ?></option>
                                            <option value="Manual"><?php echo e(t('vendor-onboarding.opt_manual')); ?></option>
                                            <option value="Evergreen"><?php echo e(t('vendor-onboarding.opt_evergreen')); ?></option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_billing_frequency')); ?></label>
                                        <select id="docPricing_billingFrequency" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px;">
                                            <option value=""><?php echo e(t('vendor-onboarding.opt_select_generic')); ?></option>
                                            <option value="Monthly"><?php echo e(t('vendor-onboarding.opt_monthly')); ?></option>
                                            <option value="Quarterly"><?php echo e(t('vendor-onboarding.opt_quarterly')); ?></option>
                                            <option value="Annually"><?php echo e(t('vendor-onboarding.opt_annually')); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_renewal_notice')); ?></label>
                                        <input type="text" id="docPricing_renewalNoticeWindow" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_eg_90days')); ?>">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_termination_rights')); ?></label>
                                        <input type="text" id="docPricing_terminationRights" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_eg_notice')); ?>">
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_annual_fee')); ?></label>
                                        <input type="number" step="0.01" min="0" id="docPricing_annualFee" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0.00">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_unit_price')); ?></label>
                                        <input type="number" step="0.01" min="0" id="docPricing_unitPrice" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0.00">
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_included_units')); ?> <span style="color: #9ca3af; font-weight: 400;"><?php echo e(t('vendor-onboarding.hint_included_units')); ?></span></label>
                                        <input type="number" step="1" min="0" id="docPricing_includedUnits" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_overage_price')); ?></label>
                                        <input type="number" step="0.01" min="0" id="docPricing_overagePrice" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0.00">
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_onetime_fees')); ?></label>
                                        <input type="number" step="0.01" min="0" id="docPricing_oneTimeFees" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0.00">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_price_cap')); ?></label>
                                        <input type="number" step="0.01" min="0" max="100" id="docPricing_priceIncreaseCap" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0.00">
                                    </div>
                                </div>
                                <div style="margin-bottom: 6px;">
                                    <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_renewal_uplift')); ?></label>
                                    <select id="docPricing_renewalUplift" style="width: auto; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px;">
                                        <option value=""><?php echo e(t('vendor-onboarding.opt_na')); ?></option>
                                        <option value="Y"><?php echo e(t('vendor-onboarding.yes')); ?></option>
                                        <option value="N"><?php echo e(t('vendor-onboarding.no')); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Certification fields -->
                        <div id="docCertFields" style="display: none;">
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_cert_type')); ?> <span style="color: #dc2626;">*</span></label>
                                <select id="docCertType" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                    <option value=""><?php echo e(t('vendor-onboarding.opt_select_generic')); ?></option>
                                    <option value="SOC 2 Type II">SOC 2 Type II</option>
                                    <option value="ISO/IEC 27001">ISO/IEC 27001</option>
                                    <option value="PCI DSS">PCI DSS</option>
                                    <option value="HITRUST CSF">HITRUST CSF</option>
                                    <option value="NIST SP 800-171">NIST SP 800-171</option>
                                    <option value="NIST SP 800-53">NIST SP 800-53</option>
                                    <option value="NIST CSF">NIST CSF</option>
                                    <option value="CMMC">CMMC</option>
                                    <option value="FedRAMP">FedRAMP</option>
                                    <option value="SOC 2 Type I">SOC 2 Type I</option>
                                    <option value="SOC 1 Type II">SOC 1 Type II</option>
                                    <option value="ISO/IEC 27701">ISO/IEC 27701</option>
                                    <option value="ISO/IEC 27017">ISO/IEC 27017</option>
                                    <option value="ISO/IEC 27018">ISO/IEC 27018</option>
                                    <option value="HIPAA Attestation">HIPAA Attestation</option>
                                    <option value="CSA STAR">CSA STAR</option>
                                    <option value="CAIQ">CAIQ</option>
                                    <option value="ISO 22301">ISO 22301</option>
                                    <option value="StateRAMP">StateRAMP</option>
                                    <option value="Cyber Essentials">Cyber Essentials</option>
                                    <option value="TISAX">TISAX</option>
                                    <option value="Privacy Certification">Privacy Certification</option>
                                    <option value="Other"><?php echo e(t('vendor-onboarding.vtype_other')); ?></option>
                                </select>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_expiration_date')); ?> <span style="color: #dc2626;">*</span></label>
                                <input type="date" id="docCertExpiration" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                            </div>
                        </div>

                        <!-- Other fields -->
                        <div id="docOtherFields" style="display: none;">
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_description')); ?></label>
                                <textarea id="docDescription" rows="3" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; box-sizing: border-box;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_doc_description')); ?>"></textarea>
                            </div>
                        </div>

                        <!-- Upload progress -->
                        <div id="docUploadProgress" style="display: none; margin-bottom: 16px;">
                            <div style="background: #e5e7eb; border-radius: 4px; height: 8px; overflow: hidden;">
                                <div id="docProgressBar" style="background: var(--theme-button-color, #35a0a3); height: 100%; width: 0%; transition: width 0.3s;"></div>
                            </div>
                            <div id="docProgressText" style="font-size: 12px; color: #6b7280; margin-top: 4px; text-align: center;"><?php echo e(t('vendor-onboarding.uploading_text')); ?></div>
                        </div>

                        <div id="docUploadError" style="display: none; margin-bottom: 16px; padding: 10px 14px; background: #fef2f2; color: #dc2626; border-radius: 6px; font-size: 13px;"></div>

                        <div style="display: flex; gap: 10px; justify-content: flex-end;">
                            <button type="button" id="docCancelBtn" style="padding: 10px 20px; border: 1px solid #d1d5db; background: white; color: #666; border-radius: 6px; cursor: pointer; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                            <button type="button" id="docUploadBtn" style="padding: 10px 20px; border: none; background: var(--theme-button-color, #35a0a3); color: white; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;"><?php echo e(t('vendor-onboarding.btn_upload')); ?></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($showVendorTabs && ($isAdmin || $isCyberTPRM || $isProcurement)): ?>
                <!-- Edit Document Metadata Modal -->
                <div id="docEditModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 30px; border-radius: 8px; max-width: 550px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto;">
                        <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;"><?php echo e(t('vendor-onboarding.modal_edit_doc_meta')); ?></h3>
                        <input type="hidden" id="editDocId" value="">

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_document_type')); ?></label>
                            <select id="editDocType" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                <option value="contract"><?php echo e(t('vendor-onboarding.filter_contract')); ?></option>
                                <?php if (!$isProcurementOnly): ?>
                                <option value="certification"><?php echo e(t('vendor-onboarding.th_certification')); ?></option>
                                <option value="other"><?php echo e(t('vendor-onboarding.vtype_other')); ?></option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_status')); ?></label>
                            <select id="editDocActive" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                <option value="1"><?php echo e(t('vendor-onboarding.label_active')); ?></option>
                                <option value="0"><?php echo e(t('vendor-onboarding.label_inactive')); ?></option>
                            </select>
                        </div>

                        <!-- Contract fields -->
                        <div id="editContractFields" style="display: none;">
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_contract_name')); ?></label>
                                <input type="text" id="editContractName" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_contract_name')); ?>">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_contract_type')); ?></label>
                                <select id="editContractType" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                    <option value=""><?php echo e(t('vendor-onboarding.opt_select_generic')); ?></option>
                                    <option value="NDA"><?php echo e(t('vendor-onboarding.opt_nda')); ?></option>
                                    <option value="DPA"><?php echo e(t('vendor-onboarding.opt_dpa')); ?></option>
                                    <option value="Master Service Agreement"><?php echo e(t('vendor-onboarding.opt_msa')); ?></option>
                                    <option value="Privacy"><?php echo e(t('vendor-onboarding.opt_privacy')); ?></option>
                                    <option value="Order Form"><?php echo e(t('vendor-onboarding.opt_order_form')); ?></option>
                                    <option value="PO"><?php echo e(t('vendor-onboarding.opt_po')); ?></option>
                                </select>
                            </div>
                            <div style="display: flex; gap: 12px; margin-bottom: 12px;">
                                <div style="flex: 1;">
                                    <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_creation_date')); ?></label>
                                    <input type="date" id="editContractCreation" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                </div>
                                <div style="flex: 1;">
                                    <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_expiration_date')); ?></label>
                                    <input type="date" id="editContractExpiration" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                </div>
                            </div>

                            <!-- Edit Pricing Fields (Order Form / PO only) -->
                            <div id="editPricingFields" style="display: none; border-top: 1px solid #e5e7eb; padding-top: 12px; margin-top: 4px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                                    <div style="font-weight: 600; font-size: 13px; color: #333;"><?php echo t('vendor-onboarding.pricing_terms_heading'); ?></div>
                                    <?php if ($docAiEnabled): ?>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <button type="button" id="editPricingAiBtn" style="padding: 4px 10px; background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; border-radius: 4px; font-size: 11px; cursor: pointer; font-weight: 500; white-space: nowrap;"><?php echo e(t('vendor-onboarding.btn_extract_document')); ?></button>
                                        <span id="editPricingAiStatus" style="font-size: 11px; color: #6b7280; display: none;"></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_contract_id_plain')); ?></label>
                                        <input type="text" id="editPricing_contractId" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_contract_id')); ?>">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_sor_link')); ?></label>
                                        <input type="url" id="editPricing_systemOfRecordLink" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="https://...">
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_renewal_type')); ?></label>
                                        <select id="editPricing_renewalType" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px;">
                                            <option value=""><?php echo e(t('vendor-onboarding.opt_select_generic')); ?></option>
                                            <option value="Auto-Renew"><?php echo e(t('vendor-onboarding.opt_auto_renew')); ?></option>
                                            <option value="Manual"><?php echo e(t('vendor-onboarding.opt_manual')); ?></option>
                                            <option value="Evergreen"><?php echo e(t('vendor-onboarding.opt_evergreen')); ?></option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_billing_frequency')); ?></label>
                                        <select id="editPricing_billingFrequency" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px;">
                                            <option value=""><?php echo e(t('vendor-onboarding.opt_select_generic')); ?></option>
                                            <option value="Monthly"><?php echo e(t('vendor-onboarding.opt_monthly')); ?></option>
                                            <option value="Quarterly"><?php echo e(t('vendor-onboarding.opt_quarterly')); ?></option>
                                            <option value="Annually"><?php echo e(t('vendor-onboarding.opt_annually')); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_renewal_notice')); ?></label>
                                        <input type="text" id="editPricing_renewalNoticeWindow" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_eg_90days')); ?>">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_termination_rights')); ?></label>
                                        <input type="text" id="editPricing_terminationRights" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_eg_notice')); ?>">
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_annual_fee')); ?></label>
                                        <input type="number" step="0.01" min="0" id="editPricing_annualFee" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0.00">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_unit_price')); ?></label>
                                        <input type="number" step="0.01" min="0" id="editPricing_unitPrice" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0.00">
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_included_units')); ?> <span style="color: #9ca3af; font-weight: 400;"><?php echo e(t('vendor-onboarding.hint_included_units')); ?></span></label>
                                        <input type="number" step="1" min="0" id="editPricing_includedUnits" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_overage_price')); ?></label>
                                        <input type="number" step="0.01" min="0" id="editPricing_overagePrice" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0.00">
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_onetime_fees')); ?></label>
                                        <input type="number" step="0.01" min="0" id="editPricing_oneTimeFees" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0.00">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_price_cap')); ?></label>
                                        <input type="number" step="0.01" min="0" max="100" id="editPricing_priceIncreaseCap" style="width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px; box-sizing: border-box;" placeholder="0.00">
                                    </div>
                                </div>
                                <div style="margin-bottom: 6px;">
                                    <label style="display: block; margin-bottom: 4px; font-size: 12px; color: #555;"><?php echo e(t('vendor-onboarding.label_renewal_uplift')); ?></label>
                                    <select id="editPricing_renewalUplift" style="width: auto; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 12px;">
                                        <option value=""><?php echo e(t('vendor-onboarding.opt_na')); ?></option>
                                        <option value="Y"><?php echo e(t('vendor-onboarding.yes')); ?></option>
                                        <option value="N"><?php echo e(t('vendor-onboarding.no')); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Certification fields -->
                        <div id="editCertFields" style="display: none;">
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_cert_type')); ?></label>
                                <select id="editCertType" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                                    <option value=""><?php echo e(t('vendor-onboarding.opt_select_generic')); ?></option>
                                    <option value="SOC 2 Type II">SOC 2 Type II</option>
                                    <option value="ISO/IEC 27001">ISO/IEC 27001</option>
                                    <option value="PCI DSS">PCI DSS</option>
                                    <option value="HITRUST CSF">HITRUST CSF</option>
                                    <option value="NIST SP 800-171">NIST SP 800-171</option>
                                    <option value="NIST SP 800-53">NIST SP 800-53</option>
                                    <option value="NIST CSF">NIST CSF</option>
                                    <option value="CMMC">CMMC</option>
                                    <option value="FedRAMP">FedRAMP</option>
                                    <option value="SOC 2 Type I">SOC 2 Type I</option>
                                    <option value="SOC 1 Type II">SOC 1 Type II</option>
                                    <option value="ISO/IEC 27701">ISO/IEC 27701</option>
                                    <option value="ISO/IEC 27017">ISO/IEC 27017</option>
                                    <option value="ISO/IEC 27018">ISO/IEC 27018</option>
                                    <option value="HIPAA Attestation">HIPAA Attestation</option>
                                    <option value="CSA STAR">CSA STAR</option>
                                    <option value="CAIQ">CAIQ</option>
                                    <option value="ISO 22301">ISO 22301</option>
                                    <option value="StateRAMP">StateRAMP</option>
                                    <option value="Cyber Essentials">Cyber Essentials</option>
                                    <option value="TISAX">TISAX</option>
                                    <option value="Privacy Certification">Privacy Certification</option>
                                    <option value="Other"><?php echo e(t('vendor-onboarding.vtype_other')); ?></option>
                                </select>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_expiration_date')); ?></label>
                                <input type="date" id="editCertExpiration" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                            </div>
                        </div>

                        <!-- Other fields -->
                        <div id="editOtherFields" style="display: none;">
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;"><?php echo e(t('vendor-onboarding.label_description')); ?></label>
                                <textarea id="editDocDescription" rows="3" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; box-sizing: border-box;" placeholder="<?php echo e(t('vendor-onboarding.placeholder_optional_desc')); ?>"></textarea>
                            </div>
                        </div>

                        <div id="editDocError" style="display: none; margin-bottom: 16px; padding: 10px 14px; background: #fef2f2; color: #dc2626; border-radius: 6px; font-size: 13px;"></div>

                        <div style="display: flex; gap: 10px; justify-content: flex-end;">
                            <button type="button" id="editDocCancelBtn" style="padding: 10px 20px; border: 1px solid #d1d5db; background: white; color: #666; border-radius: 6px; cursor: pointer; font-size: 13px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                            <button type="button" id="editDocSaveBtn" style="padding: 10px 20px; border: none; background: var(--theme-button-color, #35a0a3); color: white; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;"><?php echo e(t('vendor-onboarding.btn_save_changes')); ?></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; ?><!-- /showTemplateSelection else -->

            </main><!-- /.main-content -->
        </div><!-- /.main-layout -->

        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($theme['footer_logo_url'])): ?>
                            <a class="brand" href="index.php">
                                <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('vendor-onboarding.alt_footer_logo')); ?>" style="max-height: 45px;">
                            </a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date('Y'); ?></span>
                        <span>.&nbsp;</span>
                        <span><?php echo e(t('vendor-onboarding.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
    <script nonce="<?php echo cspNonce(); ?>">
        var csrfToken = <?php echo json_encode($csrfToken); ?>;

        function updateAllCsrfTokens(newToken) {
            csrfToken = newToken;
            var fields = document.querySelectorAll('input[name="csrf_token"]');
            for (var i = 0; i < fields.length; i++) {
                fields[i].value = newToken;
            }
        }

        // Refresh CSRF token every 30 minutes to prevent expiry on long forms
        setInterval(function() {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'api/csrf-refresh.php');
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.addEventListener('load', function() {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success && resp.csrf_token) {
                        updateAllCsrfTokens(resp.csrf_token);
                    }
                } catch (e) {}
            });
            xhr.send('');
        }, 30 * 60 * 1000);

        function setAction(action) {
            document.getElementById('formAction').value = action;
        }

        function markInactive() {
            if (confirm(<?php echo json_encode(t('vendor-onboarding.js_confirm_inactive')); ?>)) {
                document.getElementById('formAction').value = 'deactivate';
                document.getElementById('onboardingForm').submit();
            }
        }

        function deleteRequest() {
            if (confirm(<?php echo json_encode(t('vendor-onboarding.js_confirm_delete1')); ?>)) {
                if (confirm(<?php echo json_encode(t('vendor-onboarding.js_confirm_delete2')); ?>)) {
                    document.getElementById('formAction').value = 'delete';
                    document.getElementById('onboardingForm').submit();
                }
            }
        }

        // Assessment collapsible cards
        document.querySelectorAll('.assessment-collapse-header').forEach(function(header) {
            header.addEventListener('click', function() {
                var body = document.getElementById(this.dataset.target);
                var chevron = this.querySelector('.assessment-chevron');
                if (body.style.display === 'none') {
                    body.style.display = '';
                    chevron.style.transform = 'rotate(90deg)';
                } else {
                    body.style.display = 'none';
                    chevron.style.transform = 'rotate(0deg)';
                }
            });
        });

        // Tab switching for vendor detail tabs
        function showVendorTab(section) {
            const sections = ['vendor', 'saas_data', 'custom_data', 'notes', 'assessments', 'fair', 'risks', 'documents', 'subprocessors', 'action_plan'];
            sections.forEach(function(s) {
                var el = document.getElementById('vendorTabContent_' + s);
                var tab = document.getElementById('vendorTab_' + s);
                if (el) el.style.display = (s === section) ? '' : 'none';
                if (tab) {
                    tab.style.borderBottomColor = (s === section) ? 'var(--theme-header-color)' : 'transparent';
                    tab.style.color = (s === section) ? 'var(--theme-header-color)' : '#6b7280';
                    tab.style.fontWeight = (s === section) ? '600' : '500';
                }
            });
        }

        // Document filter
        (function() {
            var filterBar = document.getElementById('docFilterBar');
            if (!filterBar) return;
            filterBar.addEventListener('click', function(e) {
                var btn = e.target.closest('.doc-filter-btn');
                if (!btn) return;
                var filter = btn.getAttribute('data-filter');
                filterBar.querySelectorAll('.doc-filter-btn').forEach(function(b) {
                    b.style.background = 'white'; b.style.color = '#374151';
                    b.classList.remove('doc-filter-active');
                });
                btn.style.background = '#374151'; btn.style.color = 'white';
                btn.classList.add('doc-filter-active');
                var cards = document.querySelectorAll('.doc-card');
                var visible = 0;
                cards.forEach(function(card) {
                    var show = false;
                    if (filter === 'all') show = true;
                    else if (filter === 'active' || filter === 'inactive') show = card.getAttribute('data-doc-status') === filter;
                    else if (filter === 'expired') show = card.getAttribute('data-doc-expired') === '1';
                    else show = card.getAttribute('data-doc-type') === filter;
                    card.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                var noResults = document.getElementById('docNoResults');
                if (noResults) noResults.style.display = visible === 0 ? '' : 'none';
            });
        })();

        // Document upload management
        <?php if ($showVendorTabs): ?>
        (function() {
            var docFile = null;
            var fileInput = document.getElementById('docFileInput');
            var modal = document.getElementById('docUploadModal');
            if (!fileInput || !modal) return;

            fileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    docFile = this.files[0];
                    var size = docFile.size >= 1048576 ? (docFile.size / 1048576).toFixed(1) + ' MB' : (docFile.size / 1024).toFixed(1) + ' KB';
                    document.getElementById('docFileInfo').textContent = docFile.name + ' (' + size + ')';
                    document.getElementById('docType').value = '';
                    document.getElementById('docContractFields').style.display = 'none';
                    document.getElementById('docCertFields').style.display = 'none';
                    document.getElementById('docOtherFields').style.display = 'none';
                    document.getElementById('docPricingFields').style.display = 'none';
                    document.getElementById('docUploadProgress').style.display = 'none';
                    document.getElementById('docUploadError').style.display = 'none';
                    document.getElementById('docUploadBtn').disabled = false;
                    document.getElementById('docContractCreation').value = new Date().toISOString().split('T')[0];
                    // Clear pricing fields
                    ['contractId','systemOfRecordLink','renewalNoticeWindow','terminationRights',
                     'annualFee','unitPrice','includedUnits','overagePrice','oneTimeFees','priceIncreaseCap'].forEach(function(f) {
                        var el = document.getElementById('docPricing_' + f);
                        if (el) el.value = '';
                    });
                    ['renewalType','billingFrequency','renewalUplift'].forEach(function(f) {
                        var el = document.getElementById('docPricing_' + f);
                        if (el) el.value = '';
                    });
                    modal.style.display = 'flex';
                }
            });

            document.getElementById('docType').addEventListener('change', function() {
                document.getElementById('docContractFields').style.display = this.value === 'contract' ? '' : 'none';
                document.getElementById('docCertFields').style.display = this.value === 'certification' ? '' : 'none';
                document.getElementById('docOtherFields').style.display = this.value === 'other' ? '' : 'none';
            });

            document.getElementById('docContractType').addEventListener('change', function() {
                var needsExp = (this.value === 'Master Service Agreement' || this.value === 'PO');
                document.getElementById('docContractExpirationReq').style.display = needsExp ? '' : 'none';
                var showPricing = (this.value === 'Order Form' || this.value === 'PO');
                document.getElementById('docPricingFields').style.display = showPricing ? '' : 'none';
            });

            function closeDocModal() {
                modal.style.display = 'none';
                docFile = null;
                fileInput.value = '';
            }

            document.getElementById('docCancelBtn').addEventListener('click', closeDocModal);
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'flex') closeDocModal();
            });

            document.getElementById('docUploadBtn').addEventListener('click', function() {
                var type = document.getElementById('docType').value;
                if (!type) { alert(<?php echo json_encode(t('vendor-onboarding.js_select_doc_type')); ?>); return; }

                if (type === 'contract') {
                    if (!document.getElementById('docContractName').value.trim()) { alert(<?php echo json_encode(t('vendor-onboarding.js_contract_name_required')); ?>); return; }
                    var cType = document.getElementById('docContractType').value;
                    if (!cType) { alert(<?php echo json_encode(t('vendor-onboarding.js_contract_type_required')); ?>); return; }
                    if (!document.getElementById('docContractCreation').value) { alert(<?php echo json_encode(t('vendor-onboarding.js_creation_date_required')); ?>); return; }
                    if ((cType === 'Master Service Agreement' || cType === 'PO') && !document.getElementById('docContractExpiration').value) { alert(<?php echo json_encode(t('vendor-onboarding.js_expiration_required')); ?>); return; }
                }
                if (type === 'certification') {
                    if (!document.getElementById('docCertType').value) { alert(<?php echo json_encode(t('vendor-onboarding.js_cert_type_required')); ?>); return; }
                    if (!document.getElementById('docCertExpiration').value) { alert(<?php echo json_encode(t('vendor-onboarding.js_cert_expiration_required')); ?>); return; }
                }

                var fd = new FormData();
                fd.append('file', docFile);
                fd.append('csrf_token', csrfToken);
                fd.append('vendor_request_id', '<?php echo $requestId; ?>');
                fd.append('document_type', type);

                if (type === 'contract') {
                    var cTypeVal = document.getElementById('docContractType').value;
                    fd.append('contract_name', document.getElementById('docContractName').value.trim());
                    fd.append('contract_type', cTypeVal);
                    fd.append('contract_creation_date', document.getElementById('docContractCreation').value);
                    fd.append('contract_expiration_date', document.getElementById('docContractExpiration').value);
                    if (cTypeVal === 'Order Form' || cTypeVal === 'PO') {
                        var pricingFields = ['contractId','systemOfRecordLink','renewalType','billingFrequency',
                            'renewalNoticeWindow','terminationRights','annualFee','unitPrice','includedUnits',
                            'overagePrice','oneTimeFees','priceIncreaseCap','renewalUplift'];
                        var pricing = {};
                        pricingFields.forEach(function(f) {
                            var el = document.getElementById('docPricing_' + f);
                            if (el && el.value !== '') pricing[f] = el.value;
                        });
                        if (Object.keys(pricing).length > 0) {
                            fd.append('contract_pricing', JSON.stringify(pricing));
                        }
                    }
                } else if (type === 'certification') {
                    fd.append('certification_type', document.getElementById('docCertType').value);
                    fd.append('certification_expiration_date', document.getElementById('docCertExpiration').value);
                } else if (type === 'other') {
                    fd.append('description', document.getElementById('docDescription').value.trim());
                }

                var xhr = new XMLHttpRequest();
                var progressDiv = document.getElementById('docUploadProgress');
                var progressBar = document.getElementById('docProgressBar');
                var progressText = document.getElementById('docProgressText');
                var errorDiv = document.getElementById('docUploadError');

                progressDiv.style.display = '';
                errorDiv.style.display = 'none';
                document.getElementById('docUploadBtn').disabled = true;

                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var pct = Math.round((e.loaded / e.total) * 100);
                        progressBar.style.width = pct + '%';
                        progressText.textContent = pct + '%';
                    }
                });

                xhr.addEventListener('load', function() {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.csrf_token) updateAllCsrfTokens(resp.csrf_token);
                        if (resp.success) {
                            window.location.href = 'vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=documents';
                        } else {
                            errorDiv.textContent = resp.message || <?php echo json_encode(t('vendor-onboarding.js_upload_failed')); ?>;
                            errorDiv.style.display = '';
                            progressDiv.style.display = 'none';
                            document.getElementById('docUploadBtn').disabled = false;
                        }
                    } catch (e) {
                        errorDiv.textContent = <?php echo json_encode(t('vendor-onboarding.js_unexpected_server')); ?>;
                        errorDiv.style.display = '';
                        progressDiv.style.display = 'none';
                        document.getElementById('docUploadBtn').disabled = false;
                    }
                });

                xhr.addEventListener('error', function() {
                    errorDiv.textContent = <?php echo json_encode(t('vendor-onboarding.js_network_error_upload')); ?>;
                    errorDiv.style.display = '';
                    progressDiv.style.display = 'none';
                    document.getElementById('docUploadBtn').disabled = false;
                });

                xhr.open('POST', 'api/vendor-document-upload.php');
                xhr.send(fd);
            });
        })();

        // AI Extract Pricing handlers
        <?php if ($docAiEnabled): ?>
        (function() {
            var pricingFieldNames = ['contractId','systemOfRecordLink','renewalType','billingFrequency',
                'renewalNoticeWindow','terminationRights','annualFee','unitPrice','includedUnits',
                'overagePrice','oneTimeFees','priceIncreaseCap','renewalUplift'];

            function populatePricingFields(prefix, pricing) {
                var filled = 0;
                var skipped = 0;
                pricingFieldNames.forEach(function(f) {
                    if (pricing[f] !== undefined && pricing[f] !== '') {
                        var el = document.getElementById(prefix + f);
                        if (el) {
                            // Don't overwrite fields that already have values
                            if (el.value !== '' && el.value !== null) {
                                skipped++;
                                return;
                            }
                            el.value = pricing[f];
                            filled++;
                        }
                    }
                });
                return {filled: filled, skipped: skipped};
            }

            // Poll AI job for contract pricing extraction results
            function pollPricingJob(jobId, btn, statusEl, fieldPrefix) {
                var pollInterval = setInterval(function() {
                    fetch('api/ai-job-status.php?id=' + jobId)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.csrf_token) updateAllCsrfTokens(data.csrf_token);

                        if (data.status === 'completed' && data.result) {
                            clearInterval(pollInterval);
                            btn.disabled = false;
                            btn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_extract_document')); ?>;
                            if (data.result.pricing) {
                                var result = populatePricingFields(fieldPrefix, data.result.pricing);
                                if (result.filled > 0) {
                                    statusEl.textContent = 'Filled ' + result.filled + ' field' + (result.filled !== 1 ? 's' : '') + (result.skipped > 0 ? ' (' + result.skipped + ' kept)' : '');
                                    statusEl.style.color = '#16a34a';
                                } else if (result.skipped > 0) {
                                    statusEl.textContent = <?php echo json_encode(t('vendor-onboarding.js_all_populated')); ?>;
                                    statusEl.style.color = '#9ca3af';
                                } else {
                                    statusEl.textContent = <?php echo json_encode(t('vendor-onboarding.js_no_pricing_data')); ?>;
                                    statusEl.style.color = '#9ca3af';
                                }
                            } else {
                                statusEl.textContent = <?php echo json_encode(t('vendor-onboarding.js_no_pricing_data')); ?>;
                                statusEl.style.color = '#9ca3af';
                            }
                            statusEl.style.display = '';
                        } else if (data.status === 'failed') {
                            clearInterval(pollInterval);
                            btn.disabled = false;
                            btn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_extract_document')); ?>;
                            statusEl.textContent = data.error || <?php echo json_encode(t('vendor-onboarding.js_extraction_failed')); ?>;
                            statusEl.style.color = '#dc2626';
                            statusEl.style.display = '';
                        }
                        // pending/processing: keep polling
                    })
                    .catch(function() {
                        clearInterval(pollInterval);
                        btn.disabled = false;
                        btn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_extract_document')); ?>;
                        statusEl.textContent = <?php echo json_encode(t('vendor-onboarding.js_network_error')); ?>;
                        statusEl.style.color = '#dc2626';
                        statusEl.style.display = '';
                    });
                }, 3000);
            }

            // Upload modal AI button
            var docAiBtn = document.getElementById('docPricingAiBtn');
            if (docAiBtn) {
                docAiBtn.addEventListener('click', function() {
                    var fileInput = document.getElementById('docFileInput');
                    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                        alert(<?php echo json_encode(t('vendor-onboarding.js_select_file_first')); ?>);
                        return;
                    }

                    var statusEl = document.getElementById('docPricingAiStatus');
                    docAiBtn.disabled = true;
                    docAiBtn.textContent = <?php echo json_encode(t('vendor-onboarding.js_extracting')); ?>;
                    statusEl.textContent = '';
                    statusEl.style.display = 'none';

                    var fd = new FormData();
                    fd.append('file', fileInput.files[0]);
                    fd.append('csrf_token', csrfToken);

                    var xhr = new XMLHttpRequest();
                    xhr.addEventListener('load', function() {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.csrf_token) updateAllCsrfTokens(resp.csrf_token);
                            if (resp.queued && resp.job_id) {
                                pollPricingJob(resp.job_id, docAiBtn, statusEl, 'docPricing_');
                            } else {
                                docAiBtn.disabled = false;
                                docAiBtn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_extract_document')); ?>;
                                statusEl.textContent = resp.error || <?php echo json_encode(t('vendor-onboarding.js_extraction_failed')); ?>;
                                statusEl.style.color = '#dc2626';
                                statusEl.style.display = '';
                            }
                        } catch (e) {
                            docAiBtn.disabled = false;
                            docAiBtn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_extract_document')); ?>;
                            statusEl.textContent = <?php echo json_encode(t('vendor-onboarding.js_unexpected_response')); ?>;
                            statusEl.style.color = '#dc2626';
                            statusEl.style.display = '';
                        }
                    });
                    xhr.addEventListener('error', function() {
                        docAiBtn.disabled = false;
                        docAiBtn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_extract_document')); ?>;
                        statusEl.textContent = <?php echo json_encode(t('vendor-onboarding.js_network_error')); ?>;
                        statusEl.style.color = '#dc2626';
                        statusEl.style.display = '';
                    });
                    xhr.open('POST', 'api/contract-pricing-ai-extract.php');
                    xhr.send(fd);
                });
            }

            // Edit modal AI button
            var editAiBtn = document.getElementById('editPricingAiBtn');
            if (editAiBtn) {
                editAiBtn.addEventListener('click', function() {
                    var docId = document.getElementById('editDocId').value;
                    if (!docId) {
                        alert(<?php echo json_encode(t('vendor-onboarding.js_no_document_loaded')); ?>);
                        return;
                    }

                    var statusEl = document.getElementById('editPricingAiStatus');
                    editAiBtn.disabled = true;
                    editAiBtn.textContent = <?php echo json_encode(t('vendor-onboarding.js_extracting')); ?>;
                    statusEl.textContent = '';
                    statusEl.style.display = 'none';

                    var fd = new FormData();
                    fd.append('document_id', docId);
                    fd.append('csrf_token', csrfToken);

                    var xhr = new XMLHttpRequest();
                    xhr.addEventListener('load', function() {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.csrf_token) updateAllCsrfTokens(resp.csrf_token);
                            if (resp.queued && resp.job_id) {
                                pollPricingJob(resp.job_id, editAiBtn, statusEl, 'editPricing_');
                            } else {
                                editAiBtn.disabled = false;
                                editAiBtn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_extract_document')); ?>;
                                statusEl.textContent = resp.error || <?php echo json_encode(t('vendor-onboarding.js_extraction_failed')); ?>;
                                statusEl.style.color = '#dc2626';
                                statusEl.style.display = '';
                            }
                        } catch (e) {
                            editAiBtn.disabled = false;
                            editAiBtn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_extract_document')); ?>;
                            statusEl.textContent = <?php echo json_encode(t('vendor-onboarding.js_unexpected_response')); ?>;
                            statusEl.style.color = '#dc2626';
                            statusEl.style.display = '';
                        }
                    });
                    xhr.addEventListener('error', function() {
                        editAiBtn.disabled = false;
                        editAiBtn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_extract_document')); ?>;
                        statusEl.textContent = <?php echo json_encode(t('vendor-onboarding.js_network_error')); ?>;
                        statusEl.style.color = '#dc2626';
                        statusEl.style.display = '';
                    });
                    xhr.open('POST', 'api/contract-pricing-ai-extract.php');
                    xhr.send(fd);
                });
            }
        })();
        <?php endif; ?>

        function deleteDocument(arg) {
            var parts = arg.split(',');
            var id = parts[0];
            var filename = parts.slice(1).join(',');
            if (!confirm(<?php echo json_encode(t('vendor-onboarding.js_delete_confirm_prefix')); ?> + filename + <?php echo json_encode(t('vendor-onboarding.js_delete_confirm_suffix')); ?>)) return;
            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('document_id', id);
            var xhr = new XMLHttpRequest();
            xhr.addEventListener('load', function() {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.csrf_token) updateAllCsrfTokens(resp.csrf_token);
                    if (resp.success) {
                        window.location.href = 'vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=documents';
                    } else {
                        alert(resp.message || <?php echo json_encode(t('vendor-onboarding.js_delete_failed')); ?>);
                    }
                } catch (e) { alert(<?php echo json_encode(t('vendor-onboarding.js_delete_failed')); ?>); }
            });
            xhr.addEventListener('error', function() { alert(<?php echo json_encode(t('vendor-onboarding.js_network_error')); ?>); });
            xhr.open('POST', 'api/vendor-document-delete.php');
            xhr.send(fd);
        }

        function toggleDocumentStatus(id) {
            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('document_id', id);
            var xhr = new XMLHttpRequest();
            xhr.addEventListener('load', function() {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.csrf_token) updateAllCsrfTokens(resp.csrf_token);
                    if (resp.success) {
                        window.location.href = 'vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=documents';
                    } else {
                        alert(resp.message || <?php echo json_encode(t('vendor-onboarding.js_status_update_failed')); ?>);
                    }
                } catch (e) { alert(<?php echo json_encode(t('vendor-onboarding.js_status_update_failed')); ?>); }
            });
            xhr.addEventListener('error', function() { alert(<?php echo json_encode(t('vendor-onboarding.js_network_error')); ?>); });
            xhr.open('POST', 'api/vendor-document-toggle-status.php');
            xhr.send(fd);
        }

        function editDocumentMeta(docId) {
            var btn = document.querySelector('[data-action="editDocumentMeta"][data-arg="' + docId + '"]');
            if (!btn) return;

            var modal = document.getElementById('docEditModal');
            if (!modal) return;

            // Populate fields from data attributes
            document.getElementById('editDocId').value = docId;
            document.getElementById('editDocType').value = btn.getAttribute('data-doc-type') || 'other';
            document.getElementById('editDocActive').value = btn.getAttribute('data-is-active') || '1';
            document.getElementById('editContractName').value = btn.getAttribute('data-contract-name') || '';
            document.getElementById('editContractType').value = btn.getAttribute('data-contract-type') || '';
            document.getElementById('editContractCreation').value = btn.getAttribute('data-contract-creation') || '';
            document.getElementById('editContractExpiration').value = btn.getAttribute('data-contract-expiration') || '';
            document.getElementById('editCertType').value = btn.getAttribute('data-cert-type') || '';
            document.getElementById('editCertExpiration').value = btn.getAttribute('data-cert-expiration') || '';
            document.getElementById('editDocDescription').value = btn.getAttribute('data-description') || '';
            document.getElementById('editDocError').style.display = 'none';

            // Populate pricing fields from data attribute
            var pricingFields = ['contractId','systemOfRecordLink','renewalType','billingFrequency',
                'renewalNoticeWindow','terminationRights','annualFee','unitPrice','includedUnits',
                'overagePrice','oneTimeFees','priceIncreaseCap','renewalUplift'];
            var pricingData = {};
            try {
                var raw = btn.getAttribute('data-contract-pricing');
                if (raw) pricingData = JSON.parse(raw);
            } catch(e) {}
            pricingFields.forEach(function(f) {
                var el = document.getElementById('editPricing_' + f);
                if (el) el.value = pricingData[f] || '';
            });

            // Show/hide type-specific fields
            toggleEditDocFields();
            modal.style.display = 'flex';
        }

        function toggleEditDocFields() {
            var type = document.getElementById('editDocType').value;
            document.getElementById('editContractFields').style.display = type === 'contract' ? '' : 'none';
            document.getElementById('editCertFields').style.display = type === 'certification' ? '' : 'none';
            document.getElementById('editOtherFields').style.display = type === 'other' ? '' : 'none';
            var cType = document.getElementById('editContractType').value;
            var showPricing = (type === 'contract' && (cType === 'Order Form' || cType === 'PO'));
            document.getElementById('editPricingFields').style.display = showPricing ? '' : 'none';
        }

        (function() {
            var editModal = document.getElementById('docEditModal');
            var editType = document.getElementById('editDocType');
            var cancelBtn = document.getElementById('editDocCancelBtn');
            var saveBtn = document.getElementById('editDocSaveBtn');
            if (!editModal) return;

            if (editType) editType.addEventListener('change', toggleEditDocFields);
            var editCType = document.getElementById('editContractType');
            if (editCType) editCType.addEventListener('change', toggleEditDocFields);

            if (cancelBtn) cancelBtn.addEventListener('click', function() {
                editModal.style.display = 'none';
            });

            editModal.addEventListener('click', function(e) {
                if (e.target === editModal) editModal.style.display = 'none';
            });

            if (saveBtn) saveBtn.addEventListener('click', function() {
                var docId = document.getElementById('editDocId').value;
                var docType = document.getElementById('editDocType').value;
                var errEl = document.getElementById('editDocError');
                errEl.style.display = 'none';

                var fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('document_id', docId);
                fd.append('document_type', docType);
                fd.append('is_active', document.getElementById('editDocActive').value);

                if (docType === 'contract') {
                    var editCTypeVal = document.getElementById('editContractType').value;
                    fd.append('contract_name', document.getElementById('editContractName').value);
                    fd.append('contract_type', editCTypeVal);
                    fd.append('contract_creation_date', document.getElementById('editContractCreation').value);
                    fd.append('contract_expiration_date', document.getElementById('editContractExpiration').value);
                    if (editCTypeVal === 'Order Form' || editCTypeVal === 'PO') {
                        var ePricingFields = ['contractId','systemOfRecordLink','renewalType','billingFrequency',
                            'renewalNoticeWindow','terminationRights','annualFee','unitPrice','includedUnits',
                            'overagePrice','oneTimeFees','priceIncreaseCap','renewalUplift'];
                        var ePricing = {};
                        ePricingFields.forEach(function(f) {
                            var el = document.getElementById('editPricing_' + f);
                            if (el && el.value !== '') ePricing[f] = el.value;
                        });
                        fd.append('contract_pricing', Object.keys(ePricing).length > 0 ? JSON.stringify(ePricing) : '');
                    } else {
                        fd.append('contract_pricing', '');
                    }
                } else if (docType === 'certification') {
                    fd.append('certification_type', document.getElementById('editCertType').value);
                    fd.append('certification_expiration_date', document.getElementById('editCertExpiration').value);
                } else {
                    fd.append('description', document.getElementById('editDocDescription').value);
                }

                saveBtn.disabled = true;
                saveBtn.textContent = <?php echo json_encode(t('vendor-onboarding.js_saving')); ?>;

                var xhr = new XMLHttpRequest();
                xhr.addEventListener('load', function() {
                    saveBtn.disabled = false;
                    saveBtn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_save_changes')); ?>;
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.csrf_token) updateAllCsrfTokens(resp.csrf_token);
                        if (resp.success) {
                            window.location.href = 'vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=documents';
                        } else {
                            errEl.textContent = resp.message || <?php echo json_encode(t('vendor-onboarding.js_save_failed')); ?>;
                            errEl.style.display = '';
                        }
                    } catch (e) {
                        errEl.textContent = <?php echo json_encode(t('vendor-onboarding.js_save_failed')); ?>;
                        errEl.style.display = '';
                    }
                });
                xhr.addEventListener('error', function() {
                    saveBtn.disabled = false;
                    saveBtn.textContent = <?php echo json_encode(t('vendor-onboarding.btn_save_changes')); ?>;
                    errEl.textContent = <?php echo json_encode(t('vendor-onboarding.js_network_error')); ?>;
                    errEl.style.display = '';
                });
                xhr.open('POST', 'api/vendor-document-edit.php');
                xhr.send(fd);
            });
        })();
        <?php endif; ?>

        // ============================================================
        // SUBPROCESSOR MANAGEMENT
        // ============================================================
        function removeSubprocessor(arg) {
            var parts = arg.split(',');
            var mappingId = parts[0];
            var name = parts.slice(1).join(',');
            if (!confirm(<?php echo json_encode(t('vendor-onboarding.js_remove_confirm_prefix')); ?> + name + <?php echo json_encode(t('vendor-onboarding.js_remove_confirm_suffix')); ?>)) return;
            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('mapping_id', mappingId);
            var xhr = new XMLHttpRequest();
            xhr.addEventListener('load', function() {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.csrf_token) updateAllCsrfTokens(resp.csrf_token);
                    if (resp.success) {
                        window.location.href = 'vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=subprocessors';
                    } else {
                        alert(resp.message || <?php echo json_encode(t('vendor-onboarding.js_remove_failed')); ?>);
                    }
                } catch (e) { alert(<?php echo json_encode(t('vendor-onboarding.js_remove_failed')); ?>); }
            });
            xhr.addEventListener('error', function() { alert(<?php echo json_encode(t('vendor-onboarding.js_network_error')); ?>); });
            xhr.open('POST', 'api/vendor-subprocessor-remove.php');
            xhr.send(fd);
        }

        function editSubprocessor(mappingId) {
            var btn = document.querySelector('[data-action="editSubprocessor"][data-arg="' + mappingId + '"]');
            if (!btn) return;
            var modal = document.getElementById('editSubprocessorModal');
            if (!modal) return;
            document.getElementById('editSpMappingId').value = mappingId;
            document.getElementById('editSpName').value = btn.getAttribute('data-sp-name') || '';
            document.getElementById('editSpDomain').value = btn.getAttribute('data-sp-domain') || '';
            document.getElementById('editSpCountry').value = btn.getAttribute('data-sp-country') || '';
            document.getElementById('editSpService').value = btn.getAttribute('data-sp-service') || '';
            document.getElementById('editSpData').value = btn.getAttribute('data-sp-data') || '';
            document.getElementById('editSpError').style.display = 'none';

            var linkedId = btn.getAttribute('data-sp-linked') || '0';
            var linkedName = btn.getAttribute('data-sp-linked-name') || '';
            document.getElementById('editSpLinkedVendorId').value = linkedId !== '0' ? linkedId : '';
            if (linkedId !== '0' && linkedName) {
                document.getElementById('editSpLinkedVendorSearch').value = '';
                document.getElementById('editSpLinkedVendorLabel').textContent = linkedName;
                document.getElementById('editSpLinkedVendorSelected').style.display = '';
            } else {
                document.getElementById('editSpLinkedVendorSearch').value = '';
                document.getElementById('editSpLinkedVendorSelected').style.display = 'none';
            }
            modal.style.display = 'flex';
        }

        // Subprocessor modal IIFE
        (function() {
            var addModal = document.getElementById('addSubprocessorModal');
            var editModal = document.getElementById('editSubprocessorModal');
            if (!addModal && !editModal) return;

            // Close buttons
            document.querySelectorAll('.spModalClose').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (addModal) addModal.style.display = 'none';
                    if (editModal) editModal.style.display = 'none';
                });
            });

            // Open Add modal
            var addBtn = document.getElementById('addSubprocessorBtn');
            if (addBtn && addModal) {
                addBtn.addEventListener('click', function() {
                    document.getElementById('addSpName').value = '';
                    document.getElementById('addSpId').value = '';
                    document.getElementById('addSpDomain').value = '';
                    document.getElementById('addSpCountry').value = '';
                    document.getElementById('addSpService').value = '';
                    document.getElementById('addSpData').value = '';
                    document.getElementById('addSpLinkedVendorId').value = '';
                    document.getElementById('addSpLinkedVendorSearch').value = '';
                    document.getElementById('addSpLinkedVendorSelected').style.display = 'none';
                    document.getElementById('addSpError').style.display = 'none';
                    document.getElementById('addSpNameDropdown').style.display = 'none';
                    addModal.style.display = 'flex';
                });
            }

            // Autocomplete for subprocessor name (Add modal)
            var spNameInput = document.getElementById('addSpName');
            var spNameDropdown = document.getElementById('addSpNameDropdown');
            var spIdHidden = document.getElementById('addSpId');
            var spSearchTimer = null;

            if (spNameInput) {
                spNameInput.addEventListener('input', function() {
                    var q = spNameInput.value.trim();
                    spIdHidden.value = '';
                    if (q.length < 1) { spNameDropdown.style.display = 'none'; return; }
                    clearTimeout(spSearchTimer);
                    spSearchTimer = setTimeout(function() {
                        var xhr = new XMLHttpRequest();
                        xhr.addEventListener('load', function() {
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                var html = '';
                                var hasResults = false;

                                // Existing subprocessors section
                                if (resp.success && resp.results && resp.results.length > 0) {
                                    hasResults = true;
                                    html += '<div style="padding: 6px 14px; font-size: 11px; font-weight: 700; color: #7c3aed; background: #f5f3ff; text-transform: uppercase; letter-spacing: 0.5px;">' + <?php echo json_encode(t('vendor-onboarding.js_existing_subprocessors')); ?> + '</div>';
                                    html += resp.results.map(function(r) {
                                        return '<div style="padding: 10px 14px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f3f4f6;" ' +
                                            'data-sp-id="' + r.id + '" data-sp-name="' + r.subprocessor_name.replace(/"/g, '&quot;') + '" ' +
                                            'data-sp-domain="' + (r.subprocessor_domain || '').replace(/"/g, '&quot;') + '" ' +
                                            'data-sp-country="' + (r.country || '').replace(/"/g, '&quot;') + '">' +
                                            '<strong>' + r.subprocessor_name.replace(/</g, '&lt;') + '</strong>' +
                                            (r.subprocessor_domain ? ' <span style="color:#6b7280;">(' + r.subprocessor_domain.replace(/</g, '&lt;') + ')</span>' : '') +
                                            ' <span style="color:#9ca3af; font-size:11px;">used by ' + r.vendor_count + ' vendor(s)</span>' +
                                            '</div>';
                                    }).join('');
                                }

                                // Monitored vendors section
                                if (resp.success && resp.vendors && resp.vendors.length > 0) {
                                    hasResults = true;
                                    html += '<div style="padding: 6px 14px; font-size: 11px; font-weight: 700; color: #1d4ed8; background: #eff6ff; text-transform: uppercase; letter-spacing: 0.5px;">' + <?php echo json_encode(t('vendor-onboarding.js_monitored_vendors')); ?> + '</div>';
                                    html += resp.vendors.map(function(v) {
                                        return '<div style="padding: 10px 14px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f3f4f6;" ' +
                                            'data-vendor-id="' + v.id + '" data-vendor-name="' + (v.vendor_name || '').replace(/"/g, '&quot;') + '" ' +
                                            'data-vendor-domain="' + (v.vendor_domain || '').replace(/"/g, '&quot;') + '">' +
                                            '<span style="background:#dbeafe; color:#1d4ed8; font-size:10px; padding:1px 6px; border-radius:8px; font-weight:600; margin-right:6px;">' + <?php echo json_encode(t('vendor-onboarding.tab_vendor')); ?> + '</span>' +
                                            '<strong>' + (v.vendor_name || '').replace(/</g, '&lt;') + '</strong>' +
                                            (v.vendor_domain ? ' <span style="color:#6b7280;">(' + v.vendor_domain.replace(/</g, '&lt;') + ')</span>' : '') +
                                            '</div>';
                                    }).join('');
                                }

                                if (hasResults) {
                                    spNameDropdown.innerHTML = html;
                                    spNameDropdown.style.display = '';
                                } else {
                                    spNameDropdown.style.display = 'none';
                                }
                            } catch(e) { spNameDropdown.style.display = 'none'; }
                        });
                        xhr.open('GET', 'api/search-subprocessors.php?q=' + encodeURIComponent(q));
                        xhr.send();
                    }, 250);
                });

                spNameDropdown.addEventListener('click', function(e) {
                    // Existing subprocessor selected
                    var spItem = e.target.closest('[data-sp-id]');
                    if (spItem) {
                        spIdHidden.value = spItem.getAttribute('data-sp-id');
                        spNameInput.value = spItem.getAttribute('data-sp-name');
                        document.getElementById('addSpDomain').value = spItem.getAttribute('data-sp-domain') || '';
                        document.getElementById('addSpCountry').value = spItem.getAttribute('data-sp-country') || '';
                        spNameDropdown.style.display = 'none';
                        return;
                    }
                    // Monitored vendor selected — fill name, domain, and auto-link
                    var vItem = e.target.closest('[data-vendor-id]');
                    if (vItem) {
                        var vName = vItem.getAttribute('data-vendor-name') || '';
                        var vDomain = vItem.getAttribute('data-vendor-domain') || '';
                        var vId = vItem.getAttribute('data-vendor-id');
                        spIdHidden.value = '';
                        spNameInput.value = vName;
                        document.getElementById('addSpDomain').value = vDomain;
                        // Auto-set linked vendor
                        document.getElementById('addSpLinkedVendorId').value = vId;
                        document.getElementById('addSpLinkedVendorLabel').textContent = vName;
                        document.getElementById('addSpLinkedVendorSelected').style.display = '';
                        spNameDropdown.style.display = 'none';
                    }
                });

                document.addEventListener('click', function(e) {
                    if (!spNameInput.contains(e.target) && !spNameDropdown.contains(e.target)) {
                        spNameDropdown.style.display = 'none';
                    }
                });
            }

            // Vendor autocomplete helper (shared for add/edit linked vendor)
            function setupVendorAutocomplete(searchInput, dropdown, hiddenInput, selectedDiv, labelSpan, clearBtn, onSelect) {
                if (!searchInput) return;
                var timer = null;
                searchInput.addEventListener('input', function() {
                    var q = searchInput.value.trim();
                    if (q.length < 2) { dropdown.style.display = 'none'; return; }
                    clearTimeout(timer);
                    timer = setTimeout(function() {
                        var xhr = new XMLHttpRequest();
                        xhr.addEventListener('load', function() {
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                if (resp.success && resp.vendors && resp.vendors.length > 0) {
                                    dropdown.innerHTML = resp.vendors.map(function(v) {
                                        return '<div style="padding: 10px 14px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f3f4f6;" data-vid="' + v.id + '" data-vname="' + (v.vendor_name || '').replace(/"/g, '&quot;') + '" data-vdomain="' + (v.vendor_domain || '').replace(/"/g, '&quot;') + '">' +
                                            '<strong>' + (v.vendor_name || '').replace(/</g, '&lt;') + '</strong>' +
                                            (v.vendor_domain ? ' <span style="color:#6b7280;">(' + v.vendor_domain.replace(/</g, '&lt;') + ')</span>' : '') +
                                            '</div>';
                                    }).join('');
                                    dropdown.style.display = '';
                                } else { dropdown.style.display = 'none'; }
                            } catch(e) { dropdown.style.display = 'none'; }
                        });
                        xhr.open('GET', 'api/search-vendors.php?q=' + encodeURIComponent(q));
                        xhr.send();
                    }, 250);
                });
                dropdown.addEventListener('click', function(e) {
                    var item = e.target.closest('[data-vid]');
                    if (!item) return;
                    hiddenInput.value = item.getAttribute('data-vid');
                    searchInput.value = '';
                    labelSpan.textContent = item.getAttribute('data-vname');
                    selectedDiv.style.display = '';
                    dropdown.style.display = 'none';
                    if (onSelect) {
                        onSelect({
                            id: item.getAttribute('data-vid'),
                            vendor_name: item.getAttribute('data-vname'),
                            vendor_domain: item.getAttribute('data-vdomain') || ''
                        });
                    }
                });
                if (clearBtn) {
                    clearBtn.addEventListener('click', function() {
                        hiddenInput.value = '';
                        selectedDiv.style.display = 'none';
                    });
                }
                document.addEventListener('click', function(e) {
                    if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.style.display = 'none';
                    }
                });
            }

            // Set up linked vendor autocomplete for Add modal — auto-fill name & domain
            setupVendorAutocomplete(
                document.getElementById('addSpLinkedVendorSearch'),
                document.getElementById('addSpLinkedVendorDropdown'),
                document.getElementById('addSpLinkedVendorId'),
                document.getElementById('addSpLinkedVendorSelected'),
                document.getElementById('addSpLinkedVendorLabel'),
                document.getElementById('addSpLinkedVendorClear'),
                function(vendor) {
                    var nameEl = document.getElementById('addSpName');
                    var domainEl = document.getElementById('addSpDomain');
                    if (nameEl && !nameEl.value.trim()) nameEl.value = vendor.vendor_name;
                    if (domainEl && !domainEl.value.trim()) domainEl.value = vendor.vendor_domain;
                }
            );

            // Set up linked vendor autocomplete for Edit modal
            setupVendorAutocomplete(
                document.getElementById('editSpLinkedVendorSearch'),
                document.getElementById('editSpLinkedVendorDropdown'),
                document.getElementById('editSpLinkedVendorId'),
                document.getElementById('editSpLinkedVendorSelected'),
                document.getElementById('editSpLinkedVendorLabel'),
                document.getElementById('editSpLinkedVendorClear')
            );

            // Add subprocessor submit
            var addSubmit = document.getElementById('addSpSubmit');
            if (addSubmit) {
                addSubmit.addEventListener('click', function() {
                    var name = document.getElementById('addSpName').value.trim();
                    if (!name) {
                        var err = document.getElementById('addSpError');
                        err.textContent = <?php echo json_encode(t('vendor-onboarding.js_sp_name_required')); ?>;
                        err.style.display = '';
                        return;
                    }
                    addSubmit.disabled = true;
                    var fd = new FormData();
                    fd.append('csrf_token', csrfToken);
                    fd.append('vendor_onboarding_id', '<?php echo $requestId; ?>');
                    var spId = document.getElementById('addSpId').value;
                    if (spId) fd.append('subprocessor_id', spId);
                    fd.append('subprocessor_name', name);
                    fd.append('subprocessor_domain', document.getElementById('addSpDomain').value.trim());
                    fd.append('country', document.getElementById('addSpCountry').value.trim());
                    fd.append('linked_vendor_id', document.getElementById('addSpLinkedVendorId').value);
                    fd.append('service_description', document.getElementById('addSpService').value.trim());
                    fd.append('data_shared', document.getElementById('addSpData').value.trim());
                    var xhr = new XMLHttpRequest();
                    xhr.addEventListener('load', function() {
                        addSubmit.disabled = false;
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.csrf_token) updateAllCsrfTokens(resp.csrf_token);
                            if (resp.success) {
                                window.location.href = 'vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=subprocessors';
                            } else {
                                var err = document.getElementById('addSpError');
                                err.textContent = resp.message || <?php echo json_encode(t('vendor-onboarding.js_add_sp_failed')); ?>;
                                err.style.display = '';
                            }
                        } catch(e) {
                            var err = document.getElementById('addSpError');
                            err.textContent = <?php echo json_encode(t('vendor-onboarding.js_add_sp_failed')); ?>;
                            err.style.display = '';
                        }
                    });
                    xhr.addEventListener('error', function() {
                        addSubmit.disabled = false;
                        var err = document.getElementById('addSpError');
                        err.textContent = <?php echo json_encode(t('vendor-onboarding.js_network_error')); ?>;
                        err.style.display = '';
                    });
                    xhr.open('POST', 'api/vendor-subprocessor-add.php');
                    xhr.send(fd);
                });
            }

            // Edit subprocessor submit
            var editSubmit = document.getElementById('editSpSubmit');
            if (editSubmit) {
                editSubmit.addEventListener('click', function() {
                    var name = document.getElementById('editSpName').value.trim();
                    if (!name) {
                        var err = document.getElementById('editSpError');
                        err.textContent = <?php echo json_encode(t('vendor-onboarding.js_sp_name_required')); ?>;
                        err.style.display = '';
                        return;
                    }
                    editSubmit.disabled = true;
                    var fd = new FormData();
                    fd.append('csrf_token', csrfToken);
                    fd.append('mapping_id', document.getElementById('editSpMappingId').value);
                    fd.append('subprocessor_name', name);
                    fd.append('subprocessor_domain', document.getElementById('editSpDomain').value.trim());
                    fd.append('country', document.getElementById('editSpCountry').value.trim());
                    fd.append('linked_vendor_id', document.getElementById('editSpLinkedVendorId').value);
                    fd.append('service_description', document.getElementById('editSpService').value.trim());
                    fd.append('data_shared', document.getElementById('editSpData').value.trim());
                    var xhr = new XMLHttpRequest();
                    xhr.addEventListener('load', function() {
                        editSubmit.disabled = false;
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.csrf_token) updateAllCsrfTokens(resp.csrf_token);
                            if (resp.success) {
                                window.location.href = 'vendor-onboarding.php?id=<?php echo $requestId; ?>&tab=subprocessors';
                            } else {
                                var err = document.getElementById('editSpError');
                                err.textContent = resp.message || <?php echo json_encode(t('vendor-onboarding.js_update_sp_failed')); ?>;
                                err.style.display = '';
                            }
                        } catch(e) {
                            var err = document.getElementById('editSpError');
                            err.textContent = <?php echo json_encode(t('vendor-onboarding.js_update_sp_failed')); ?>;
                            err.style.display = '';
                        }
                    });
                    xhr.addEventListener('error', function() {
                        editSubmit.disabled = false;
                        var err = document.getElementById('editSpError');
                        err.textContent = <?php echo json_encode(t('vendor-onboarding.js_network_error')); ?>;
                        err.style.display = '';
                    });
                    xhr.open('POST', 'api/vendor-subprocessor-edit.php');
                    xhr.send(fd);
                });
            }
        })();

        // Toggle reply form visibility
        // Score dropdown toggle
        function toggleOnbScoreMenu() {
            var menu = document.getElementById('onbScoreMenu');
            if (menu) menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
        document.addEventListener('click', function(e) {
            var menu = document.getElementById('onbScoreMenu');
            var dropdown = document.getElementById('onbScoreDropdown');
            if (menu && dropdown && !dropdown.contains(e.target)) menu.style.display = 'none';

            // Severity filter buttons
            var btn = e.target.closest('[data-filter-severity]');
            if (btn) {
                var sev = btn.getAttribute('data-filter-severity');
                var tableId = btn.getAttribute('data-filter-table');
                var table = document.getElementById(tableId);
                if (!table) return;
                var rows = table.querySelectorAll('tbody tr[data-severity]');
                var isActive = btn.classList.contains('sev-filter-active');

                // Clear active state on ALL filter buttons targeting this table
                document.querySelectorAll('[data-filter-table="' + tableId + '"]').forEach(function(b) {
                    b.classList.remove('sev-filter-active');
                    b.style.outline = 'none';
                    b.style.boxShadow = 'none';
                });

                // Open the parent <details> if collapsed
                var details = table.closest('details');
                if (details && !details.open) details.open = true;

                if (isActive) {
                    // Remove filter — show all rows
                    rows.forEach(function(r) { r.style.display = ''; });
                } else {
                    // Apply filter — highlight all buttons for this severity+table
                    var firstChild = btn.querySelector('div');
                    var borderColor = btn.style.color || (firstChild && firstChild.style.color) || 'var(--theme-header-color)';
                    document.querySelectorAll('[data-filter-table="' + tableId + '"][data-filter-severity="' + sev + '"]').forEach(function(b) {
                        b.classList.add('sev-filter-active');
                        b.style.outline = '2px solid ' + borderColor;
                        b.style.boxShadow = '0 0 0 3px ' + borderColor + '22';
                    });
                    rows.forEach(function(r) {
                        r.style.display = r.getAttribute('data-severity') === sev ? '' : 'none';
                    });
                }

                // Scroll table into view
                table.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });

        function toggleReplyForm(caseId) {
            var form = document.getElementById('replyForm_' + caseId);
            if (form) {
                form.style.display = form.style.display === 'none' ? 'block' : 'none';
                if (form.style.display === 'block') {
                    var textarea = form.querySelector('textarea');
                    if (textarea) textarea.focus();
                }
            }
        }

        <?php if ($canAddNotes): ?>
        // Case note modal and assignee type-ahead
        (function() {
            var modal = document.getElementById('caseNoteModal');
            var openBtn = document.getElementById('createCaseNoteBtn');
            var cancelBtn = document.getElementById('caseNoteCancelBtn');
            if (!modal || !openBtn) return;

            var titleInput = document.getElementById('caseNoteTitle');
            var descInput = document.getElementById('caseNoteDescription');
            var dueDateInput = document.getElementById('caseNoteDueDate');
            var assigneeInput = document.getElementById('caseNoteAssigneeInput');
            var assigneeHidden = document.getElementById('caseNoteAssignedTo');
            var suggestionsBox = document.getElementById('caseNoteAssigneeSuggestions');
            var allowedGroups = <?php echo json_encode($allowedNoteGroups); ?>;

            function openModal() {
                titleInput.value = '';
                descInput.value = '';
                dueDateInput.value = '';
                assigneeInput.value = '';
                assigneeHidden.value = '';
                if (suggestionsBox) suggestionsBox.style.display = 'none';
                modal.style.display = 'flex';
                titleInput.focus();
            }

            function closeModal() {
                modal.style.display = 'none';
            }

            openBtn.addEventListener('click', openModal);
            cancelBtn.addEventListener('click', closeModal);

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
            });

            // Assignee type-ahead
            if (!assigneeInput || !suggestionsBox) return;

            var debounceTimer = null;
            var highlightedIdx = -1;
            var results = [];

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function searchAssignees(query) {
                if (query.length < 2) {
                    suggestionsBox.style.display = 'none';
                    return;
                }
                suggestionsBox.innerHTML = <?php echo json_encode(t('vendor-onboarding.js_searching_html')); ?>;
                suggestionsBox.style.display = 'block';

                var url = 'api/search-users.php?q=' + encodeURIComponent(query);
                if (allowedGroups) url += '&groups=' + encodeURIComponent(allowedGroups);
                var requestId = <?php echo $requestId ?: 0; ?>;
                if (requestId) url += '&request_id=' + requestId;

                fetch(url)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.users.length > 0) {
                            results = data.users;
                            highlightedIdx = -1;
                            renderSuggestions(data.users);
                        } else {
                            results = [];
                            suggestionsBox.innerHTML = <?php echo json_encode(t('vendor-onboarding.js_no_users_html')); ?>;
                        }
                    })
                    .catch(function() {
                        results = [];
                        suggestionsBox.innerHTML = <?php echo json_encode(t('vendor-onboarding.js_search_error_html')); ?>;
                    });
            }

            function renderSuggestions(users) {
                suggestionsBox.innerHTML = users.map(function(u, i) {
                    return '<div class="autocomplete-item" data-user-id="' + u.id + '" data-display="' + escapeHtml(u.display) + '" data-index="' + i + '">' +
                        '<div class="user-name">' + escapeHtml(u.display) + '</div>' +
                        (u.email ? '<div class="user-email">' + escapeHtml(u.email) + '</div>' : '') +
                        '</div>';
                }).join('');

                suggestionsBox.querySelectorAll('.autocomplete-item').forEach(function(item) {
                    item.addEventListener('click', function() { selectAssignee(item); });
                });
            }

            function selectAssignee(item) {
                assigneeHidden.value = item.dataset.userId;
                assigneeInput.value = item.dataset.display;
                suggestionsBox.style.display = 'none';
                results = [];
                highlightedIdx = -1;
            }

            function updateHighlight() {
                var items = suggestionsBox.querySelectorAll('.autocomplete-item');
                items.forEach(function(item, i) {
                    item.classList.toggle('highlighted', i === highlightedIdx);
                });
            }

            assigneeInput.addEventListener('input', function() {
                // Clear hidden ID when user changes text (invalidates stale selection)
                assigneeHidden.value = '';
                clearTimeout(debounceTimer);
                var val = this.value;
                debounceTimer = setTimeout(function() { searchAssignees(val); }, 300);
            });

            assigneeInput.addEventListener('keydown', function(e) {
                if (suggestionsBox.style.display === 'none') return;
                var items = suggestionsBox.querySelectorAll('.autocomplete-item');
                if (items.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlightedIdx = Math.min(highlightedIdx + 1, items.length - 1);
                    updateHighlight();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlightedIdx = Math.max(highlightedIdx - 1, 0);
                    updateHighlight();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (highlightedIdx >= 0 && items[highlightedIdx]) {
                        selectAssignee(items[highlightedIdx]);
                    }
                } else if (e.key === 'Escape') {
                    suggestionsBox.style.display = 'none';
                }
            });

            // Click outside suggestions to dismiss (not the modal)
            document.addEventListener('click', function(e) {
                if (!assigneeInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
                    suggestionsBox.style.display = 'none';
                }
            });
        })();

        // Case reassignment modal and type-ahead
        (function() {
            var modal = document.getElementById('caseReassignModal');
            if (!modal) return;

            var searchInput = document.getElementById('caseReassignSearch');
            var hiddenCaseId = document.getElementById('caseReassignCaseId');
            var hiddenUserId = document.getElementById('caseReassignUserId');
            var suggestionsBox = document.getElementById('caseReassignSuggestions');
            var submitBtn = document.getElementById('caseReassignSubmitBtn');
            var cancelBtn = document.getElementById('caseReassignCancelBtn');
            var allowedGroups = <?php echo json_encode($allowedNoteGroups); ?>;

            var debounceTimer = null;
            var highlightedIdx = -1;

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function openModal(caseId) {
                hiddenCaseId.value = caseId;
                hiddenUserId.value = '';
                searchInput.value = '';
                if (suggestionsBox) suggestionsBox.style.display = 'none';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                modal.style.display = 'flex';
                searchInput.focus();
            }

            function closeModal() {
                modal.style.display = 'none';
            }

            // Open via data-action buttons on case cards
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('[data-action="openCaseReassignModal"]');
                if (btn) {
                    openModal(btn.dataset.caseId);
                    return;
                }
            });

            cancelBtn.addEventListener('click', closeModal);

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
            });

            function searchUsers(query) {
                if (query.length < 2) {
                    suggestionsBox.style.display = 'none';
                    return;
                }
                suggestionsBox.innerHTML = <?php echo json_encode(t('vendor-onboarding.js_searching_html')); ?>;
                suggestionsBox.style.display = 'block';

                var url = 'api/search-users.php?q=' + encodeURIComponent(query);
                if (allowedGroups) url += '&groups=' + encodeURIComponent(allowedGroups);
                var requestId = <?php echo $requestId ?: 0; ?>;
                if (requestId) url += '&request_id=' + requestId;

                fetch(url)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.users.length > 0) {
                            highlightedIdx = -1;
                            suggestionsBox.innerHTML = data.users.map(function(u, i) {
                                return '<div class="autocomplete-item" data-user-id="' + u.id + '" data-display="' + escapeHtml(u.display) + '" data-index="' + i + '">' +
                                    '<div class="user-name">' + escapeHtml(u.display) + '</div>' +
                                    (u.email ? '<div class="user-email">' + escapeHtml(u.email) + '</div>' : '') +
                                    '</div>';
                            }).join('');
                            suggestionsBox.querySelectorAll('.autocomplete-item').forEach(function(item) {
                                item.addEventListener('click', function() { selectUser(item); });
                            });
                        } else {
                            suggestionsBox.innerHTML = <?php echo json_encode(t('vendor-onboarding.js_no_users_html')); ?>;
                        }
                    })
                    .catch(function() {
                        suggestionsBox.innerHTML = <?php echo json_encode(t('vendor-onboarding.js_search_error_html')); ?>;
                    });
            }

            function selectUser(item) {
                hiddenUserId.value = item.dataset.userId;
                searchInput.value = item.dataset.display;
                suggestionsBox.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                highlightedIdx = -1;
            }

            searchInput.addEventListener('input', function() {
                hiddenUserId.value = '';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                clearTimeout(debounceTimer);
                var val = this.value;
                debounceTimer = setTimeout(function() { searchUsers(val); }, 300);
            });

            searchInput.addEventListener('keydown', function(e) {
                if (suggestionsBox.style.display === 'none') return;
                var items = suggestionsBox.querySelectorAll('.autocomplete-item');
                if (items.length === 0) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlightedIdx = Math.min(highlightedIdx + 1, items.length - 1);
                    items.forEach(function(item, i) { item.classList.toggle('highlighted', i === highlightedIdx); });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlightedIdx = Math.max(highlightedIdx - 1, 0);
                    items.forEach(function(item, i) { item.classList.toggle('highlighted', i === highlightedIdx); });
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (highlightedIdx >= 0 && items[highlightedIdx]) selectUser(items[highlightedIdx]);
                } else if (e.key === 'Escape') {
                    suggestionsBox.style.display = 'none';
                }
            });

            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
                    suggestionsBox.style.display = 'none';
                }
            });

            document.getElementById('caseReassignForm').addEventListener('submit', function(e) {
                if (!hiddenUserId.value) { e.preventDefault(); alert(<?php echo json_encode(t('vendor-onboarding.js_select_user_assign')); ?>); }
            });
        })();
        <?php endif; ?>

    </script>

    <?php if ($editMode && $canEditTier): ?>
    <!-- Tier Change Modal -- assign or reassign vendor risk tier without
         leaving the onboarding page. Justification required because compliance. -->
    <div id="tierChangeModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;"><?php echo empty($request['vendor_tier']) ? t('vendor-onboarding.tier_assign') : t('vendor-onboarding.tier_change'); ?> <?php echo e(t('vendor-onboarding.vendor_risk_tier_suffix')); ?></h3>

            <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>" id="tierChangeForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="update_tier" value="1">

                <div style="margin-bottom: 20px;">
                    <label for="new_tier" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;"><?php echo e(t('vendor-onboarding.label_tier_colon')); ?></label>
                    <select id="new_tier" name="new_tier" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value=""><?php echo e(t('vendor-onboarding.not_assigned')); ?></option>
                        <option value="1" <?php echo ($request['vendor_tier'] ?? '') === '1' ? 'selected' : ''; ?>><?php echo e(t('vendor-onboarding.tier1_full')); ?></option>
                        <option value="2" <?php echo ($request['vendor_tier'] ?? '') === '2' ? 'selected' : ''; ?>><?php echo e(t('vendor-onboarding.tier2_full')); ?></option>
                        <option value="3" <?php echo ($request['vendor_tier'] ?? '') === '3' ? 'selected' : ''; ?>><?php echo e(t('vendor-onboarding.tier3_full')); ?></option>
                    </select>
                </div>

                <div style="margin-bottom: 20px;">
                    <label for="tier_justification" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;"><?php echo e(t('vendor-onboarding.label_justification_colon')); ?> <span style="color: #dc2626;">*</span></label>
                    <textarea id="tier_justification" name="tier_justification" rows="4" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-family: inherit;" placeholder="<?php echo e(t('vendor-onboarding.tier_reason_prefix')); ?> <?php echo empty($request['vendor_tier']) ? t('vendor-onboarding.word_assignment') : t('vendor-onboarding.word_change'); ?>..." required></textarea>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" data-action="closeTierModal" style="padding: 10px 20px; border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 14px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                    <button type="submit" style="padding: 10px 20px; border: none; background: <?php echo empty($request['vendor_tier']) ? '#dc2626' : '#f59e0b'; ?>; color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500;">
                        <?php echo empty($request['vendor_tier']) ? t('vendor-onboarding.btn_assign_tier') : t('vendor-onboarding.btn_update_tier'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        function openTierModal() {
            document.getElementById('tier_justification').value = '';
            document.getElementById('tierChangeModal').style.display = 'flex';
            document.getElementById('new_tier').focus();
        }

        function closeTierModal() {
            document.getElementById('tierChangeModal').style.display = 'none';
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeTierModal();
        });

        document.getElementById('tierChangeForm').addEventListener('submit', function(e) {
            if (!document.getElementById('tier_justification').value.trim()) {
                e.preventDefault();
                alert(<?php echo json_encode(t('vendor-onboarding.js_tier_justification')); ?>);
                document.getElementById('tier_justification').focus();
            }
        });
    </script>
    <?php endif; ?>

    <?php if ($editMode && $canAssignStakeholders): ?>
    <!-- Stakeholder Reassign Modal -->
    <div id="reassignModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;"><?php echo e(t('vendor-onboarding.modal_reassign_stakeholder')); ?></h3>

            <form method="POST" action="vendor-onboarding.php?id=<?php echo $requestId; ?>" id="reassignForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="reassign_stakeholder" value="1">
                <input type="hidden" name="stakeholder_user_id" id="reassignUserId" value="">

                <div style="margin-bottom: 20px;">
                    <label for="reassignSearch" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;"><?php echo e(t('vendor-onboarding.label_search_user')); ?></label>
                    <div style="position: relative;">
                        <input type="text" id="reassignSearch" placeholder="<?php echo e(t('vendor-onboarding.placeholder_type_name_username')); ?>" autocomplete="off"
                            style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-family: inherit;">
                        <div id="reassignSuggestions" class="autocomplete-suggestions" style="display: none;"></div>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" data-action="closeReassignModal" style="padding: 10px 20px; border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 14px;"><?php echo e(t('vendor-onboarding.btn_cancel')); ?></button>
                    <button type="submit" id="reassignSubmitBtn" style="padding: 10px 20px; border: none; background: #6b7280; color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; opacity: 0.5;" disabled><?php echo e(t('vendor-onboarding.btn_reassign')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        // Reassign modal
        (function() {
            var modal = document.getElementById('reassignModal');
            var searchInput = document.getElementById('reassignSearch');
            var hiddenInput = document.getElementById('reassignUserId');
            var suggestionsBox = document.getElementById('reassignSuggestions');
            var submitBtn = document.getElementById('reassignSubmitBtn');
            if (!modal) return;

            var debounceTimer = null;
            var highlightedIdx = -1;
            var currentResults = [];

            function openModal() {
                searchInput.value = '';
                hiddenInput.value = '';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                if (suggestionsBox) suggestionsBox.style.display = 'none';
                modal.style.display = 'flex';
                searchInput.focus();
            }
            function closeModal() {
                modal.style.display = 'none';
            }

            // Use event delegation for open button
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('[data-action="openReassignModal"]');
                if (btn) openModal();
                var closeBtn = e.target.closest('[data-action="closeReassignModal"]');
                if (closeBtn) closeModal();
                if (e.target === modal) closeModal();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
            });

            // Typeahead search
            function searchUsers(query) {
                if (query.length < 2) { suggestionsBox.style.display = 'none'; return; }
                suggestionsBox.innerHTML = <?php echo json_encode(t('vendor-onboarding.js_searching_html')); ?>;
                suggestionsBox.style.display = 'block';

                fetch('api/search-users.php?q=' + encodeURIComponent(query) + '&request_id=<?php echo $requestId; ?>')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.users.length > 0) {
                            currentResults = data.users;
                            highlightedIdx = -1;
                            renderSuggestions(data.users);
                        } else {
                            currentResults = [];
                            suggestionsBox.innerHTML = <?php echo json_encode(t('vendor-onboarding.js_no_users_html')); ?>;
                        }
                    })
                    .catch(function() {
                        currentResults = [];
                        suggestionsBox.innerHTML = <?php echo json_encode(t('vendor-onboarding.js_search_error_html')); ?>;
                    });
            }

            function renderSuggestions(users) {
                suggestionsBox.innerHTML = users.map(function(u, i) {
                    var nameDiv = document.createElement('div');
                    nameDiv.textContent = u.display;
                    var emailPart = u.email ? '<div class="user-email">' + escapeHtml(u.email) + '</div>' : '';
                    return '<div class="autocomplete-item" data-user-id="' + u.id + '" data-display="' + escapeHtml(u.display) + '" data-index="' + i + '"><div class="user-name">' + escapeHtml(u.display) + '</div>' + emailPart + '</div>';
                }).join('');
                suggestionsBox.querySelectorAll('.autocomplete-item').forEach(function(item) {
                    item.addEventListener('click', function() { selectUser(item); });
                });
            }

            function selectUser(item) {
                hiddenInput.value = item.dataset.userId;
                searchInput.value = item.dataset.display;
                suggestionsBox.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.background = 'var(--theme-header-color)';
            }

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text || '';
                return div.innerHTML;
            }

            searchInput.addEventListener('input', function() {
                hiddenInput.value = '';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.background = '#6b7280';
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() { searchUsers(searchInput.value); }, 300);
            });

            searchInput.addEventListener('keydown', function(e) {
                if (suggestionsBox.style.display === 'none') return;
                var items = suggestionsBox.querySelectorAll('.autocomplete-item');
                if (items.length === 0) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlightedIdx = Math.min(highlightedIdx + 1, items.length - 1);
                    items.forEach(function(it, i) { it.classList.toggle('highlighted', i === highlightedIdx); });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlightedIdx = Math.max(highlightedIdx - 1, 0);
                    items.forEach(function(it, i) { it.classList.toggle('highlighted', i === highlightedIdx); });
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (highlightedIdx >= 0 && items[highlightedIdx]) selectUser(items[highlightedIdx]);
                } else if (e.key === 'Escape') {
                    suggestionsBox.style.display = 'none';
                }
            });

            document.addEventListener('click', function(e) {
                if (suggestionsBox && !searchInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
                    suggestionsBox.style.display = 'none';
                }
            });

            document.getElementById('reassignForm').addEventListener('submit', function(e) {
                if (!hiddenInput.value) { e.preventDefault(); alert(<?php echo json_encode(t('vendor-onboarding.js_select_user_assign')); ?>); }
            });
        })();
    </script>
    <?php endif; ?>

    <?php if (($canEdit || $canEditProcurementFields) && $editMode): ?>
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        var editing = false;
        var toggleBtn = document.getElementById('toggleEditBtn');
        var editActions = document.getElementById('editActions');
        var cancelBtn = document.getElementById('cancelEditBtn');
        if (!toggleBtn) return;

        function setEditMode(on) {
            editing = on;
            document.querySelectorAll('.vendor-field-display').forEach(function(el) { el.style.display = on ? 'none' : ''; });
            document.querySelectorAll('.vendor-field-edit').forEach(function(el) { el.style.display = on ? '' : 'none'; });
            if (editActions) editActions.style.display = on ? 'flex' : 'none';
            toggleBtn.style.display = on ? 'none' : '';
        }

        toggleBtn.addEventListener('click', function() { setEditMode(true); });
        if (cancelBtn) cancelBtn.addEventListener('click', function() { setEditMode(false); });

        // "Add VAT" buttons (shown when no VAT is on file) jump straight into edit
        // mode and focus the VAT field.
        document.querySelectorAll('.vat-add-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setEditMode(true);
                var t = btn.getAttribute('data-vat-target');
                var inp = t && document.getElementById(t + '_primary');
                if (inp) { try { inp.focus(); } catch (e) {} }
            });
        });
    })();
    </script>
    <?php endif; ?>

    <?php if (false): // Score tab JS removed — Score tab replaced with link to vendor-srs-details.php ?>
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        var gradeClasses = {A:'grade-a',B:'grade-b',C:'grade-c',D:'grade-d',F:'grade-f'};
        var perPage = 10;
        function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
        function pagHtml(page, totalPages, fnName, total, start, end) {
            var pag = '<span style="font-size:12px;color:#6b7280;">Showing ' + (start+1) + '-' + Math.min(end, total) + ' of ' + total + '</span>';
            if (totalPages > 1) {
                pag += '<span style="display:flex;gap:4px;">';
                if (page > 1) pag += '<button data-action="' + fnName + '" data-arg="' + (page-1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">&laquo; Prev</button>';
                for (var i = 1; i <= totalPages; i++) {
                    if (i === page) pag += '<button style="padding:3px 10px;border:1px solid var(--theme-header-color,#2563eb);border-radius:4px;background:var(--theme-header-color,#2563eb);color:white;font-size:12px;cursor:default;">' + i + '</button>';
                    else pag += '<button data-action="' + fnName + '" data-arg="' + i + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">' + i + '</button>';
                }
                if (page < totalPages) pag += '<button data-action="' + fnName + '" data-arg="' + (page+1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">Next &raquo;</button>';
                pag += '</span>';
            }
            return pag;
        }

        // UpGuard history
        <?php if (count($vendorSrsScoreHistory) > 1): ?>
        var ugHistData_score = <?php echo json_encode(array_values(array_map(function($h) use ($srsService, $upguardDisplayMode_score, $upguardMaxScore_score) {
            $grade = $srsService->calculateGrade(intval($h['score']));
            return [
                'date' => date('M j, Y g:i A', strtotime($h['scored_at'])),
                'score' => displayUpguardScore(intval($h['score']), $upguardDisplayMode_score, $upguardMaxScore_score),
                'grade' => $grade,
                'critical' => intval($h['critical_risks'] ?? 0),
                'high' => intval($h['high_risks']),
                'medium' => intval($h['medium_risks']),
                'low' => intval($h['low_risks']),
                'info' => intval($h['info_risks']),
            ];
        }, $vendorSrsScoreHistory))); ?>;
        var ugTotalPages_score = Math.ceil(ugHistData_score.length / perPage);
        function renderUgHist_score(page) {
            var start = (page - 1) * perPage;
            var slice = ugHistData_score.slice(start, start + perPage);
            var html = '';
            slice.forEach(function(h) {
                var gc = gradeClasses[h.grade] || 'grade-f';
                html += '<tr><td style="padding:5px 8px;">' + esc(h.date) + '</td><td style="padding:5px 8px;"><strong>' + esc(String(h.score)) + '</strong></td>'
                    + '<td style="padding:5px 8px;"><span class="score-grade ' + gc + '" style="font-size:16px;">' + esc(h.grade) + '</span></td>'
                    + '<td style="padding:5px 8px;">' + h.critical + '</td><td style="padding:5px 8px;">' + h.high + '</td><td style="padding:5px 8px;">' + h.medium + '</td><td style="padding:5px 8px;">' + h.low + '</td><td style="padding:5px 8px;">' + h.info + '</td></tr>';
            });
            document.getElementById('ugHistTbody_score').innerHTML = html;
            document.getElementById('ugHistPagination_score').innerHTML = pagHtml(page, ugTotalPages_score, 'renderUgHist_score', ugHistData_score.length, start, start + perPage);
        }
        window.renderUgHist_score = renderUgHist_score;
        <?php endif; ?>

        // Shodan history
        <?php if (count($vendorShodanScoreHistory) > 1): ?>
        var hasTL_score = <?php echo json_encode(!empty($vendorShodanScoreHistory[0]['traffic_light'])); ?>;
        var shHistData_score = <?php echo json_encode(array_values(array_map(function($sh) use ($shodanService) {
            $grade = $shodanService->calculateGrade(intval($sh['score']));
            return [
                'date' => date('M j, Y g:i A', strtotime($sh['scored_at'])),
                'score' => intval($sh['score']),
                'grade' => $grade,
                'traffic_light' => $sh['traffic_light'] ?? null,
                'open_ports' => intval($sh['open_ports_count'] ?? 0),
                'vulns' => intval($sh['vuln_count'] ?? 0),
                'critical' => intval($sh['critical_vulns'] ?? 0),
                'high' => intval($sh['high_vulns'] ?? 0),
            ];
        }, $vendorShodanScoreHistory))); ?>;
        var shTotalPages_score = Math.ceil(shHistData_score.length / perPage);
        var tlColors_score = {green:'#16a34a', yellow:'#eab308', red:'#dc2626'};
        var riskLabels_score = {green:<?php echo json_encode(t('vendor-onboarding.tl_acceptable')); ?>, yellow:<?php echo json_encode(t('vendor-onboarding.tl_needs_improvement')); ?>, red:<?php echo json_encode(t('vendor-onboarding.tl_unacceptable')); ?>};
        function renderShHist_score(page) {
            var start = (page - 1) * perPage;
            var slice = shHistData_score.slice(start, start + perPage);
            var html = '';
            slice.forEach(function(h) {
                var gc = gradeClasses[h.grade] || 'grade-f';
                var tlHtml = '';
                if (hasTL_score) {
                    if (h.traffic_light) {
                        var c = tlColors_score[h.traffic_light] || '#9ca3af';
                        var label = riskLabels_score[h.traffic_light] || h.traffic_light;
                        tlHtml = '<td style="padding:5px 8px;"><span style="display:inline-flex;align-items:center;gap:5px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + c + ';box-shadow:0 0 4px ' + c + ';"></span>' + label + '</span></td>';
                    } else tlHtml = '<td style="padding:5px 8px;">-</td>';
                }
                html += '<tr><td style="padding:5px 8px;">' + esc(h.date) + '</td><td style="padding:5px 8px;"><strong>' + h.score + '%</strong></td>'
                    + '<td style="padding:5px 8px;"><span class="score-grade ' + gc + '" style="font-size:16px;">' + esc(h.grade) + '</span></td>'
                    + tlHtml
                    + '<td style="padding:5px 8px;">' + h.open_ports + '</td><td style="padding:5px 8px;">' + h.vulns + '</td><td style="padding:5px 8px;">' + h.critical + '</td><td style="padding:5px 8px;">' + h.high + '</td></tr>';
            });
            document.getElementById('shHistTbody_score').innerHTML = html;
            document.getElementById('shHistPagination_score').innerHTML = pagHtml(page, shTotalPages_score, 'renderShHist_score', shHistData_score.length, start, start + perPage);
        }
        window.renderShHist_score = renderShHist_score;
        <?php endif; ?>

        // Toggle score history collapsible
        var histInitialized_score = false;
        window.toggleScoreHistory_score = function() {
            var body = document.getElementById('scoreHistBody_score');
            var arrow = document.getElementById('scoreHistArrow_score');
            if (!body) return;
            var showing = body.style.display === 'none';
            body.style.display = showing ? '' : 'none';
            if (arrow) arrow.style.transform = showing ? 'rotate(180deg)' : 'rotate(0deg)';
            if (showing && !histInitialized_score) {
                histInitialized_score = true;
                <?php if (count($vendorSrsScoreHistory) > 1): ?>renderUgHist_score(1);<?php endif; ?>
                <?php if (count($vendorShodanScoreHistory) > 1): ?>renderShHist_score(1);<?php endif; ?>
            }
        };

        // Toggle history sub-tabs (UpGuard/Shodan)
        window.toggleHistoryTab_score = function(tab) {
            var ugDiv = document.getElementById('historyUpguard_score');
            var shDiv = document.getElementById('historyShodan_score');
            var ugBtn = document.getElementById('histTabUpguard_score');
            var shBtn = document.getElementById('histTabShodan_score');
            if (tab === 'upguard') {
                if (ugDiv) ugDiv.style.display = '';
                if (shDiv) shDiv.style.display = 'none';
                if (ugBtn) { ugBtn.style.background = 'var(--theme-header-color)'; ugBtn.style.color = 'white'; }
                if (shBtn) { shBtn.style.background = 'white'; shBtn.style.color = '#374151'; }
            } else {
                if (ugDiv) ugDiv.style.display = 'none';
                if (shDiv) shDiv.style.display = '';
                if (shBtn) { shBtn.style.background = 'var(--theme-header-color)'; shBtn.style.color = 'white'; }
                if (ugBtn) { ugBtn.style.background = 'white'; ugBtn.style.color = '#374151'; }
            }
        };
    })();
    </script>

    <?php if (count($vendorSrsTrendData) >= 1 || count($vendorShodanTrendData) >= 1): ?>
    <!-- Chart.js for score trend (load before procurement Chart.js to avoid duplicate) -->
    <script src="app/js/chart.min.js" nonce="<?php echo cspNonce(); ?>"></script>
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        var scoreChartInited = false;
        var trendData = <?php echo json_encode($vendorSrsTrendData); ?>;
        var shodanData = <?php echo json_encode($vendorShodanTrendData); ?>;
        var maxScore = <?php echo intval($upguardMaxScore_score); ?>;
        var displayMode = <?php echo json_encode($upguardDisplayMode_score); ?>;
        var themeColor = <?php echo json_encode($theme['header_color']); ?>;
        var ugName = <?php echo json_encode($upguardName_score); ?>;
        var shName = <?php echo json_encode($shodanName_score); ?>;

        function initScoreTrendChart() {
            if (scoreChartInited) return;
            scoreChartInited = true;
            try {
                var isPercentage = displayMode === 'percentage';
                if ((!trendData || trendData.length === 0) && (!shodanData || shodanData.length === 0)) return;

                var canvas = document.getElementById('scoreTrendChart');
                if (!canvas) return;
                if (typeof Chart === 'undefined') {
                    canvas.parentElement.innerHTML = <?php echo json_encode(t('vendor-onboarding.js_chart_unavailable_html')); ?>;
                    return;
                }

                var allDates = {};
                if (trendData) trendData.forEach(function(d) { allDates[d.date] = true; });
                if (shodanData) shodanData.forEach(function(d) { allDates[d.date] = true; });
                var labels = Object.keys(allDates).sort();

                var datasets = [];
                var hasUpguard = trendData && trendData.length > 0;

                if (hasUpguard) {
                    var upguardMap = {};
                    trendData.forEach(function(d) {
                        upguardMap[d.date] = isPercentage && maxScore > 0
                            ? Math.floor((d.score / maxScore) * 100) : d.score;
                    });
                    datasets.push({
                        label: isPercentage ? ugName + ' Score (%)' : ugName + ' Score',
                        data: labels.map(function(d) { return upguardMap[d] !== undefined ? upguardMap[d] : null; }),
                        borderColor: themeColor,
                        backgroundColor: themeColor + '20',
                        fill: false, tension: 0.3, pointRadius: 6, pointHoverRadius: 8,
                        borderWidth: 2, spanGaps: true, yAxisID: 'y'
                    });
                }

                if (shodanData && shodanData.length > 0) {
                    var shodanMap = {};
                    shodanData.forEach(function(d) { shodanMap[d.date] = d.score; });
                    datasets.push({
                        label: shName + ' Score (%)',
                        data: labels.map(function(d) { return shodanMap[d] !== undefined ? shodanMap[d] : null; }),
                        borderColor: '#0ea5e9',
                        backgroundColor: '#0ea5e920',
                        fill: false, tension: 0.3, pointRadius: 6, pointHoverRadius: 8,
                        borderWidth: 2, borderDash: hasUpguard ? [5, 3] : [],
                        spanGaps: true, yAxisID: hasUpguard ? 'y2' : 'y'
                    });
                }

                var hasBoth = hasUpguard && shodanData && shodanData.length > 0;
                if (hasBoth) {
                    var ugPctMap = {};
                    trendData.forEach(function(d) { ugPctMap[d.date] = maxScore > 0 ? Math.floor((d.score / maxScore) * 100) : 0; });
                    var shMap2 = {};
                    shodanData.forEach(function(d) { shMap2[d.date] = d.score; });
                    var avgData = labels.map(function(d) {
                        var ug = ugPctMap[d]; var sh = shMap2[d];
                        if (ug !== undefined && sh !== undefined) return Math.round((ug + sh) / 2);
                        if (ug !== undefined) return ug;
                        if (sh !== undefined) return sh;
                        return null;
                    });
                    datasets.push({
                        label: 'Avg Score (%)',
                        data: avgData,
                        borderColor: '#8b5cf6', backgroundColor: '#8b5cf620',
                        fill: false, tension: 0.3, pointRadius: 4, pointHoverRadius: 6,
                        borderWidth: 2, borderDash: [2, 2], spanGaps: true, yAxisID: 'y2'
                    });
                }

                var ctx = canvas.getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: { labels: labels, datasets: datasets },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        scales: {
                            y: {
                                min: 0, max: (hasUpguard ? (isPercentage ? 100 : maxScore) : 100),
                                position: 'left',
                                title: { display: true, text: hasBoth ? (isPercentage ? ugName + ' (%)' : ugName + ' Score') : (hasUpguard ? (isPercentage ? 'Score (%)' : 'Score') : shName + ' (%)') }
                            },
                            y2: {
                                min: 0, max: 100, position: 'right', display: hasBoth,
                                title: { display: hasBoth, text: shName + ' / Avg (%)' },
                                grid: { drawOnChartArea: false }
                            },
                            x: { title: { display: true, text: 'Date' } }
                        },
                        plugins: {
                            legend: { display: datasets.length > 1 },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        var color = context.dataset.borderColor;
                                        var isPctDs = color === '#0ea5e9' || color === '#8b5cf6';
                                        var suffix = isPctDs ? '%' : (isPercentage ? '%' : '');
                                        return context.dataset.label + ': ' + context.parsed.y + suffix;
                                    }
                                }
                            }
                        }
                    }
                });
            } catch (e) { console.error('Score trend chart error:', e); }
        }

        // Lazy-init: render chart on first tab show
        var origShowTab = window.showVendorTab;
        window.showVendorTab = function(section) {
            origShowTab(section);
            if (section === 'score') initScoreTrendChart();
        };
        // Also init if score tab is the active tab on load
        <?php if ($activeTab === 'score'): ?>
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initScoreTrendChart);
        } else {
            initScoreTrendChart();
        }
        <?php endif; ?>
    })();
    </script>
    <?php endif; ?>
    <?php endif; ?>

    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <script src="app/js/phone-input.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <script src="app/js/vat-input.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <script nonce="<?php echo cspNonce(); ?>">
    // Procurement Onboarding -> scoring-disabled banner: toggle live when staff
    // change the Yes/No field in edit mode (server already renders it for the
    // saved value on load).
    (function () {
        var banner = document.getElementById('procurementScoringBanner');
        if (!banner) return;
        function sync() {
            var sel = document.querySelector('select[name="vsu_onboarded"]');
            // In edit mode the select drives it; otherwise keep the server-rendered state.
            if (sel) banner.style.display = (sel.value === 'no') ? 'flex' : 'none';
        }
        document.addEventListener('change', function (e) {
            if (e.target && e.target.name === 'vsu_onboarded') sync();
        });
        sync();
    })();
    </script>

    <?php if ($isProcurementOnly && $showVendorTabs): ?>
    <!-- Chart.js for procurement detailed summary report -->
    <script src="app/js/chart.min.js" nonce="<?php echo cspNonce(); ?>"></script>
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        var themeColor = <?php echo json_encode($headerColor); ?>;
        var themeColorAlpha = themeColor + '33';

        // === NIST CSF Radar Chart ===
        var nistCtx = document.getElementById('procNistRadarChart');
        if (nistCtx) {
            new Chart(nistCtx, {
                type: 'radar',
                data: {
                    labels: ['Identify', 'Protect', 'Detect', 'Respond', 'Recover'],
                    datasets: [{
                        label: 'NIST CSF Score',
                        data: <?php echo json_encode(array_values($nistScores)); ?>,
                        borderColor: themeColor,
                        backgroundColor: themeColorAlpha,
                        borderWidth: 2,
                        pointBackgroundColor: themeColor,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 1,
                    scales: {
                        r: {
                            beginAtZero: true,
                            max: 100,
                            ticks: { stepSize: 20, font: { size: 9 } },
                            pointLabels: { font: { size: 11, weight: 'bold' } }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: 'NIST CSF 2.0 Maturity', font: { size: 13 } }
                    }
                }
            });
        }

        // === Score Trend Line Chart ===
        <?php if (!empty($trendLabels)): ?>
        var trendCtx = document.getElementById('procTrendChart');
        if (trendCtx) {
            var datasets = [];
            <?php if (!empty($ugTrendData)): ?>
            datasets.push({
                label: <?php echo json_encode($upguardDisplayName . ' (normalized %)'); ?>,
                data: <?php echo json_encode($ugTrendData); ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,0.1)',
                fill: false,
                tension: 0.3,
                pointRadius: 2,
                spanGaps: true
            });
            <?php endif; ?>
            <?php if (!empty($shTrendData)): ?>
            datasets.push({
                label: <?php echo json_encode($shodanDisplayName . ' (%)'); ?>,
                data: <?php echo json_encode($shTrendData); ?>,
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245,158,11,0.1)',
                fill: false,
                tension: 0.3,
                pointRadius: 2,
                spanGaps: true
            });
            <?php endif; ?>
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($trendLabels); ?>,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: { beginAtZero: true, max: 100, title: { display: true, text: 'Score (%)' } },
                        x: { ticks: { maxTicksToShow: 12, font: { size: 8 } } }
                    },
                    plugins: {
                        legend: { position: 'top', labels: { font: { size: 9 } } }
                    }
                }
            });
        }
        <?php endif; ?>

        // === Severity Doughnut Chart ===
        <?php if (array_sum($severityCounts) > 0): ?>
        var sevCtx = document.getElementById('procSeverityChart');
        if (sevCtx) {
            new Chart(sevCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Critical', 'High', 'Medium', 'Low', 'Info'],
                    datasets: [{
                        data: <?php echo json_encode(array_values($severityCounts)); ?>,
                        backgroundColor: ['#dc2626', '#ea580c', '#d97706', '#2563eb', '#6b7280'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 1.4,
                    plugins: {
                        legend: { position: 'right', labels: { font: { size: 9 }, padding: 8 } }
                    }
                }
            });
        }
        <?php endif; ?>

        // === Category Scores Horizontal Bar Chart ===
        <?php if (!empty($ugCategoryScores) || !empty($shCategoryScores)): ?>
        var catCtx = document.getElementById('procCategoryChart');
        if (catCtx) {
            var allCats = {};
            <?php foreach ($ugCategoryScores as $cat => $val): ?>
            allCats[<?php echo json_encode(ucwords(str_replace('_', ' ', $cat))); ?>] = { ug: <?php echo (int)$val; ?>, sh: 0 };
            <?php endforeach; ?>
            <?php foreach ($shCategoryScores as $cat => $val): ?>
            var catLabel = <?php echo json_encode(ucwords(str_replace('_', ' ', $cat))); ?>;
            if (!allCats[catLabel]) allCats[catLabel] = { ug: 0, sh: 0 };
            allCats[catLabel].sh = <?php echo (int)$val; ?>;
            <?php endforeach; ?>

            var catLabels = Object.keys(allCats);
            var ugData = catLabels.map(function(c) { return allCats[c].ug; });
            var shData = catLabels.map(function(c) { return allCats[c].sh; });

            var catDatasets = [];
            if (ugData.some(function(v) { return v > 0; })) {
                catDatasets.push({
                    label: <?php echo json_encode($upguardDisplayName); ?>,
                    data: ugData,
                    backgroundColor: 'rgba(59,130,246,0.7)',
                    borderRadius: 3
                });
            }
            if (shData.some(function(v) { return v > 0; })) {
                catDatasets.push({
                    label: <?php echo json_encode($shodanDisplayName); ?>,
                    data: shData,
                    backgroundColor: 'rgba(245,158,11,0.7)',
                    borderRadius: 3
                });
            }

            new Chart(catCtx, {
                type: 'bar',
                data: { labels: catLabels, datasets: catDatasets },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        x: { beginAtZero: true, max: 100, title: { display: true, text: 'Score' } }
                    },
                    plugins: {
                        legend: { position: 'top', labels: { font: { size: 9 } } }
                    }
                }
            });
        }
        <?php endif; ?>

        // === Pre-capture chart canvases as static images ===
        function procCaptureCharts() {
            var images = {};
            ['procNistRadarChart', 'procTrendChart', 'procSeverityChart', 'procShCatChart'].forEach(function(id) {
                var canvas = document.getElementById(id);
                if (canvas && canvas.width > 0 && canvas.height > 0) {
                    try { images[id] = canvas.toDataURL('image/png'); } catch (e) {}
                }
            });
            return images;
        }

        // === Replace canvas elements with pre-captured images in cloned HTML ===
        function procReplaceCanvases(container, chartImages) {
            Object.keys(chartImages).forEach(function(id) {
                var canvas = container.querySelector('#' + id);
                if (canvas) {
                    var img = document.createElement('img');
                    img.src = chartImages[id];
                    img.style.maxWidth = '100%';
                    img.style.height = 'auto';
                    canvas.parentNode.replaceChild(img, canvas);
                }
            });
        }

        // === Print / Save as PDF — opens clean popup window ===
        var procPrintBtn = document.getElementById('procPrintBtn');
        if (procPrintBtn) {
            procPrintBtn.addEventListener('click', function() {
                var chartImages = procCaptureCharts();

                var reportEl = document.querySelector('.proc-report');
                if (!reportEl) return;
                var reportHtml = reportEl.querySelector('.proc-container').innerHTML;
                // Strip the action bar and empty commentary placeholder from cloned content
                var tmp = document.createElement('div');
                tmp.innerHTML = reportHtml;
                var actionBar = tmp.querySelector('.proc-action-bar');
                if (actionBar) actionBar.remove();
                var emptyPlaceholder = tmp.querySelector('#procCommentaryPlaceholder');
                if (emptyPlaceholder) emptyPlaceholder.remove();
                procReplaceCanvases(tmp, chartImages);
                reportHtml = tmp.innerHTML;

                // Collect inline styles from the proc-report stylesheet
                var styles = '';
                document.querySelectorAll('style').forEach(function(s) {
                    if (s.textContent.indexOf('proc-report') !== -1 || s.textContent.indexOf('proc-container') !== -1) {
                        styles += s.textContent;
                    }
                });

                var w = window.open('', '_blank');
                if (!w) { alert(<?php echo json_encode(t('vendor-onboarding.js_allow_popups')); ?>); return; }
                w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8">'
                    + '<title>Vendor Detailed Assessment — <?php echo e(addslashes($request['vendor_name'] ?? '')); ?></title>'
                    + '<style>'
                    + 'body { margin: 0; padding: 0; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 10pt; line-height: 1.5; color: #1f2937; }'
                    + '.proc-container { max-width: 8.5in; margin: 0 auto; padding: 20px; }'
                    + '.print-toolbar { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 12px 20px; display: flex; gap: 10px; align-items: center; font-family: sans-serif; }'
                    + '.print-toolbar button { padding: 8px 20px; background: #1e3a5f; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }'
                    + '@media print { @page { size: letter; margin: 0.6in 0.5in 0.8in 0.5in; } body { margin: 0; padding: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; } .no-print { display: none !important; } .proc-page-break { page-break-after: always; } .proc-avoid-break { page-break-inside: avoid; } .print-toolbar { display: none !important; } }'
                    + styles
                    + '</style></head><body>'
                    + '<div class="print-toolbar no-print">'
                    + '<button id="printBtn">' + <?php echo json_encode(t('vendor-onboarding.btn_print_pdf')); ?> + '</button>'
                    + '<span style="color: #64748b; font-size: 12px;">' + <?php echo json_encode(t('vendor-onboarding.js_print_hint')); ?> + '</span>'
                    + '</div>'
                    + '<div class="proc-report"><div class="proc-container">' + reportHtml + '</div></div>'
                    + '<script>document.getElementById("printBtn").addEventListener("click", function() { window.print(); });<\/script>'
                    + '</body></html>');
                w.document.close();

                // Auto-trigger browser print dialog after content is ready
                setTimeout(function() { w.print(); }, 400);
            });
        }

        // === Generate Commentary (AI) — full report with commentary injected ===
        var procCommentaryBtn = document.getElementById('procCommentaryBtn');
        if (procCommentaryBtn) {
            var procCsrf = csrfToken;
            procCommentaryBtn.addEventListener('click', function() {
                var btn = procCommentaryBtn;
                var origText = btn.textContent;
                btn.disabled = true;
                btn.textContent = <?php echo json_encode(t('vendor-onboarding.js_generating')); ?>;

                var w = window.open('', '_blank');
                if (w) {
                    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Generating Commentary...</title>'
                        + '<style>body{font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f8fafc;color:#475569;}'
                        + '.loader{text-align:center;}.spinner{width:40px;height:40px;border:4px solid #e2e8f0;border-top:4px solid #1e3a5f;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 16px;}'
                        + '@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style></head>'
                        + '<body><div class="loader"><div class="spinner"></div><p>Generating Commentary...</p><p style="font-size:12px;color:#94a3b8;">This may take a moment</p></div></body></html>');
                    w.document.close();
                }

                fetch('api/generate-srs-summary.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        vendor_id: <?php echo (int)$requestId; ?>,
                        csrf_token: procCsrf
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.csrf_token) procCsrf = data.csrf_token;

                    if (data.error) {
                        btn.disabled = false;
                        btn.textContent = origText;
                        if (w) w.close();
                        alert(<?php echo json_encode(t('vendor-onboarding.js_error_prefix')); ?> + data.error);
                        return;
                    }

                    if (data.queued && data.job_id) {
                        var pollInterval = setInterval(function() {
                            fetch('api/ai-job-status.php?id=' + data.job_id)
                            .then(function(r) { return r.json(); })
                            .then(function(poll) {
                                if (poll.csrf_token) procCsrf = poll.csrf_token;
                                if (poll.status === 'completed' && poll.result) {
                                    clearInterval(pollInterval);
                                    btn.disabled = false;
                                    btn.textContent = origText;
                                    data.summary = poll.result.summary;
                                    procApplyCommentary(data, w);
                                } else if (poll.status === 'failed') {
                                    clearInterval(pollInterval);
                                    btn.disabled = false;
                                    btn.textContent = origText;
                                    if (w) w.close();
                                    alert(<?php echo json_encode(t('vendor-onboarding.js_error_prefix')); ?> + (poll.error || <?php echo json_encode(t('vendor-onboarding.js_processing_failed')); ?>));
                                }
                            })
                            .catch(function() {
                                clearInterval(pollInterval);
                                btn.disabled = false;
                                btn.textContent = origText;
                                if (w) w.close();
                                alert(<?php echo json_encode(t('vendor-onboarding.js_check_status_failed')); ?>);
                            });
                        }, 3000);
                        return;
                    }
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = origText;
                    if (w) w.close();
                    alert(<?php echo json_encode(t('vendor-onboarding.js_error_prefix')); ?> + (err.message || <?php echo json_encode(t('vendor-onboarding.js_request_failed')); ?>));
                    console.error(err);
                });

                function procApplyCommentary(data, w) {
                    var chartImages = procCaptureCharts();

                    // Clone the full report, inject commentary into the placeholder
                    var reportEl = document.querySelector('.proc-report');
                    if (!reportEl) { if (w) w.close(); return; }
                    var reportHtml = reportEl.querySelector('.proc-container').innerHTML;

                    // Strip action bar from cloned content
                    var tmp = document.createElement('div');
                    tmp.innerHTML = reportHtml;
                    var actionBar = tmp.querySelector('.proc-action-bar');
                    if (actionBar) actionBar.remove();
                    procReplaceCanvases(tmp, chartImages);

                    // Inject AI commentary into the placeholder
                    var placeholder = tmp.querySelector('#procCommentaryPlaceholder');
                    if (placeholder) {
                        placeholder.innerHTML = '<div style="page-break-before: always; padding: 20px 24px; margin-bottom: 16px; font-size: 9.5pt; line-height: 1.7;">'
                            + '<h3 class="proc-section-title" style="margin-top: 0;">' + <?php echo json_encode(t('vendor-onboarding.label_summary')); ?> + '</h3>'
                            + data.summary
                            + '</div>';
                    }
                    reportHtml = tmp.innerHTML;

                    // Collect proc-report styles
                    var styles = '';
                    document.querySelectorAll('style').forEach(function(s) {
                        if (s.textContent.indexOf('proc-report') !== -1 || s.textContent.indexOf('proc-container') !== -1) {
                            styles += s.textContent;
                        }
                    });

                    if (!w || w.closed) w = window.open('', '_blank');
                    if (!w) { alert(<?php echo json_encode(t('vendor-onboarding.js_allow_popups_short')); ?>); return; }

                    var vendorName = <?php echo json_encode($request['vendor_name'] ?? t('vendor-onboarding.js_unknown_vendor')); ?>;
                    w.document.open();
                    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8">'
                        + '<title>Vendor Detailed Assessment with Commentary — ' + vendorName + '</title>'
                        + '<style>'
                        + 'body { margin: 0; padding: 0; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 10pt; line-height: 1.5; color: #1f2937; }'
                        + '.proc-container { max-width: 8.5in; margin: 0 auto; padding: 20px; }'
                        + '.print-toolbar { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 12px 20px; display: flex; gap: 10px; align-items: center; }'
                        + '.print-toolbar button { padding: 8px 20px; background: #1e3a5f; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }'
                        + '@media print { @page { size: letter; margin: 0.6in 0.5in 0.8in 0.5in; } body { margin: 0; padding: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; } .no-print { display: none !important; } .proc-page-break { page-break-after: always; } .proc-avoid-break { page-break-inside: avoid; } .print-toolbar { display: none !important; } }'
                        + styles
                        + '</style></head><body>'
                        + '<div class="print-toolbar no-print">'
                        + '<button id="printBtn">' + <?php echo json_encode(t('vendor-onboarding.btn_print_pdf')); ?> + '</button>'
                        + '<span style="color: #64748b; font-size: 12px;">' + <?php echo json_encode(t('vendor-onboarding.js_print_hint')); ?> + '</span>'
                        + '</div>'
                        + '<div class="proc-report"><div class="proc-container">' + reportHtml + '</div></div>'
                        + '<script>document.getElementById("printBtn").addEventListener("click", function() { window.print(); });<\/script>'
                        + '</body></html>');
                    w.document.close();

                    // Auto-trigger browser print dialog after content is ready
                    setTimeout(function() { w.print(); }, 400);
                }
            });
        }
    })();
    </script>
    <?php endif; ?>

    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
