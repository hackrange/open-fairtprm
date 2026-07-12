<?php
/**
 * Vendor Assessments Management
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the admin command center for vendor security assessments. Think of it
 * as the DMV for third-party vendors -- they have to fill out forms, wait around,
 * and prove they're not a security liability before we let them play in our sandbox.
 *
 * Handles creating new assessments from templates, sending magic links to vendors,
 * tracking completion status, filtering/sorting/searching the assessment list,
 * and provides bulk actions like delete and resend. The whole thing is wrapped in
 * a nice sidebar layout with pagination that would make a DBA shed a single tear
 * of joy (or horror, depending on the day).
 */

// Boot up the app -- session, DB, security, the whole enchilada
require_once 'includes/init.php';
requireAuth();

// Grab all our singleton friends -- the usual suspects
$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$session = Session::getInstance();
$acl = ACL::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// Permission checks -- who gets to be the bouncer at this club?
// Admins see everything (obviously), Cyber TPRM are the security nerds,
// and Procurement are the folks who actually sign the checks.
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurement = hasGroup('procurement');
$isStakeholder = hasGroup('stakeholder');
$isAuditor = hasGroup('auditor');

// If you're none of the above, sorry buddy -- you're not on the guest list
if (!$isAdmin && !$isCyberTPRM && !$isProcurement && !$isStakeholder && !$isAuditor) {
    http_response_code(403);
    die('Access denied. You do not have permission to manage vendor assessments.');
}

// Fire up the assessment service -- this bad boy handles all the heavy lifting
// for creating, deleting, and tracking vendor assessments. Also makes sure the
// database tables exist because apparently we like living dangerously.
require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();
$assessmentService->initializeTables();

// BOLA fix: reviewers (admin/cyber_tprm/procurement/auditor) manage all assessments;
// a scoped stakeholder may only delete/resend assessments for vendors they created or
// are linked to via vendor_onboarding_stakeholders.
if (!function_exists('assessmentManageable')) {
    function assessmentManageable($assessmentId, $isAdmin, $isCyberTPRM, $isProcurement, $isAuditor, $assessmentService, $user) {
        if ($isAdmin || $isCyberTPRM || $isProcurement || $isAuditor) return true;
        $a = $assessmentService->getAssessmentById((int)$assessmentId);
        if (!$a) return false;
        if ((int)($a['created_by'] ?? 0) === (int)$user['id']) return true;
        $vrid = (int)($a['vendor_request_id'] ?? 0);
        if (!$vrid) return false;
        return !empty(Database::getInstance()->fetchOne(
            'SELECT 1 AS x FROM vendor_onboarding_stakeholders WHERE request_id = :rid AND user_id = :uid',
            [':rid' => $vrid, ':uid' => (int)$user['id']]));
    }
}

$error = '';
$success = '';
$successHtml = '';

// ============================================================
// FORM SUBMISSION HANDLING
// This is where the magic happens when someone clicks a button.
// We validate CSRF tokens first because we're not savages.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-assessments.invalid_request');
    } elseif (isset($_POST['create_assessment'])) {
        // The "Download Fillable PDF" button reuses this handler but asks us
        // to redirect to the PDF endpoint instead of emailing and rendering a
        // success card. This lets a single code path own vendor/validation
        // logic so the two buttons can't drift.
        $pdfDownloadMode = !empty($_POST['download_pdf']);

        // Procurement cannot create assessments -- read-only access for them
        if ($isProcurement && !$isAdmin && !$isCyberTPRM) {
            $error = t('vendor-assessments.procurement_cannot_create');
        }
        // Pull all the form data out and sanitize it. Trust no one, not even Karen from Procurement.
        $templateId = intval($_POST['template_id'] ?? 0);
        $vendorName = trim($_POST['vendor_name'] ?? '');
        $vendorEmail = trim($_POST['vendor_email'] ?? '');
        $vendorContact = trim($_POST['vendor_contact'] ?? '');
        $vendorContactEmail = trim($_POST['vendor_contact_email'] ?? '');
        $expiresInDays = intval($_POST['expires_in_days'] ?? 30);
        $vendorRequestId = !empty($_POST['vendor_request_id']) ? intval($_POST['vendor_request_id']) : null;
        $isNewVendor = !empty($_POST['is_new_vendor']);
        $vendorDomain = trim($_POST['vendor_domain'] ?? '');

        // Check if selected template is an onboarding category template
        $isOnboardingTemplate = false;
        if ($templateId) {
            $templateRecord = $db->fetchOne("SELECT category FROM assessment_templates WHERE id = :id", [':id' => $templateId]);
            $isOnboardingTemplate = ($templateRecord && $templateRecord['category'] === 'onboarding');
        }

        // For onboarding templates, create a new vendor_onboarding_requests record first
        if ($isOnboardingTemplate && $isNewVendor && empty($error) && $templateId && !empty($vendorName) && !empty($vendorEmail)) {
            try {
                $newVendorData = [
                    'vendor_name' => $security->cleanInput($vendorName),
                    'vendor_domain' => $security->cleanInput($vendorDomain),
                    'primary_contact_email' => $security->cleanInput($vendorEmail),
                    'primary_contact_details' => $security->cleanInput($vendorContact),
                    'status' => 'draft',
                    'created_by' => $user['id'],
                    'last_autosave' => date('Y-m-d H:i:s'),
                ];
                $vendorRequestId = $db->insert('vendor_onboarding_requests', $newVendorData);

                // Set up stakeholder ownership -- creator is the owner
                $db->insert('vendor_onboarding_stakeholders', [
                    'request_id' => $vendorRequestId,
                    'user_id' => $user['id'],
                    'role' => 'owner',
                    'assigned_by' => $user['id']
                ]);
            } catch (Exception $e) {
                error_log('Create vendor for onboarding assessment error: ' . $e->getMessage());
                $error = t('vendor-assessments.failed_create_vendor');
            }
        }

        // Validation gauntlet -- your form data must survive all of these checks
        if (!empty($error)) {
            // Error already set above -- skip creation
        } elseif (!$templateId || empty($vendorName) || empty($vendorEmail)) {
            $error = t('vendor-assessments.fill_required_fields');
        } elseif (!$vendorRequestId) {
            $error = t('vendor-assessments.select_valid_vendor_existing');
        } elseif (!$isOnboardingTemplate && !$db->fetchOne("SELECT id FROM vendor_onboarding_requests WHERE id = :id AND status IN ('submitted', 'in_review', 'ai_review', 'approved')", [':id' => $vendorRequestId])) {
            $error = t('vendor-assessments.vendor_not_valid');
        } elseif (!filter_var($vendorEmail, FILTER_VALIDATE_EMAIL)) {
            $error = t('vendor-assessments.enter_valid_email');
        } else {
            // All validations passed -- let's actually create this thing
            try {
                $result = $assessmentService->createAssessment(
                    $templateId,
                    $vendorName,
                    $vendorEmail,
                    $vendorContact,
                    $vendorContactEmail,
                    $vendorRequestId,
                    $user['id'],
                    $expiresInDays,
                    true   // prefill from the vendor's prior completed assessment (re-assessment convenience)
                );

                // Build the magic link URL using the admin-configured Application URL
                $assessmentUrl = baseUrl('vendor-assessment.php?token=' . $result['uuid']);

                // PDF download mode: create + audit exactly like the normal
                // flow, but skip email and redirect straight to the PDF
                // endpoint so the user receives the fillable document.
                if (!empty($pdfDownloadMode) && !empty($result['id'])) {
                    $auth->audit($user['id'], 'assessment_create', 'vendor_assessments', $result['id'], [
                        'new' => ['vendor_name' => $vendorName, 'vendor_email' => $vendorEmail, 'template_id' => $templateId, 'vendor_request_id' => $vendorRequestId, 'via' => 'pdf_download']
                    ]);
                    header('Location: api/assessment-download-pdf.php?assessment_id=' . (int)$result['id']);
                    exit;
                }

                // Auto-send email to the contact person if email is enabled
                $emailSent = false;
                try {
                    require_once __DIR__ . '/includes/classes/EmailService.php';
                    $encryption = new Encryption();
                    $emailService = new EmailService($db, $encryption);

                    if ($emailService->isEnabled() && !empty($vendorContactEmail)) {
                        // Look up full name from onboarding record
                        $onboardingRecord = $db->fetchOne(
                            "SELECT primary_contact_details FROM vendor_onboarding_requests WHERE id = ?",
                            [$vendorRequestId]
                        );
                        $fullName = !empty($onboardingRecord['primary_contact_details']) ? $onboardingRecord['primary_contact_details'] : null;

                        $emailResult = $emailService->sendAssessmentEmail(
                            $vendorContactEmail,
                            $vendorName,
                            $assessmentUrl,
                            $vendorContact,
                            $templateId,
                            $fullName,
                            $result['expires_at']
                        );
                        $emailSent = !empty($emailResult['success']);

                        // Record in tracking table so cron won't re-send
                        if ($emailSent && !empty($result['id'])) {
                            try {
                                $expiresDate = !empty($result['expires_at']) ? date('Y-m-d', strtotime($result['expires_at'])) : date('Y-m-d', strtotime('+30 days'));
                                $db->query(
                                    "INSERT INTO vendor_assessment_reminders (assessment_id, reminder_type, expires_at, sent_at, email_sent_to, status)
                                     VALUES (?, 'initial', ?, NOW(), ?, 'sent')
                                     ON DUPLICATE KEY UPDATE status = 'sent', sent_at = NOW(), email_sent_to = VALUES(email_sent_to)",
                                    [$result['id'], $expiresDate, $vendorContactEmail]
                                );
                            } catch (Exception $trackEx) {
                                error_log('Assessment reminder tracking insert failed: ' . $trackEx->getMessage());
                            }
                        }
                    }
                } catch (Exception $emailEx) {
                    error_log('Auto-send assessment email error: ' . $emailEx->getMessage());
                }

                // Show a success message with the link and a handy copy button
                $emailNote = $emailSent
                    ? '<br><span style="color:#166534;">' . t('vendor-assessments.email_sent_auto_prefix') . htmlspecialchars($vendorContactEmail) . '</span>'
                    : '';

                // Let the admin know we carried answers over from a prior assessment
                if (!empty($result['prefilled_count'])) {
                    $emailNote .= '<br><span style="color:#166534;">' . t('vendor-assessments.prefilled_prefix') . (int)$result['prefilled_count'] .
                                  t('vendor-assessments.prefilled_suffix') . '</span>';
                }
                $auth->audit($user['id'], 'assessment_create', 'vendor_assessments', $result['id'] ?? null, [
                    'new' => ['vendor_name' => $vendorName, 'vendor_email' => $vendorEmail, 'template_id' => $templateId, 'vendor_request_id' => $vendorRequestId]
                ]);
                $successHtml = t('vendor-assessments.created_success_html') . $emailNote . t('vendor-assessments.share_link_text') .
                           '<br><br><code style="background:#f3f4f6;padding:8px 12px;border-radius:4px;word-break:break-all;">' .
                           htmlspecialchars($assessmentUrl) . '</code>' .
                           '<br><br><button type="button" data-clipboard="' . htmlspecialchars($assessmentUrl, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm" style="background:#6b7280;color:white;">' . t('vendor-assessments.btn_copy_link') . '</button>';
            } catch (Exception $e) {
                error_log('Create assessment error: ' . $e->getMessage());
                $error = t('vendor-assessments.failed_create_assessment');
            }
        }
    } elseif (isset($_POST['delete_assessment'])) {
        // Nuke an assessment from orbit. It's the only way to be sure.
        $assessmentId = intval($_POST['assessment_id'] ?? 0);
        if ($assessmentId && assessmentManageable($assessmentId, $isAdmin, $isCyberTPRM, $isProcurement, $isAuditor, $assessmentService, $user)) {
            try {
                $auth->audit($user['id'], 'assessment_delete', 'vendor_assessments', $assessmentId);
                $assessmentService->deleteAssessment($assessmentId);
                $success = t('vendor-assessments.deleted_success');
            } catch (Exception $e) {
                error_log('Delete assessment error: ' . $e->getMessage());
                $error = t('vendor-assessments.failed_delete');
            }
        } elseif ($assessmentId) {
            http_response_code(403);
            $error = t('vendor-assessments.failed_delete');
        }
    } elseif (isset($_POST['resend_assessment'])) {
        // Vendor ghosted us? Hit 'em with a fresh link and reset the clock.
        // Extends the expiration by another 30 days because we're generous like that.
        $assessmentId = intval($_POST['assessment_id'] ?? 0);
        if ($assessmentId && assessmentManageable($assessmentId, $isAdmin, $isCyberTPRM, $isProcurement, $isAuditor, $assessmentService, $user)) {
            try {
                $assessmentService->resendAssessment($assessmentId, 30);
                $auth->audit($user['id'], 'assessment_resend', 'vendor_assessments', $assessmentId);
                $success = t('vendor-assessments.link_reset_extended');
            } catch (Exception $e) {
                error_log('Resend assessment error: ' . $e->getMessage());
                $error = t('vendor-assessments.failed_resend');
            }
        } elseif ($assessmentId) {
            http_response_code(403);
            $error = t('vendor-assessments.failed_resend');
        }
    }
}

// ============================================================
// DATA FETCHING & FILTERING
// Grab all the templates and assessments, then let the user
// slice and dice them however they want. It's like a data buffet.
// ============================================================
$templates = $assessmentService->getTemplates(true, 'vendor_assessment');

// Search, filter, sort, and pagination parameters -- the holy quartet of list pages
$statusFilter = $_GET['status'] ?? '';
$typeFilter = isset($_GET['type']) ? intval($_GET['type']) : 0;
$searchQuery = trim($_GET['search'] ?? '');
$sortColumn = $_GET['sort'] ?? 'created_at';
$sortOrder = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
$perPage = in_array($perPage, [25, 50, 100]) ? $perPage : 25;  // No, you can't show 10,000 per page
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Whitelist valid sort columns so nobody tries to SQL-inject via the sort param.
// I mean, we're using params anyway, but belt AND suspenders, people.
$validSortColumns = ['vendor_name', 'template_name', 'status', 'created_at', 'expires_at'];
if (!in_array($sortColumn, $validSortColumns)) {
    $sortColumn = 'created_at';
}

// Build the filters array from query string params
$filters = ['category' => 'vendor_assessment']; // Only show vendor assessment type
if ($statusFilter) {
    $filters['status'] = $statusFilter;
}
if ($typeFilter) {
    $filters['template_id'] = $typeFilter;
}

// SECURITY (BOLA): stakeholders are a scoped role -- they are excluded from the
// per-assessment view page (vendor-assessment-view.php returns 403 for them), so
// they must not receive the whole org's assessment inventory (and its bearer-token
// links) from the list either. Scope their list to assessments they created or are
// assigned to. Org-wide reviewer roles (admin/cyber_tprm/procurement/auditor) are
// unaffected and still see everything.
if ($isStakeholder && !$isAdmin && !$isCyberTPRM && !$isProcurement && !$isAuditor) {
    $filters['scope_user_id'] = (int)$user['id'];
}

// Fetch all assessments matching filters. Yes, we load them all into memory
// and handle search/pagination in PHP. Is it optimal? Nah. Does it work? You bet.
$allAssessments = $assessmentService->getAllAssessments($filters);

// Apply search filter -- basically a poor man's full-text search.
// Checks vendor name, email, template name, creator, and stakeholder fields.
// It ain't Elasticsearch but it gets the job done for reasonable dataset sizes.
if (!empty($searchQuery)) {
    $searchLower = strtolower($searchQuery);
    $allAssessments = array_filter($allAssessments, function($a) use ($searchLower) {
        return strpos(strtolower($a['vendor_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($a['vendor_email'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($a['template_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($a['created_by_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($a['stakeholder_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($a['stakeholder_email'] ?? ''), $searchLower) !== false;
    });
    $allAssessments = array_values($allAssessments); // Re-index after filtering (array_filter leaves gaps)
}

// Sort the assessments using the spaceship operator (<=>). If you haven't
// seen this operator before, welcome to PHP 7+. It's basically strcmp on steroids.
usort($allAssessments, function($a, $b) use ($sortColumn, $sortOrder) {
    $aVal = $a[$sortColumn] ?? '';
    $bVal = $b[$sortColumn] ?? '';

    // Date columns need to be compared as timestamps, not strings.
    // "2025-01-01" < "2025-12-31" works as strings too, but let's be proper about it.
    if (in_array($sortColumn, ['created_at', 'expires_at'])) {
        $aVal = strtotime($aVal) ?: 0;
        $bVal = strtotime($bVal) ?: 0;
    } else {
        $aVal = strtolower($aVal);
        $bVal = strtolower($bVal);
    }

    if ($sortOrder === 'ASC') {
        return $aVal <=> $bVal;
    }
    return $bVal <=> $aVal;
});

// Pagination math -- the kind of math that makes you appreciate frameworks
$totalAssessments = count($allAssessments);
$totalPages = max(1, ceil($totalAssessments / $perPage));
$currentPage = min($currentPage, $totalPages);   // Don't let users request page 9999
$offset = ($currentPage - 1) * $perPage;
$assessments = array_slice($allAssessments, $offset, $perPage);

// Helper to build sort URL -- toggles direction if you click the same column twice.
// Because apparently that's a UX pattern people expect now. Thanks, every spreadsheet ever.
function buildSortUrl($column, $currentSort, $currentOrder) {
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = ($currentSort === $column && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    $params['page'] = 1; // Reset to first page on sort
    return '?' . http_build_query($params);
}

// Helper to build pagination URL -- preserves all existing query params while swapping the page
function buildPageUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// Helper to show those little triangle arrows next to the sorted column header
function getSortIndicator($column, $currentSort, $currentOrder) {
    if ($currentSort !== $column) return '';
    return $currentOrder === 'ASC' ? ' &#9650;' : ' &#9660;';
}

// Grab all vendors from the onboarding system that are in a "usable" state.
// We only show submitted, in_review, ai_review, and approved vendors because
// drafts and inactive ones aren't real vendors yet (or anymore). ai_review is
// the AI-usage review stage -- it's a sibling of in_review (a vendor actively
// under review), so assessments must be linkable to it just like in_review.
$vendors = $db->fetchAll(
    "SELECT id, vendor_name, vendor_domain, primary_contact_email as vendor_email, primary_contact_details as vendor_contact, primary_contact_email as vendor_contact_email FROM vendor_onboarding_requests WHERE status IN ('submitted', 'in_review', 'ai_review', 'approved') ORDER BY vendor_name"
);

// If someone arrived here from a vendor's onboarding page via "New Assessment" button,
// we pre-select that vendor in the create modal so they don't have to search for it again.
// It's the little things that keep users from throwing keyboards.
$linkVendorId = isset($_GET['link_vendor']) ? intval($_GET['link_vendor']) : 0;
$preselectedVendor = null;
if ($linkVendorId) {
    // SECURITY (IDOR): constrain the preselect lookup to the same "usable" vendor
    // set the create-modal dropdown ($vendors) already exposes. Without the status
    // filter, link_vendor=<any id> would disclose name/contact/email of draft or
    // inactive vendor records the user is not meant to see in this form.
    $preselectedVendor = $db->fetchOne(
        "SELECT id, vendor_name, vendor_domain, primary_contact_email as vendor_email, primary_contact_details as vendor_contact, primary_contact_email as vendor_contact_email FROM vendor_onboarding_requests WHERE id = :id AND status IN ('submitted', 'in_review', 'ai_review', 'approved')",
        [':id' => $linkVendorId]
    );
}

// link_vendor is a one-shot trigger: it pre-selects the vendor and auto-opens the
// Create modal exactly once, on arrival from a vendor's "New Assessment" button.
// Drop it now that it has been consumed so the param does NOT get carried into the
// sort/pagination links (built by copying $_GET) -- otherwise clicking a column
// header would reload with link_vendor still set and re-open the Create modal
// instead of just sorting. ($preselectedVendor already holds what we need.)
unset($_GET['link_vendor']);

$csrfToken = $security->getCSRFToken() ?? $security->generateCSRFToken();

// Check if email notifications are enabled for native email sending
$emailEnabled = false;
try {
    $emailEnabledRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'email_enabled'");
    $emailEnabled = ($emailEnabledRow && $emailEnabledRow['config_value'] === '1');
} catch (Exception $e) {
    // Table may not exist yet -- fall back to mailto
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo e(t('vendor-assessments.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
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
        body { margin: 0; font-family: 'Roboto', sans-serif; background: #f9fafb; }

        .page { display: flex; flex-direction: column; min-height: 100vh; }

        .top-bar {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
        }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a {
            color: #333; text-decoration: none; padding: 8px 15px;
            border-radius: 4px; background: rgba(255,101,67,0.1);
            transition: background 0.2s; font-size: 14px;
        }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }

        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }

        .sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            max-width: var(--sidebar-width);
            background: var(--nav-fill-color);
            display: flex; flex-direction: column;
        }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title {
            color: var(--nav-font-color); font-size: 13px; font-weight: 500;
            margin-top: 8px; opacity: 0.9;
        }
        .sidebar-content { flex: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1.5px; color: var(--nav-font-color);
            opacity: 0.5; padding: 0 20px; margin-bottom: 10px;
        }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li a {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 20px; color: var(--nav-font-color);
            opacity: 0.85; text-decoration: none; font-size: 13px;
            transition: all 0.2s; border-left: 3px solid transparent;
        }
        .sidebar-nav li a:hover {
            background: rgba(255,255,255,0.1); opacity: 1;
            border-left-color: var(--nav-font-color);
        }
        .sidebar-nav li a.active {
            background: rgba(255,255,255,0.15); opacity: 1;
            border-left-color: var(--nav-font-color); font-weight: 500;
        }
        .sidebar-nav li a .icon { font-size: 16px; width: 20px; text-align: center; }
        .sidebar-nav li a .badge {
            margin-left: auto; background: rgba(255,255,255,0.2);
            padding: 2px 8px; border-radius: 10px; font-size: 11px;
        }

        .main-content { flex: 1; padding: 30px 35px; background: #f9fafb; overflow-y: auto; }

        .page-header { margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { font-size: 24px; margin: 0; color: #333; }

        .card {
            background: white; border: 1px solid #e5e7eb;
            border-radius: 10px; padding: 25px; margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .card h3 {
            color: var(--theme-header-color); margin: 0 0 20px 0;
            font-size: 16px; font-weight: 600;
            padding-bottom: 12px; border-bottom: 2px solid #f0f0f0;
        }

        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; color: #333; font-weight: 500; font-size: 13px; }
        .form-group label .required { color: #ef4444; }
        .form-control, .form-select {
            width: 100%; padding: 10px 12px;
            border: 1px solid #ddd; border-radius: 4px; font-size: 14px;
        }
        .form-control:focus, .form-select:focus { outline: none; border-color: var(--theme-header-color); }
        .form-help { color: #666; font-size: 12px; margin-top: 4px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }

        .btn {
            padding: 10px 20px; border: none; border-radius: 4px;
            cursor: pointer; font-size: 13px; font-weight: 500;
            transition: all 0.2s; text-decoration: none; display: inline-block;
        }
        .btn-primary { background: var(--theme-header-color); color: white; }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-danger { background: #ef4444; color: white; }

        .alert { padding: 12px 16px; margin-bottom: 20px; border-radius: 6px; font-size: 14px; }
        .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f8f9fa; padding: 12px 10px; text-align: left; font-weight: 500; border-bottom: 2px solid #dee2e6; }
        td { padding: 12px 10px; border-bottom: 1px solid #dee2e6; vertical-align: middle; }
        tr:hover { background: #f9fafb; }

        .badge {
            padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 500;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-in-progress { background: #dbeafe; color: #1e40af; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-expired { background: #fef2f2; color: #991b1b; }

        .status-filters { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .status-filters a {
            padding: 8px 16px; border-radius: 4px; font-size: 13px; font-weight: 500;
            text-decoration: none; color: #666; background: #f3f4f6;
            border: 1px solid #e5e7eb;
            transition: all 0.2s;
        }
        .status-filters a:hover { background: #e5e7eb; }
        .status-filters a.active { background: var(--theme-header-color); color: white; border-color: var(--theme-header-color); }

        .modal {
            display: none; position: fixed; z-index: 1000;
            left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fff; margin: 5% auto; padding: 25px;
            border: 1px solid #888; border-radius: 8px;
            width: 90%; max-width: 550px;
        }
        .close { color: #aaa; float: right; font-size: 24px; cursor: pointer; }
        .close:hover { color: #000; }

        .empty-state {
            text-align: center; padding: 60px 20px; color: #666;
        }
        .empty-state .icon { font-size: 50px; margin-bottom: 15px; opacity: 0.5; }

        /* Search and filter controls */
        .controls-bar {
            display: flex; flex-wrap: wrap; gap: 15px; align-items: center;
            margin-bottom: 20px; padding: 15px; background: #f8f9fa;
            border-radius: 8px; border: 1px solid #e5e7eb;
        }
        .search-box {
            flex: 1; min-width: 200px; max-width: 350px;
            position: relative;
        }
        .search-box input {
            width: 100%; padding: 8px 12px;
            border: 1px solid #ddd; border-radius: 4px; font-size: 13px;
        }
        .search-box input:focus { outline: none; border-color: var(--theme-header-color); }
        .filter-group { display: flex; align-items: center; gap: 8px; }
        .filter-group label { font-size: 13px; color: #666; white-space: nowrap; }
        .filter-group select {
            padding: 8px 12px; border: 1px solid #ddd;
            border-radius: 4px; font-size: 13px; min-width: 150px;
        }
        .per-page-group { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .per-page-group label { font-size: 12px; color: #666; }
        .per-page-group select {
            padding: 6px 10px; border: 1px solid #ddd;
            border-radius: 4px; font-size: 12px;
        }

        /* Sortable headers */
        th.sortable {
            cursor: pointer; user-select: none;
            transition: background 0.2s;
        }
        th.sortable:hover { background: #e9ecef; }
        th.sortable .sort-icon { font-size: 10px; margin-left: 4px; opacity: 0.5; }
        th.sortable.active .sort-icon { opacity: 1; color: var(--theme-header-color); }

        /* Pagination */
        .pagination-bar {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 20px; padding-top: 15px; border-top: 1px solid #e5e7eb;
            flex-wrap: wrap; gap: 10px;
        }
        .pagination-info { font-size: 13px; color: #666; }
        .pagination {
            display: flex; gap: 4px; list-style: none; margin: 0; padding: 0;
        }
        .pagination li a, .pagination li span {
            display: inline-block; padding: 6px 12px;
            border: 1px solid #ddd; border-radius: 4px;
            text-decoration: none; color: #333; font-size: 13px;
            transition: all 0.2s;
        }
        .pagination li a:hover { background: #f3f4f6; border-color: #ccc; }
        .pagination li.active span {
            background: var(--theme-header-color); color: white;
            border-color: var(--theme-header-color);
        }
        .pagination li.disabled span { color: #ccc; cursor: not-allowed; }

        /* Footer styling */
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
            overflow: visible;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; white-space: nowrap; }
        .footer-modern .brand img { max-height: 45px; }

        @media (max-width: 992px) {
            .main-layout { flex-direction: column; }
            .sidebar { width: 100%; min-width: 100%; max-width: 100%; }
            .form-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: stretch; }
            .page-header > div { flex-direction: column; width: 100%; }
            .vendor-search-container { min-width: 100% !important; width: 100% !important; }
        }
    </style>
</head>
<body>
    <div class="page">
        <?php renderImpersonationBanner(); ?>

        <div class="top-bar">
            <span style="color: #666; margin-right: 15px;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <?php if ($isAdmin): ?>
                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                <?php endif; ?>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <?php
            $_statusMap = [
                'pending' => 'assessments_pending',
                'in_progress' => 'assessments_in_progress',
                'completed' => 'assessments_completed',
            ];
            $_savedPageNum = $currentPage; $currentPage = $_statusMap[$statusFilter ?? ''] ?? 'assessments';
            include __DIR__ . '/includes/sidebar_nav.php'; $currentPage = $_savedPageNum;
            ?>

            <main class="main-content">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo e($error); ?></div>
                <?php endif; ?>
                <?php if ($success || $successHtml): ?>
                    <div class="alert alert-success"><?php echo $successHtml ? $successHtml : e($success); ?></div>
                <?php endif; ?>

                <div class="page-header">
                    <div>
                        <h1><?php echo e(t('vendor-assessments.heading')); ?></h1>
                        <?php if ((!$isProcurement || $isAdmin || $isCyberTPRM) && !$isAuditor): ?>
                        <button class="btn btn-primary" style="margin-top: 10px;" data-toggle="createModal">
                            + <?php echo ($isStakeholder && !$isAdmin && !$isCyberTPRM) ? e(t('vendor-assessments.new_vendor_request')) : e(t('vendor-assessments.new_assessment')); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="vendor-search-container" style="position: relative; min-width: 300px;">
                        <input
                            type="text"
                            id="vendorSearchInput"
                            placeholder="<?php echo e(t('vendor-assessments.search_vendors_placeholder')); ?>"
                            style="width: 100%; padding: 10px 40px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                            class="focus-ring"
                        >
                        <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">&#128269;</span>
                        <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
                    </div>
                </div>

                <!-- Status Filters -->
                <div class="status-filters">
                    <a href="vendor-assessments.php<?php echo $typeFilter ? '?type=' . $typeFilter : ''; ?>" class="<?php echo empty($statusFilter) ? 'active' : ''; ?>"><?php echo e(t('vendor-assessments.filter_all')); ?></a>
                    <a href="vendor-assessments.php?status=pending<?php echo $typeFilter ? '&type=' . $typeFilter : ''; ?>" class="<?php echo $statusFilter === 'pending' ? 'active' : ''; ?>"><?php echo e(t('vendor-assessments.filter_pending')); ?></a>
                    <a href="vendor-assessments.php?status=in_progress<?php echo $typeFilter ? '&type=' . $typeFilter : ''; ?>" class="<?php echo $statusFilter === 'in_progress' ? 'active' : ''; ?>"><?php echo e(t('vendor-assessments.filter_in_progress')); ?></a>
                    <a href="vendor-assessments.php?status=completed<?php echo $typeFilter ? '&type=' . $typeFilter : ''; ?>" class="<?php echo $statusFilter === 'completed' ? 'active' : ''; ?>"><?php echo e(t('vendor-assessments.filter_completed')); ?></a>
                </div>

                <!-- Search, Type Filter, and Per-Page Controls -->
                <div class="controls-bar">
                    <div class="search-box">
                        <form method="GET" id="searchForm">
                            <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?php echo e($statusFilter); ?>"><?php endif; ?>
                            <?php if ($typeFilter): ?><input type="hidden" name="type" value="<?php echo $typeFilter; ?>"><?php endif; ?>
                            <?php if ($sortColumn !== 'created_at'): ?><input type="hidden" name="sort" value="<?php echo e($sortColumn); ?>"><?php endif; ?>
                            <?php if ($sortOrder !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($sortOrder); ?>"><?php endif; ?>
                            <?php if ($perPage !== 25): ?><input type="hidden" name="per_page" value="<?php echo $perPage; ?>"><?php endif; ?>
                            <input type="text" name="search" placeholder="<?php echo e(t('vendor-assessments.search_placeholder')); ?>" value="<?php echo e($searchQuery); ?>">
                        </form>
                    </div>

                    <div class="filter-group">
                        <label><?php echo e(t('vendor-assessments.label_type')); ?></label>
                        <select data-action="filterByType">
                            <option value=""><?php echo e(t('vendor-assessments.all_types')); ?></option>
                            <?php foreach ($templates as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $typeFilter == $t['id'] ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="per-page-group">
                        <label><?php echo e(t('vendor-assessments.label_show')); ?></label>
                        <select data-action="changePerPage">
                            <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                        <label><?php echo e(t('vendor-assessments.per_page')); ?></label>
                    </div>
                </div>

                <!-- Assessments List -->
                <div class="card">
                    <?php if (empty($assessments)): ?>
                    <div class="empty-state">
                        <div class="icon"><img src="app/icons/file-shield-02.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('vendor-assessments.no_assessments_found')); ?></h3>
                        <p><?php echo e(t('vendor-assessments.no_assessments_hint')); ?></p>
                    </div>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable <?php echo $sortColumn === 'vendor_name' ? 'active' : ''; ?>">
                                    <a href="<?php echo buildSortUrl('vendor_name', $sortColumn, $sortOrder); ?>" style="color:inherit;text-decoration:none;">
                                        <?php echo e(t('vendor-assessments.col_assessment')); ?><span class="sort-icon"><?php echo getSortIndicator('vendor_name', $sortColumn, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th class="sortable <?php echo $sortColumn === 'template_name' ? 'active' : ''; ?>">
                                    <a href="<?php echo buildSortUrl('template_name', $sortColumn, $sortOrder); ?>" style="color:inherit;text-decoration:none;">
                                        <?php echo e(t('vendor-assessments.col_type')); ?><span class="sort-icon"><?php echo getSortIndicator('template_name', $sortColumn, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th class="sortable <?php echo $sortColumn === 'status' ? 'active' : ''; ?>">
                                    <a href="<?php echo buildSortUrl('status', $sortColumn, $sortOrder); ?>" style="color:inherit;text-decoration:none;">
                                        <?php echo e(t('vendor-assessments.col_status')); ?><span class="sort-icon"><?php echo getSortIndicator('status', $sortColumn, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th><?php echo e(t('vendor-assessments.col_progress')); ?></th>
                                <th class="sortable <?php echo $sortColumn === 'created_at' ? 'active' : ''; ?>">
                                    <a href="<?php echo buildSortUrl('created_at', $sortColumn, $sortOrder); ?>" style="color:inherit;text-decoration:none;">
                                        <?php echo e(t('vendor-assessments.col_created')); ?><span class="sort-icon"><?php echo getSortIndicator('created_at', $sortColumn, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th class="sortable <?php echo $sortColumn === 'expires_at' ? 'active' : ''; ?>">
                                    <a href="<?php echo buildSortUrl('expires_at', $sortColumn, $sortOrder); ?>" style="color:inherit;text-decoration:none;">
                                        <?php echo e(t('vendor-assessments.col_expires')); ?><span class="sort-icon"><?php echo getSortIndicator('expires_at', $sortColumn, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th><?php echo e(t('vendor-assessments.col_actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assessments as $a): ?>
                            <?php
                                $isExpired = $a['expires_at'] && strtotime($a['expires_at']) < time() && $a['status'] !== 'completed';
                                $displayStatus = $isExpired ? 'expired' : $a['status'];
                                $completion = $assessmentService->getCompletionStatus($a['id']);
                                $assessmentName = $a['vendor_name'] . ' - ' . date('M j, Y', strtotime($a['created_at']));
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($assessmentName); ?></strong><br>
                                    <small style="color: #666;"><?php echo e($a['vendor_email']); ?></small>
                                </td>
                                <td><?php echo e($a['template_name']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $displayStatus; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $displayStatus)); ?>
                                    </span>
                                    <?php if ($a['certificate_uploaded']): ?>
                                    <br><small style="color: #22c55e;"><?php echo e(t('vendor-assessments.certificate_uploaded')); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$a['certificate_uploaded']): ?>
                                    <div style="width: 80px; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                                        <div style="width: <?php echo $completion['percentage']; ?>%; height: 100%; background: <?php echo $completion['percentage'] == 100 ? '#22c55e' : '#3b82f6'; ?>;"></div>
                                    </div>
                                    <small><?php echo $completion['percentage']; ?>%</small>
                                    <?php else: ?>
                                    <span style="color: #22c55e;">100%</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($a['created_at'])); ?>
                                    <?php if ($a['created_by_name']): ?>
                                    <br><small style="color: #666;"><?php echo e(t('vendor-assessments.created_by_prefix')); ?> <?php echo e($a['created_by_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($a['expires_at']): ?>
                                    <?php echo date('M j, Y', strtotime($a['expires_at'])); ?>
                                    <?php if ($isExpired): ?>
                                    <br><small style="color: #ef4444;"><?php echo e(t('vendor-assessments.expired')); ?></small>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <a href="vendor-assessment-view.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-secondary" target="_blank"><?php echo e(t('vendor-assessments.btn_view')); ?></a>
                                    <?php if (!$isExpired): ?>
                                    <a href="api/assessment-download-pdf.php?assessment_id=<?php echo (int)$a['id']; ?>" class="btn btn-sm" style="background:#b91c1c;color:white;" title="<?php echo e(t('vendor-assessments.btn_pdf_title')); ?>">PDF</a>
                                    <a href="api/assessment-download-xlsx.php?assessment_id=<?php echo (int)$a['id']; ?>" class="btn btn-sm" style="background:#15803d;color:white;" title="<?php echo e(t('vendor-assessments.btn_excel_title')); ?>">Excel</a>
                                    <?php endif; ?>
                                    <?php if (!$isAuditor): ?>
                                    <?php if ($a['status'] !== 'completed'): ?>
                                    <button class="btn btn-sm" style="background:#3b82f6;color:white;" data-action="copyLink" data-arg="<?php echo e($a['uuid']); ?>"><?php echo e(t('vendor-assessments.btn_copy')); ?></button>
                                    <button class="btn btn-sm" style="background:#10b981;color:white;" data-action="emailLink" data-args="<?php echo e(json_encode([$a['uuid'], $a['vendor_name'], $a['vendor_contact_email']])); ?>"><?php echo e(t('vendor-assessments.btn_email')); ?></button>
                                    <?php endif; ?>
                                    <?php if ($isExpired || $a['status'] === 'pending'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="assessment_id" value="<?php echo (int)$a['id']; ?>">
                                        <button type="submit" name="resend_assessment" class="btn btn-sm" style="background:#f59e0b;color:white;"><?php echo e(t('vendor-assessments.btn_resend')); ?></button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" data-confirm="<?php echo e(t('vendor-assessments.confirm_delete')); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="assessment_id" value="<?php echo (int)$a['id']; ?>">
                                        <button type="submit" name="delete_assessment" class="btn btn-sm btn-danger"><?php echo e(t('vendor-assessments.btn_delete')); ?></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1 || $totalAssessments > 0): ?>
                    <div class="pagination-bar">
                        <div class="pagination-info">
                            <?php echo e(t('vendor-assessments.showing_prefix')); ?> <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalAssessments); ?> <?php echo e(t('vendor-assessments.showing_of')); ?> <?php echo $totalAssessments; ?> <?php echo e(t('vendor-assessments.showing_suffix')); ?>
                        </div>
                        <?php if ($totalPages > 1): ?>
                        <ul class="pagination">
                            <?php if ($currentPage > 1): ?>
                            <li><a href="<?php echo buildPageUrl(1); ?>">&laquo;</a></li>
                            <li><a href="<?php echo buildPageUrl($currentPage - 1); ?>">&lsaquo;</a></li>
                            <?php else: ?>
                            <li class="disabled"><span>&laquo;</span></li>
                            <li class="disabled"><span>&lsaquo;</span></li>
                            <?php endif; ?>

                            <?php
                            // Show page numbers
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($totalPages, $currentPage + 2);

                            if ($startPage > 1): ?>
                            <li><a href="<?php echo buildPageUrl(1); ?>">1</a></li>
                            <?php if ($startPage > 2): ?><li class="disabled"><span>...</span></li><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="<?php echo $i === $currentPage ? 'active' : ''; ?>">
                                <?php if ($i === $currentPage): ?>
                                <span><?php echo $i; ?></span>
                                <?php else: ?>
                                <a href="<?php echo buildPageUrl($i); ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            </li>
                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?><li class="disabled"><span>...</span></li><?php endif; ?>
                            <li><a href="<?php echo buildPageUrl($totalPages); ?>"><?php echo $totalPages; ?></a></li>
                            <?php endif; ?>

                            <?php if ($currentPage < $totalPages): ?>
                            <li><a href="<?php echo buildPageUrl($currentPage + 1); ?>">&rsaquo;</a></li>
                            <li><a href="<?php echo buildPageUrl($totalPages); ?>">&raquo;</a></li>
                            <?php else: ?>
                            <li class="disabled"><span>&rsaquo;</span></li>
                            <li class="disabled"><span>&raquo;</span></li>
                            <?php endif; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Create Assessment Modal -->
    <?php if (!$isProcurement || $isAdmin || $isCyberTPRM): ?>
    <div id="createModal" class="modal">
        <div class="modal-content">
            <span class="close" data-close="createModal">&times;</span>
            <h3 style="margin-top: 0; color: var(--theme-header-color);"><?php echo ($isStakeholder && !$isAdmin && !$isCyberTPRM) ? e(t('vendor-assessments.new_vendor_request')) : e(t('vendor-assessments.create_new_assessment')); ?></h3>
            <form method="POST" id="createAssessmentForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                <div class="form-group">
                    <label><?php echo e(t('vendor-assessments.label_assessment_type')); ?> <span class="required">*</span></label>
                    <select name="template_id" class="form-control" required>
                        <option value=""><?php echo e(t('vendor-assessments.select_type')); ?></option>
                        <?php
                        // Group templates by category
                        $categoryLabels = [
                            'vendor_assessment' => t('vendor-assessments.cat_vendor_assessment'),
                            'procurement' => t('vendor-assessments.cat_procurement'),
                            'onboarding' => t('vendor-assessments.cat_onboarding'),
                        ];
                        $grouped = [];
                        foreach ($templates as $t) {
                            $cat = $t['category'] ?? 'vendor_assessment';
                            $grouped[$cat][] = $t;
                        }
                        foreach ($grouped as $cat => $catTemplates): ?>
                        <optgroup label="<?php echo e($categoryLabels[$cat] ?? ucfirst($cat)); ?>">
                            <?php foreach ($catTemplates as $t): ?>
                            <option value="<?php echo $t['id']; ?>" data-category="<?php echo e($cat); ?>"><?php echo e($t['name']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <input type="hidden" name="is_new_vendor" id="isNewVendor" value="">

                <!-- Existing vendor search (shown for non-onboarding templates) -->
                <div id="existingVendorSection" class="form-group" style="position: relative;">
                    <label><?php echo e(t('vendor-assessments.label_link_existing_vendor')); ?> <span class="required">*</span></label>
                    <input type="hidden" name="vendor_request_id" id="vendorRequestId" value="<?php echo $preselectedVendor ? $preselectedVendor['id'] : ''; ?>">
                    <input type="text" class="form-control" id="vendorSearch" placeholder="<?php echo e(t('vendor-assessments.vendor_search_placeholder')); ?>" autocomplete="off" value="<?php echo $preselectedVendor ? e($preselectedVendor['vendor_name'] . ($preselectedVendor['vendor_domain'] ? ' (' . $preselectedVendor['vendor_domain'] . ')' : '')) : ''; ?>">
                    <div id="vendorSuggestions" style="display:none; position:absolute; top:100%; left:0; right:0; background:white; border:1px solid #ddd; border-top:none; border-radius:0 0 4px 4px; max-height:200px; overflow-y:auto; z-index:100; box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>
                    <div class="form-help"><?php echo e(t('vendor-assessments.help_search_vendor')); ?></div>
                </div>

                <!-- New vendor fields (shown for onboarding templates) -->
                <div id="newVendorSection" style="display: none;">
                    <div class="form-group">
                        <label><?php echo e(t('vendor-assessments.label_vendor_domain')); ?></label>
                        <input type="text" name="vendor_domain" id="newVendorDomain" class="form-control" placeholder="<?php echo e(t('vendor-assessments.placeholder_domain_example')); ?>">
                        <div class="form-help"><?php echo e(t('vendor-assessments.help_vendor_domain')); ?></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><?php echo e(t('vendor-assessments.label_vendor_name')); ?> <span class="required">*</span></label>
                        <input type="text" name="vendor_name" id="vendorName" class="form-control" required value="<?php echo $preselectedVendor ? e($preselectedVendor['vendor_name']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label><?php echo e(t('vendor-assessments.label_vendor_contact_name')); ?> <span class="required">*</span></label>
                        <input type="text" name="vendor_contact" id="vendorContact" class="form-control" required value="<?php echo $preselectedVendor ? e($preselectedVendor['vendor_contact']) : ''; ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><?php echo e(t('vendor-assessments.label_vendor_contact_email')); ?> <span class="required">*</span></label>
                        <input type="email" name="vendor_contact_email" id="vendorContactEmail" class="form-control" required value="<?php echo $preselectedVendor ? e($preselectedVendor['vendor_contact_email']) : ''; ?>">
                        <div class="form-help"><?php echo e(t('vendor-assessments.help_contact_email')); ?></div>
                    </div>
                    <div class="form-group">
                        <label><?php echo e(t('vendor-assessments.label_requester_email')); ?></label>
                        <input type="email" name="vendor_email" id="vendorEmail" class="form-control" value="<?php echo $preselectedVendor ? e($preselectedVendor['vendor_email']) : e($user['email']); ?>">
                        <div class="form-help"><?php echo e(t('vendor-assessments.help_requester_email')); ?></div>
                    </div>
                </div>

                <div class="form-group">
                    <label><?php echo e(t('vendor-assessments.label_link_expires_in')); ?></label>
                    <select name="expires_in_days" class="form-control">
                        <option value="7"><?php echo e(t('vendor-assessments.expires_7_days')); ?></option>
                        <option value="14"><?php echo e(t('vendor-assessments.expires_14_days')); ?></option>
                        <option value="30" selected><?php echo e(t('vendor-assessments.expires_30_days')); ?></option>
                        <option value="60"><?php echo e(t('vendor-assessments.expires_60_days')); ?></option>
                        <option value="90"><?php echo e(t('vendor-assessments.expires_90_days')); ?></option>
                    </select>
                </div>

                <div id="vendorError" style="display:none; color:#991b1b; background:#fef2f2; border:1px solid #fecaca; padding:8px 12px; border-radius:4px; margin-bottom:15px; font-size:13px;">
                    <?php echo e(t('vendor-assessments.select_valid_vendor_results')); ?>
                </div>

                <input type="hidden" name="download_pdf" id="downloadPdfFlag" value="0">
                <div style="display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap;">
                    <button type="submit" name="create_assessment" class="btn btn-primary" id="createAssessmentBtn"><?php echo e(t('vendor-assessments.btn_create_assessment')); ?></button>
                    <button type="submit" name="create_assessment" class="btn" id="downloadPdfBtn" style="background:#b91c1c;color:white;" title="<?php echo e(t('vendor-assessments.btn_download_pdf_title')); ?>">
                        <span style="display:inline-block;margin-right:6px;font-weight:700;">PDF</span> <?php echo e(t('vendor-assessments.btn_download_fillable_pdf')); ?>
                    </button>
                    <button type="button" class="btn btn-secondary" data-close="createModal"><?php echo e(t('vendor-assessments.btn_cancel')); ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Email Recipients Modal -->
    <div id="emailModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
        <div style="background:white; padding:30px; border-radius:8px; max-width:520px; width:90%; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; color:#333; font-size:18px;"><?php echo e(t('vendor-assessments.send_assessment_email')); ?></h3>
                <button type="button" id="emailModalCloseX" style="background:none; border:none; font-size:22px; cursor:pointer; color:#999; line-height:1;">&times;</button>
            </div>
            <p style="margin:0 0 6px; color:#666; font-size:14px;"><?php echo e(t('vendor-assessments.vendor_label')); ?> <strong id="emailModalVendorName"></strong></p>
            <label style="display:block; margin:12px 0 6px; font-weight:600; color:#333; font-size:14px;"><?php echo e(t('vendor-assessments.recipients')); ?></label>
            <div id="emailModalTags" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; min-height:10px;"></div>
            <div style="display:flex; gap:6px;">
                <input type="email" id="emailModalNewAddr" placeholder="<?php echo e(t('vendor-assessments.add_email_placeholder')); ?>" style="flex:1; padding:8px 10px; border:1px solid #d1d5db; border-radius:4px; font-size:14px;">
                <button type="button" id="emailModalAddBtn" class="btn btn-sm" style="background:#3b82f6; color:white; padding:8px 14px; white-space:nowrap;"><?php echo e(t('vendor-assessments.btn_add')); ?></button>
            </div>
            <p style="margin:4px 0 0; color:#999; font-size:12px;"><?php echo e(t('vendor-assessments.separate_addresses')); ?></p>
            <div id="emailModalStatus" style="display:none; margin-top:12px; padding:8px 10px; background:#f9fafb; border-radius:4px; font-size:13px;"></div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" id="emailModalCancelBtn" class="btn btn-sm btn-secondary" style="padding:8px 18px;"><?php echo e(t('vendor-assessments.btn_cancel')); ?></button>
                <button type="button" id="emailModalSendBtn" class="btn btn-sm" style="background:#10b981; color:white; padding:8px 18px;"><?php echo e(t('vendor-assessments.btn_send')); ?></button>
            </div>
        </div>
    </div>
    <style>
        .email-recipient-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.4;
        }
        .email-tag-remove {
            background: none;
            border: none;
            color: #0369a1;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            padding: 0 2px;
            opacity: 0.7;
        }
        .email-tag-remove:hover {
            opacity: 1;
            color: #dc2626;
        }
    </style>

    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <script nonce="<?php echo cspNonce(); ?>">
        // Vendor data for typeahead
        const vendors = <?php echo json_encode($vendors ?: []); ?>;

        // Filter by type
        function filterByType(typeId) {
            const params = new URLSearchParams(window.location.search);
            if (typeId) {
                params.set('type', typeId);
            } else {
                params.delete('type');
            }
            params.delete('page'); // Reset to first page
            window.location.href = '?' + params.toString();
        }

        // Change per page
        function changePerPage(perPage) {
            const params = new URLSearchParams(window.location.search);
            params.set('per_page', perPage);
            params.delete('page'); // Reset to first page
            window.location.href = '?' + params.toString();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function selectVendor(v) {
            document.getElementById('vendorRequestId').value = v.id;
            document.getElementById('vendorSearch').value = v.vendor_name + (v.vendor_domain ? ' (' + v.vendor_domain + ')' : '');
            document.getElementById('vendorName').value = v.vendor_name || '';
            document.getElementById('vendorEmail').value = v.vendor_email || '';
            document.getElementById('vendorContact').value = v.vendor_contact || '';
            document.getElementById('vendorContactEmail').value = v.vendor_contact_email || '';
            document.getElementById('vendorSuggestions').style.display = 'none';
        }

        // Track whether current template is onboarding category
        var isOnboardingMode = false;

        function toggleOnboardingMode(onboarding) {
            isOnboardingMode = onboarding;
            var existingSection = document.getElementById('existingVendorSection');
            var newSection = document.getElementById('newVendorSection');
            var isNewVendorField = document.getElementById('isNewVendor');
            var vendorNameField = document.getElementById('vendorName');
            var vendorEmailField = document.getElementById('vendorEmail');
            var vendorContactField = document.getElementById('vendorContact');
            var vendorContactEmailField = document.getElementById('vendorContactEmail');

            if (onboarding) {
                // Show new vendor fields, hide existing vendor search
                existingSection.style.display = 'none';
                newSection.style.display = '';
                isNewVendorField.value = '1';
                // Clear existing vendor selection
                document.getElementById('vendorRequestId').value = '';
                document.getElementById('vendorSearch').value = '';
                // Clear and enable name/email fields for new entry
                vendorNameField.value = '';
                vendorNameField.readOnly = false;
                vendorEmailField.value = '';
                vendorEmailField.readOnly = false;
                vendorContactField.value = '';
                vendorContactEmailField.value = '';
            } else {
                // Show existing vendor search, hide new vendor fields
                existingSection.style.display = '';
                newSection.style.display = 'none';
                isNewVendorField.value = '';
            }
            document.getElementById('vendorError').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            var vendorSearch = document.getElementById('vendorSearch');
            var vendorSuggestions = document.getElementById('vendorSuggestions');

            if (!vendorSearch || !vendorSuggestions) {
                console.error('Typeahead elements not found');
                return;
            }

            // Template selection change -- toggle onboarding mode
            var templateSelect = document.querySelector('select[name="template_id"]');
            if (templateSelect) {
                templateSelect.addEventListener('change', function() {
                    var selected = this.options[this.selectedIndex];
                    var category = selected ? selected.getAttribute('data-category') : '';
                    toggleOnboardingMode(category === 'onboarding');
                });
            }

            vendorSearch.addEventListener('input', function() {
                var query = this.value.toLowerCase().trim();
                vendorSuggestions.innerHTML = '';

                // Clear vendor selection if search field is cleared or changed
                // User must reselect from dropdown to set a valid vendor
                document.getElementById('vendorRequestId').value = '';
                document.getElementById('vendorError').style.display = 'none';

                if (query.length < 2) {
                    vendorSuggestions.style.display = 'none';
                    return;
                }

                var matches = vendors.filter(function(v) {
                    var name = (v.vendor_name || '').toLowerCase();
                    var domain = (v.vendor_domain || '').toLowerCase();
                    return name.includes(query) || domain.includes(query);
                }).slice(0, 10);

                if (matches.length === 0) {
                    vendorSuggestions.style.display = 'none';
                    return;
                }

                matches.forEach(function(v) {
                    var div = document.createElement('div');
                    div.style.cssText = 'padding:10px 12px; cursor:pointer; border-bottom:1px solid #eee;';
                    div.innerHTML = '<strong>' + escapeHtml(v.vendor_name || '') + '</strong>' +
                        (v.vendor_domain ? '<br><small style="color:#666;">' + escapeHtml(v.vendor_domain) + '</small>' : '');
                    div.addEventListener('mouseenter', function() { div.style.background = '#f3f4f6'; });
                    div.addEventListener('mouseleave', function() { div.style.background = 'white'; });
                    div.addEventListener('click', function() { selectVendor(v); });
                    vendorSuggestions.appendChild(div);
                });

                vendorSuggestions.style.display = 'block';
            });

            vendorSearch.addEventListener('blur', function() {
                setTimeout(function() { vendorSuggestions.style.display = 'none'; }, 200);
            });

            // Auto-open modal if coming from vendor record (but not after successful creation)
            <?php if ($preselectedVendor && empty($success) && empty($successHtml)): ?>
            document.getElementById('createModal').style.display = 'block';
            // Scrub link_vendor from the address bar once the modal is open so the
            // client-side Type / per-page handlers (which rebuild the URL from
            // window.location.search) don't carry it forward and re-open this modal.
            try {
                var _u = new URL(window.location.href);
                if (_u.searchParams.has('link_vendor')) {
                    _u.searchParams.delete('link_vendor');
                    window.history.replaceState({}, '', _u.toString());
                }
            } catch (e) { /* older browsers: harmless, server-side guard already handles links */ }
            <?php endif; ?>

            // Form validation for create assessment
            var createForm = document.getElementById('createAssessmentForm');
            if (createForm) {
                createForm.addEventListener('submit', function(e) {
                    if (!validateVendorSelection()) {
                        e.preventDefault();
                    }
                });
            }

            // Download Fillable PDF button -- same creation flow as
            // "Create Assessment" (all required fields enforced + audit
            // trail written) but the server redirects to the PDF endpoint
            // instead of sending email.
            var pdfBtn = document.getElementById('downloadPdfBtn');
            var pdfFlag = document.getElementById('downloadPdfFlag');
            if (pdfBtn && pdfFlag) {
                pdfBtn.addEventListener('click', function() {
                    pdfFlag.value = '1';
                });
            }
            // If user clicks the normal Create button afterwards, make sure
            // we don't still have the download flag set from a previous click.
            var createBtn = document.getElementById('createAssessmentBtn');
            if (createBtn && pdfFlag) {
                createBtn.addEventListener('click', function() {
                    pdfFlag.value = '0';
                });
            }
        });

        function validateVendorSelection() {
            var vendorError = document.getElementById('vendorError');

            // In onboarding mode, vendor name and email are required but no existing vendor needed
            if (isOnboardingMode) {
                var vendorName = document.getElementById('vendorName').value.trim();
                var vendorEmail = document.getElementById('vendorEmail').value.trim();
                if (!vendorName || !vendorEmail) {
                    vendorError.textContent = <?php echo json_encode(t('vendor-assessments.js_enter_name_email')); ?>;
                    vendorError.style.display = 'block';
                    document.getElementById('vendorName').focus();
                    return false;
                }
                vendorError.style.display = 'none';
                return true;
            }

            // Non-onboarding: require existing vendor selection
            var vendorRequestId = document.getElementById('vendorRequestId').value;
            if (!vendorRequestId || vendorRequestId === '') {
                vendorError.textContent = <?php echo json_encode(t('vendor-assessments.js_select_valid_vendor')); ?>;
                vendorError.style.display = 'block';
                document.getElementById('vendorSearch').focus();
                return false;
            }

            vendorError.style.display = 'none';
            return true;
        }

        function copyLink(uuid) {
            const url = <?php echo json_encode(baseUrl('')); ?> + 'vendor-assessment.php?token=' + uuid;
            navigator.clipboard.writeText(url).then(() => {
                alert(<?php echo json_encode(t('vendor-assessments.js_link_copied')); ?>);
            });
        }

        const emailEnabled = <?php echo $emailEnabled ? 'true' : 'false'; ?>;
        var csrfToken = <?php echo json_encode($csrfToken); ?>;

        // --- Email Modal State ---
        var emailModalState = { uuid: '', vendorName: '', recipients: [] };

        function emailLink(uuid, vendorName, vendorEmail) {
            emailModalState.uuid = uuid;
            emailModalState.vendorName = vendorName;
            emailModalState.recipients = vendorEmail ? [vendorEmail] : [];

            document.getElementById('emailModalVendorName').textContent = vendorName;
            document.getElementById('emailModalNewAddr').value = '';
            document.getElementById('emailModalStatus').textContent = '';
            document.getElementById('emailModalStatus').style.display = 'none';
            document.getElementById('emailModalSendBtn').disabled = false;
            document.getElementById('emailModalSendBtn').textContent = <?php echo json_encode(t('vendor-assessments.btn_send')); ?>;
            renderRecipientTags();
            document.getElementById('emailModal').style.display = 'flex';
        }

        function closeEmailModal() {
            document.getElementById('emailModal').style.display = 'none';
        }

        function renderRecipientTags() {
            var container = document.getElementById('emailModalTags');
            container.innerHTML = '';
            emailModalState.recipients.forEach(function(email, idx) {
                var tag = document.createElement('span');
                tag.className = 'email-recipient-tag';
                tag.innerHTML = '<span>' + escapeHtml(email) + '</span>' +
                    '<button type="button" class="email-tag-remove" data-idx="' + idx + '" title="' + <?php echo json_encode(t('vendor-assessments.js_remove_title')); ?> + '">&times;</button>';
                container.appendChild(tag);
            });
            // Attach remove handlers
            container.querySelectorAll('.email-tag-remove').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var i = parseInt(this.getAttribute('data-idx'), 10);
                    emailModalState.recipients.splice(i, 1);
                    renderRecipientTags();
                });
            });
            // Toggle send button
            document.getElementById('emailModalSendBtn').disabled = emailModalState.recipients.length === 0;
        }

        function escapeHtml(str) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(str));
            return d.innerHTML;
        }

        function addEmailRecipient() {
            var input = document.getElementById('emailModalNewAddr');
            var raw = input.value.trim();
            if (!raw) return;

            // Split on commas, semicolons, or spaces to allow pasting multiple addresses
            var addresses = raw.split(/[,;\s]+/).filter(function(a) { return a.length > 0; });
            var invalid = [];
            addresses.forEach(function(addr) {
                if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(addr)) {
                    if (emailModalState.recipients.indexOf(addr) === -1) {
                        emailModalState.recipients.push(addr);
                    }
                } else {
                    invalid.push(addr);
                }
            });
            input.value = invalid.length ? invalid.join(', ') : '';
            renderRecipientTags();
            if (invalid.length) {
                input.focus();
            }
        }

        function sendEmailModal() {
            var recipients = emailModalState.recipients.slice();
            if (recipients.length === 0) return;

            var uuid = emailModalState.uuid;
            var vendorName = emailModalState.vendorName;
            var statusEl = document.getElementById('emailModalStatus');
            var sendBtn = document.getElementById('emailModalSendBtn');

            if (!emailEnabled) {
                // Fall back to mailto: with all recipients
                var url = <?php echo json_encode(baseUrl('')); ?> + 'vendor-assessment.php?token=' + uuid;
                var subject = encodeURIComponent(<?php echo json_encode(t('vendor-assessments.js_email_subject_prefix')); ?> + vendorName);
                var body = encodeURIComponent(
                    <?php echo json_encode(t('vendor-assessments.js_email_body_intro')); ?> +
                    url + '\n\n' +
                    <?php echo json_encode(t('vendor-assessments.js_email_body_outro')); ?>
                );
                window.location.href = 'mailto:' + recipients.join(',') + '?subject=' + subject + '&body=' + body;
                closeEmailModal();
                return;
            }

            sendBtn.disabled = true;
            sendBtn.textContent = <?php echo json_encode(t('vendor-assessments.js_sending')); ?>;
            statusEl.style.display = 'block';
            statusEl.style.color = '#666';
            statusEl.textContent = <?php echo json_encode(t('vendor-assessments.js_sending_to_prefix')); ?> + recipients.length + <?php echo json_encode(t('vendor-assessments.js_sending_to_suffix')); ?>;

            var successes = [];
            var failures = [];

            // Send sequentially so each request uses the fresh CSRF token
            // returned by the previous one (tokens are single-use).
            function sendNext(i) {
                if (i >= recipients.length) {
                    sendBtn.disabled = false;
                    sendBtn.textContent = <?php echo json_encode(t('vendor-assessments.btn_send')); ?>;
                    if (failures.length === 0) {
                        statusEl.style.color = '#10b981';
                        statusEl.textContent = <?php echo json_encode(t('vendor-assessments.js_email_sent_to')); ?> + successes.join(', ');
                        setTimeout(closeEmailModal, 1500);
                    } else {
                        var msg = '';
                        if (successes.length) msg += <?php echo json_encode(t('vendor-assessments.js_sent_to_label')); ?> + successes.join(', ') + '. ';
                        msg += <?php echo json_encode(t('vendor-assessments.js_failed_label')); ?> + failures.join('; ');
                        statusEl.style.color = '#ef4444';
                        statusEl.textContent = msg;
                    }
                    return;
                }
                var email = recipients[i];
                fetch('api/send-assessment-email.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        uuid: uuid,
                        vendor_name: vendorName,
                        vendor_email: email
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.csrf_token) {
                        csrfToken = data.csrf_token;
                        document.querySelectorAll('input[name="csrf_token"]').forEach(function(el) { el.value = csrfToken; });
                    }
                    if (data.success) {
                        successes.push(email);
                    } else {
                        failures.push(email + ': ' + (data.message || <?php echo json_encode(t('vendor-assessments.js_unknown_error')); ?>));
                    }
                })
                .catch(function() {
                    failures.push(email + ': ' + <?php echo json_encode(t('vendor-assessments.js_network_error')); ?>);
                })
                .finally(function() {
                    sendNext(i + 1);
                });
            }
            sendNext(0);
        }

        // NOTE: We intentionally do NOT close the create-assessment modal when the
        // backdrop is clicked. It is a data-entry form, and an accidental click
        // outside it used to wipe everything the user had typed. Use the X or the
        // Cancel button to dismiss it instead.

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('emailModal').style.display === 'flex') {
                closeEmailModal();
            }
        });

        // Attach modal button listeners (CSP blocks inline onclick)
        document.getElementById('emailModalCloseX').addEventListener('click', closeEmailModal);
        document.getElementById('emailModalCancelBtn').addEventListener('click', closeEmailModal);
        document.getElementById('emailModalSendBtn').addEventListener('click', sendEmailModal);
        document.getElementById('emailModalAddBtn').addEventListener('click', addEmailRecipient);
        document.getElementById('emailModalNewAddr').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addEmailRecipient();
            }
        });

    </script>

    <footer class="section footer-modern bg-gray-13">
        <div class="footer-modern-body">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                    <?php if (!empty($theme['footer_logo_url'])): ?>
                        <a class="brand" href="index.php">
                            <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('vendor-assessments.footer_logo_alt')); ?>" style="max-height: 45px;">
                        </a>
                    <?php endif; ?>
                    <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                </div>
                <p class="rights" style="margin: 0;">
                    <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                    <span class="copyright-year"><?php echo date('Y'); ?></span>
                    <span>.&nbsp;</span>
                    <span><?php echo e(t('vendor-assessments.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
