<?php
/**
 * GRC Unified Compliance Engine - Audits
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Audit management with full lifecycle: list audits with status and framework,
 * create/edit audits, record findings with severity, evidence request workflow,
 * findings with remediation tracking, and generate audit artifact exports.
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$db = Database::getInstance();

// GRC access check
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberGRC && !$isAuditor) {
    http_response_code(403);
    die('Access denied. GRC module requires cyber_grc, administrator, or auditor role.');
}

$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;

$grc = GRCService::getInstance();
$msg = '';
$msgType = '';

// Export handler (RTF or PDF)
if (isset($_GET['export']) && (int)$_GET['export'] > 0) {
    $exportId = (int)$_GET['export'];
    $exportFormat = ($_GET['format'] ?? 'rtf') === 'pdf' ? 'pdf' : 'rtf';
    $exportAudit = $db->fetchOne(
        'SELECT a.*, f.code as framework_code, f.name as framework_name, u.full_name as lead_auditor_name, gs.name as scope_name
         FROM grc_audits a
         LEFT JOIN grc_frameworks f ON f.id = a.framework_id
         LEFT JOIN users u ON u.id = a.lead_auditor_user_id
         LEFT JOIN grc_scopes gs ON gs.id = a.scope_id
         WHERE a.id = :id',
        [':id' => $exportId]
    );
    if ($exportAudit) {
        $exportFindings = $db->fetchAll(
            'SELECT af.*, ic.control_ref, fr.requirement_ref
             FROM grc_audit_findings af
             LEFT JOIN grc_internal_controls ic ON ic.id = af.control_id
             LEFT JOIN grc_framework_requirements fr ON fr.id = af.requirement_id
             WHERE af.audit_id = :aid
             ORDER BY FIELD(af.severity, "critical", "high", "medium", "low", "informational")',
            [':aid' => $exportId]
        );
        $exportAssessments = $db->fetchAll(
            'SELECT ara.assessment_status, ara.notes, fr.requirement_ref, fr.title as requirement_title, u.full_name as assessor_name, ara.assessed_at
             FROM grc_audit_requirement_assessments ara
             LEFT JOIN grc_framework_requirements fr ON fr.id = ara.requirement_id
             LEFT JOIN users u ON u.id = ara.assessor_user_id
             WHERE ara.audit_id = :aid
             ORDER BY fr.sort_order, fr.requirement_ref',
            [':aid' => $exportId]
        );

        // Helper to escape RTF special chars
        $rtfEsc = function($s) { return str_replace(['\\', '{', '}', "\n", "\r"], ['\\\\', '\\{', '\\}', ' ', ''], (string)$s); };

        if ($exportFormat === 'pdf') {
            // Build HTML for PDF rendering via browser print
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Audit Report - ' . htmlspecialchars($exportAudit['audit_ref']) . '</title>';
            $html .= '<style>
                @media print { body { margin: 0; } @page { margin: 20mm; } }
                body { font-family: "Segoe UI", Calibri, Arial, sans-serif; font-size: 12px; color: #333; max-width: 800px; margin: 0 auto; padding: 40px 20px; }
                h1 { font-size: 22px; text-align: center; margin-bottom: 4px; color: #1a1a1a; }
                h2 { font-size: 16px; border-bottom: 2px solid #e5e7eb; padding-bottom: 6px; margin-top: 30px; color: #1a1a1a; }
                .subtitle { text-align: center; font-size: 14px; color: #6b7280; margin-bottom: 30px; }
                .detail-row { display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 4px; }
                .detail-row .label { font-weight: 600; color: #6b7280; min-width: 110px; }
                table { width: 100%; border-collapse: collapse; margin: 12px 0 20px; font-size: 11px; }
                th { background: #f3f4f6; padding: 8px 10px; text-align: left; font-weight: 600; border: 1px solid #e5e7eb; }
                td { padding: 7px 10px; border: 1px solid #e5e7eb; vertical-align: top; }
                tr:nth-child(even) td { background: #f9fafb; }
                .severity-critical { color: #991b1b; font-weight: 600; }
                .severity-high { color: #dc3545; font-weight: 600; }
                .severity-medium { color: #d97706; }
                .severity-low { color: #3b82f6; }
                .conforming { color: #065f46; } .non_conforming { color: #991b1b; font-weight: 600; }
                .partially_conforming { color: #92400e; } .not_applicable { color: #6b7280; }
                .print-btn { display: block; text-align: center; margin: 20px auto; padding: 10px 30px; background: #3b82f6; color: #fff; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
                @media print { .print-btn { display: none; } }
            </style></head><body>';
            $html .= '<button class="print-btn" onclick="window.print()">Print / Save as PDF</button>';
            $html .= '<h1>Audit Report</h1>';
            $html .= '<div class="subtitle">' . htmlspecialchars($exportAudit['audit_ref'] . ' - ' . $exportAudit['title']) . '<br>Generated: ' . date('F j, Y') . '</div>';

            $html .= '<h2>Audit Details</h2>';
            $fields = [
                'Status' => ucfirst(str_replace('_', ' ', $exportAudit['status'])),
                'Type' => ucfirst($exportAudit['audit_type'] ?? '-'),
                'Framework' => $exportAudit['framework_name'] ?? ($exportAudit['framework_code'] ?? 'N/A'),
                'Scope' => $exportAudit['scope_name'] ?? '-',
                'Lead Auditor' => $exportAudit['lead_auditor_name'] ?? '-',
                'Planned' => ($exportAudit['planned_start'] ?? '-') . ' to ' . ($exportAudit['planned_end'] ?? '-'),
            ];
            foreach ($fields as $lbl => $val) {
                $html .= '<div class="detail-row"><span class="label">' . $lbl . ':</span> ' . htmlspecialchars($val) . '</div>';
            }

            if (!empty($exportAssessments)) {
                $html .= '<h2>Requirements Assessment</h2><table><thead><tr><th>Ref</th><th>Requirement</th><th>Status</th><th>Notes</th></tr></thead><tbody>';
                foreach ($exportAssessments as $ea) {
                    $sc = str_replace(' ', '_', $ea['assessment_status']);
                    $html .= '<tr><td style="white-space:nowrap;font-weight:500;">' . htmlspecialchars($ea['requirement_ref']) . '</td>';
                    $html .= '<td>' . htmlspecialchars($ea['requirement_title']) . '</td>';
                    $html .= '<td class="' . htmlspecialchars($sc) . '">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $ea['assessment_status']))) . '</td>';
                    $html .= '<td>' . htmlspecialchars($ea['notes'] ?? '') . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }

            if (!empty($exportFindings)) {
                $html .= '<h2>Findings</h2><table><thead><tr><th>Ref</th><th>Title</th><th>Severity</th><th>Status</th><th>Control/Req</th></tr></thead><tbody>';
                foreach ($exportFindings as $ef) {
                    $ref = $ef['control_ref'] ?: ($ef['requirement_ref'] ?: '-');
                    $html .= '<tr><td style="white-space:nowrap;font-weight:500;">' . htmlspecialchars($ef['finding_ref']) . '</td>';
                    $html .= '<td>' . htmlspecialchars($ef['title']) . '</td>';
                    $html .= '<td class="severity-' . htmlspecialchars($ef['severity']) . '">' . htmlspecialchars(ucfirst($ef['severity'])) . '</td>';
                    $html .= '<td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $ef['status']))) . '</td>';
                    $html .= '<td>' . htmlspecialchars($ref) . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }

            $html .= '</body></html>';
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        }

        // Build RTF
        $rtf = "{\\rtf1\\ansi\\deff0{\\fonttbl{\\f0 Calibri;}{\\f1 Courier New;}}\n";
        $rtf .= "{\\colortbl ;\\red0\\green0\\blue0;\\red220\\green53\\blue69;\\red5\\green150\\blue105;\\red107\\green114\\blue128;}\n";
        $rtf .= "\\f0\\fs24\n";

        $rtf .= "\\pard\\qc\\b\\fs36 Audit Report\\b0\\fs24\\par\n";
        $rtf .= "\\pard\\qc\\fs28 " . $rtfEsc($exportAudit['audit_ref'] . ' - ' . $exportAudit['title']) . "\\fs24\\par\n";
        $rtf .= "\\pard\\qc\\cf4 Generated: " . date('F j, Y') . "\\cf1\\par\\par\n";

        $rtf .= "\\pard\\b\\fs28 Audit Details\\b0\\fs24\\par\n";
        $rtf .= "\\pard Status: " . ucfirst(str_replace('_', ' ', $exportAudit['status'])) . "\\par\n";
        $rtf .= "Type: " . ucfirst($exportAudit['audit_type'] ?? '-') . "\\par\n";
        $rtf .= "Framework: " . $rtfEsc($exportAudit['framework_name'] ?? ($exportAudit['framework_code'] ?? 'N/A')) . "\\par\n";
        $rtf .= "Scope: " . $rtfEsc($exportAudit['scope_name'] ?? '-') . "\\par\n";
        $rtf .= "Lead Auditor: " . $rtfEsc($exportAudit['lead_auditor_name'] ?? '-') . "\\par\n";
        $rtf .= "Planned: " . ($exportAudit['planned_start'] ?? '-') . " to " . ($exportAudit['planned_end'] ?? '-') . "\\par\\par\n";

        if (!empty($exportAssessments)) {
            $rtf .= "\\pard\\b\\fs28 Requirements Assessment\\b0\\fs24\\par\n";
            foreach ($exportAssessments as $ea) {
                $statusLabel = ucfirst(str_replace('_', ' ', $ea['assessment_status']));
                $rtf .= "\\pard\\b " . $rtfEsc($ea['requirement_ref']) . "\\b0  " . $rtfEsc($ea['requirement_title']) . " \\endash  " . $statusLabel;
                if (!empty($ea['notes'])) {
                    $rtf .= " \\endash  " . $rtfEsc($ea['notes']);
                }
                $rtf .= "\\par\n";
            }
            $rtf .= "\\par\n";
        }

        if (!empty($exportFindings)) {
            $rtf .= "\\pard\\b\\fs28 Findings\\b0\\fs24\\par\n";
            foreach ($exportFindings as $ef) {
                $ref = $ef['control_ref'] ?: ($ef['requirement_ref'] ?: '-');
                $rtf .= "\\pard\\b " . $rtfEsc($ef['finding_ref']) . "\\b0  " . $rtfEsc($ef['title']) . "\\par\n";
                $rtf .= "  Severity: " . ucfirst($ef['severity']) . "  |  Status: " . ucfirst(str_replace('_', ' ', $ef['status'])) . "  |  Control/Req: " . $rtfEsc($ref) . "\\par\n";
                if (!empty($ef['description'])) {
                    $rtf .= "  " . $rtfEsc($ef['description']) . "\\par\n";
                }
                $rtf .= "\\par\n";
            }
        }

        $rtf .= "}";

        $filename = $exportAudit['audit_ref'] . '_Report_' . date('Y-m-d') . '.rtf';
        header('Content-Type: application/rtf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($rtf));
        echo $rtf;
        exit;
    }
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid CSRF token. Please try again.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_audit') {
            $title = trim($_POST['title'] ?? '');
            $frameworkId = !empty($_POST['framework_id']) ? (int)$_POST['framework_id'] : null;
            if ($title !== '') {
                $last = $db->fetchOne("SELECT audit_ref FROM grc_audits WHERE audit_ref LIKE 'AUD-%' ORDER BY id DESC LIMIT 1");
                $ref = $last ? 'AUD-' . str_pad((int)substr($last['audit_ref'], 4) + 1, 3, '0', STR_PAD_LEFT) : 'AUD-001';

                $db->insert('grc_audits', [
                    'audit_ref' => $ref,
                    'title' => $title,
                    'description' => trim($_POST['description'] ?? '') ?: null,
                    'audit_type' => $_POST['audit_type'] ?? 'internal',
                    'framework_id' => $frameworkId,
                    'scope_id' => !empty($_POST['scope_id']) ? (int)$_POST['scope_id'] : null,
                    'status' => 'planning',
                    'lead_auditor_user_id' => !empty($_POST['lead_auditor_id']) ? (int)$_POST['lead_auditor_id'] : null,
                    'planned_start' => !empty($_POST['planned_start_date']) ? $_POST['planned_start_date'] : null,
                    'planned_end' => !empty($_POST['planned_end_date']) ? $_POST['planned_end_date'] : null,
                    'created_by' => (int)$user['id'],
                ]);
                $msg = 'Audit created successfully.';
                $msgType = 'success';
            } else {
                $msg = 'Title is required.';
                $msgType = 'danger';
            }
        } elseif ($action === 'update_audit') {
            $auditId = (int)($_POST['audit_id'] ?? 0);
            if ($auditId > 0) {
                $updateData = [
                    'title' => trim($_POST['title'] ?? ''),
                    'description' => trim($_POST['description'] ?? '') ?: null,
                    'audit_type' => $_POST['audit_type'] ?? 'internal',
                    'scope_id' => !empty($_POST['scope_id']) ? (int)$_POST['scope_id'] : null,
                    'status' => $_POST['status'] ?? 'planning',
                    'lead_auditor_user_id' => !empty($_POST['lead_auditor_id']) ? (int)$_POST['lead_auditor_id'] : null,
                    'planned_start' => !empty($_POST['planned_start_date']) ? $_POST['planned_start_date'] : null,
                    'planned_end' => !empty($_POST['planned_end_date']) ? $_POST['planned_end_date'] : null,
                ];
                if ($_POST['status'] === 'fieldwork' && empty($_POST['actual_start'])) {
                    $updateData['actual_start'] = date('Y-m-d');
                }
                if ($_POST['status'] === 'closed' && empty($_POST['actual_end'])) {
                    $updateData['actual_end'] = date('Y-m-d');
                }
                $db->update('grc_audits', $updateData, 'id = :id', [':id' => $auditId]);
                $msg = 'Audit updated.';
                $msgType = 'success';
            }
        } elseif ($action === 'add_finding') {
            $auditId = (int)($_POST['audit_id'] ?? 0);
            $findingTitle = trim($_POST['finding_title'] ?? '');
            if ($auditId > 0 && $findingTitle !== '') {
                $last = $db->fetchOne("SELECT finding_ref FROM grc_audit_findings WHERE finding_ref LIKE 'FND-%' ORDER BY id DESC LIMIT 1");
                $fref = $last ? 'FND-' . str_pad((int)substr($last['finding_ref'], 4) + 1, 3, '0', STR_PAD_LEFT) : 'FND-001';

                $db->insert('grc_audit_findings', [
                    'finding_ref' => $fref,
                    'audit_id' => $auditId,
                    'title' => $findingTitle,
                    'description' => trim($_POST['finding_description'] ?? '') ?: null,
                    'severity' => $_POST['severity'] ?? 'medium',
                    'status' => 'open',
                    'control_id' => !empty($_POST['control_id']) ? (int)$_POST['control_id'] : null,
                    'requirement_id' => !empty($_POST['requirement_id']) ? (int)$_POST['requirement_id'] : null,
                    'remediation_plan' => trim($_POST['recommendation'] ?? '') ?: null,
                    'assigned_to' => !empty($_POST['assigned_to_finding']) ? (int)$_POST['assigned_to_finding'] : null,
                ]);
                $msg = 'Finding recorded.';
                $msgType = 'success';
            }
        } elseif ($action === 'update_finding') {
            $findingId = (int)($_POST['finding_id'] ?? 0);
            if ($findingId > 0) {
                $fUpdate = [
                    'status' => $_POST['finding_status'] ?? 'open',
                    'severity' => $_POST['severity'] ?? 'medium',
                ];
                if ($_POST['finding_status'] === 'remediated' || $_POST['finding_status'] === 'verified_closed') {
                    $fUpdate['closed_at'] = date('Y-m-d H:i:s');
                    $fUpdate['closed_by'] = (int)$user['id'];
                }
                $db->update('grc_audit_findings', $fUpdate, 'id = :id', [':id' => $findingId]);
                $msg = 'Finding updated.';
                $msgType = 'success';
            }
        } elseif ($action === 'add_remediation') {
            $findingId = (int)($_POST['finding_id'] ?? 0);
            $planTitle = trim($_POST['plan_title'] ?? '');
            if ($findingId > 0 && $planTitle !== '') {
                $db->insert('grc_remediation_plans', [
                    'finding_id' => $findingId,
                    'title' => $planTitle,
                    'description' => trim($_POST['plan_description'] ?? '') ?: null,
                    'assigned_to' => !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null,
                    'planned_completion' => !empty($_POST['planned_completion']) ? $_POST['planned_completion'] : null,
                    'status' => 'open',
                    'created_by' => (int)$user['id'],
                ]);
                $db->update('grc_audit_findings', ['status' => 'in_remediation'], 'id = :id', [':id' => $findingId]);
                $msg = 'Remediation plan added.';
                $msgType = 'success';
            }
        } elseif ($action === 'request_evidence') {
            $auditId = (int)($_POST['audit_id'] ?? 0);
            $requestDesc = trim($_POST['request_description'] ?? '');
            if ($auditId > 0 && $requestDesc !== '') {
                $db->insert('grc_audit_evidence_requests', [
                    'audit_id' => $auditId,
                    'control_id' => !empty($_POST['control_id']) ? (int)$_POST['control_id'] : null,
                    'description' => $requestDesc,
                    'assigned_to' => !empty($_POST['request_assigned_to']) ? (int)$_POST['request_assigned_to'] : null,
                    'due_date' => !empty($_POST['request_due_date']) ? $_POST['request_due_date'] : null,
                    'status' => 'requested',
                    'requested_by' => (int)$user['id'],
                ]);
                $msg = 'Evidence request created.';
                $msgType = 'success';
            }
        } elseif ($action === 'save_requirement_assessments') {
            $auditId = (int)($_POST['audit_id'] ?? 0);
            if ($auditId > 0 && !empty($_POST['assessments']) && is_array($_POST['assessments'])) {
                $count = 0;
                foreach ($_POST['assessments'] as $reqId => $status) {
                    $reqId = (int)$reqId;
                    $status = in_array($status, ['not_assessed', 'conforming', 'non_conforming', 'partially_conforming', 'not_applicable']) ? $status : 'not_assessed';
                    $notes = trim($_POST['assessment_notes'][$reqId] ?? '');

                    $existing = $db->fetchOne(
                        'SELECT id FROM grc_audit_requirement_assessments WHERE audit_id = :aid AND requirement_id = :rid',
                        [':aid' => $auditId, ':rid' => $reqId]
                    );

                    if ($existing) {
                        $db->update('grc_audit_requirement_assessments', [
                            'assessment_status' => $status,
                            'notes' => $notes ?: null,
                            'assessor_user_id' => (int)$user['id'],
                            'assessed_at' => date('Y-m-d H:i:s'),
                        ], 'id = :id', [':id' => $existing['id']]);
                    } else {
                        $db->insert('grc_audit_requirement_assessments', [
                            'audit_id' => $auditId,
                            'requirement_id' => $reqId,
                            'assessment_status' => $status,
                            'notes' => $notes ?: null,
                            'assessor_user_id' => (int)$user['id'],
                            'assessed_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                    $count++;
                }
                $msg = $count . ' requirement assessment(s) saved.';
                $msgType = 'success';
            }
        } elseif ($action === 'delete_audit') {
            $auditId = (int)($_POST['audit_id'] ?? 0);
            if ($auditId > 0 && $isAdmin) {
                $db->query('DELETE FROM grc_audit_requirement_assessments WHERE audit_id = :id', [':id' => $auditId]);
                $db->query('DELETE FROM grc_audit_evidence_requests WHERE audit_id = :id', [':id' => $auditId]);
                $db->query('DELETE FROM grc_audit_findings WHERE audit_id = :id', [':id' => $auditId]);
                $db->query('DELETE FROM grc_audits WHERE id = :id', [':id' => $auditId]);
                $msg = 'Audit deleted.';
                $msgType = 'success';
                header('Location: grc-audits.php?deleted=1');
                exit;
            } else {
                $msg = 'Only administrators can delete audits.';
                $msgType = 'danger';
            }
        }
        $csrfToken = $security->generateCSRFToken();
    }
}

// Handle deleted redirect
if (isset($_GET['deleted'])) {
    $msg = 'Audit deleted.';
    $msgType = 'success';
}

// Generate CSRF token for GET requests (POST handler above generates its own after validation)
if (!isset($csrfToken)) {
    $csrfToken = $security->generateCSRFToken();
}

// Check if AI is enabled for conditional UI features
require_once __DIR__ . '/includes/classes/AIPlatformService.php';
$aiEnabled = AIPlatformService::getInstance()->isEnabled();

// Load data
$frameworks = $grc->getFrameworks();
$users_list = $db->fetchAll('SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name');
$scopes = $grc->getScopes();

// Filters
$auditWhere = ['1=1'];
$auditParams = [];
if (!empty($_GET['status'])) {
    $auditWhere[] = 'a.status = :status';
    $auditParams[':status'] = $_GET['status'];
}
if (!empty($_GET['framework_id'])) {
    $auditWhere[] = 'a.framework_id = :fid';
    $auditParams[':fid'] = (int)$_GET['framework_id'];
}

$audits = $db->fetchAll(
    'SELECT a.*, f.code as framework_code, f.name as framework_name,
            u.full_name as lead_auditor_name,
            gs.name as scope_name,
            (SELECT COUNT(*) FROM grc_audit_findings af WHERE af.audit_id = a.id) as finding_count,
            (SELECT COUNT(*) FROM grc_audit_findings af WHERE af.audit_id = a.id AND af.status IN ("open","in_remediation")) as open_findings
     FROM grc_audits a
     LEFT JOIN grc_frameworks f ON f.id = a.framework_id
     LEFT JOIN users u ON u.id = a.lead_auditor_user_id
     LEFT JOIN grc_scopes gs ON gs.id = a.scope_id
     WHERE ' . implode(' AND ', $auditWhere) . '
     ORDER BY a.created_at DESC',
    $auditParams
);

// Detail view
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$auditDetail = null;
$findings = [];
$evidenceRequests = [];
$remediationPlans = [];
if ($viewId > 0) {
    $auditDetail = $db->fetchOne(
        'SELECT a.*, f.code as framework_code, f.name as framework_name, u.full_name as lead_auditor_name, gs.name as scope_name
         FROM grc_audits a
         LEFT JOIN grc_frameworks f ON f.id = a.framework_id
         LEFT JOIN users u ON u.id = a.lead_auditor_user_id
         LEFT JOIN grc_scopes gs ON gs.id = a.scope_id
         WHERE a.id = :id',
        [':id' => $viewId]
    );
    if ($auditDetail) {
        $findings = $db->fetchAll(
            'SELECT af.*, ic.control_ref, ic.title as control_title,
                    fr.requirement_ref, fr.title as requirement_title,
                    u.full_name as assigned_to_name
             FROM grc_audit_findings af
             LEFT JOIN grc_internal_controls ic ON ic.id = af.control_id
             LEFT JOIN grc_framework_requirements fr ON fr.id = af.requirement_id
             LEFT JOIN users u ON u.id = af.assigned_to
             WHERE af.audit_id = :aid
             ORDER BY FIELD(af.severity, "critical", "high", "medium", "low", "informational"), af.created_at DESC',
            [':aid' => $viewId]
        );
        $evidenceRequests = $db->fetchAll(
            'SELECT er.*, u.full_name as assigned_to_name, ru.full_name as requested_by_name
             FROM grc_audit_evidence_requests er
             LEFT JOIN users u ON u.id = er.assigned_to
             LEFT JOIN users ru ON ru.id = er.requested_by
             WHERE er.audit_id = :aid
             ORDER BY er.created_at DESC',
            [':aid' => $viewId]
        );
        // Get remediation plans for findings in this audit
        $findingIds = array_column($findings, 'id');
        if (!empty($findingIds)) {
            $placeholders = implode(',', array_fill(0, count($findingIds), '?'));
            $remediationPlans = $db->fetchAll(
                "SELECT rp.*, u.full_name as assigned_to_name
                 FROM grc_remediation_plans rp
                 LEFT JOIN users u ON u.id = rp.assigned_to
                 WHERE rp.finding_id IN ($placeholders)
                 ORDER BY rp.planned_completion ASC",
                array_values($findingIds)
            );
        }

        // Load framework requirements and their assessments for this audit
        $frameworkRequirements = [];
        $requirementAssessments = [];
        if (!empty($auditDetail['framework_id'])) {
            $frameworkRequirements = $db->fetchAll(
                'SELECT fr.id, fr.requirement_ref, fr.title, fr.description, fr.is_required
                 FROM grc_framework_requirements fr
                 WHERE fr.framework_id = :fid
                 ORDER BY fr.sort_order, fr.requirement_ref',
                [':fid' => (int)$auditDetail['framework_id']]
            );

            $assessmentRows = $db->fetchAll(
                'SELECT ara.*, u.full_name as assessor_name
                 FROM grc_audit_requirement_assessments ara
                 LEFT JOIN users u ON u.id = ara.assessor_user_id
                 WHERE ara.audit_id = :aid',
                [':aid' => $viewId]
            );
            foreach ($assessmentRows as $ar) {
                $requirementAssessments[(int)$ar['requirement_id']] = $ar;
            }
        }
    }
}

// Edit mode
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editAudit = null;
if ($editId > 0 && !$readOnly) {
    $editAudit = $db->fetchOne('SELECT * FROM grc_audits WHERE id = :id', [':id' => $editId]);
}

$allControls = $db->fetchAll('SELECT id, control_ref, title FROM grc_internal_controls WHERE is_active = 1 ORDER BY control_ref');

// Load framework requirements for finding dropdown
$findingRequirements = [];
$activeFrameworkId = isset($auditDetail['framework_id']) ? $auditDetail['framework_id'] : (isset($editAudit['framework_id']) ? $editAudit['framework_id'] : null);
if ($activeFrameworkId) {
    $findingRequirements = $db->fetchAll(
        'SELECT id, requirement_ref, title FROM grc_framework_requirements WHERE framework_id = :fid ORDER BY sort_order, requirement_ref',
        [':fid' => (int)$activeFrameworkId]
    );
}

$currentPage = 'grc_audits';
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title>Audits - GRC Compliance Engine</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
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
        a { text-decoration: none; }
        a:hover { text-decoration: none; }
        body { margin: 0; font-family: 'Roboto', sans-serif; }
        .page { display: flex; flex-direction: column; min-height: 100vh; }
        .page-header { display: none !important; }
        .top-bar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 10px 20px; display: flex; justify-content: flex-end; align-items: center; flex-shrink: 0; }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a { color: #333; text-decoration: none; padding: 8px 15px; border-radius: 4px; background: rgba(255,101,67,0.1); transition: background 0.2s; font-size: 14px; }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }
        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }
        .sidebar { width: var(--sidebar-width) !important; min-width: var(--sidebar-width) !important; max-width: var(--sidebar-width) !important; background: var(--nav-fill-color) !important; padding: 0; flex-shrink: 0; display: flex; flex-direction: column; }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title { color: var(--nav-font-color); font-size: 13px; font-weight: 500; margin-top: 8px; opacity: 0.9; }
        .sidebar-content { flex: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--nav-font-color); opacity: 0.5; padding: 0 20px; margin-bottom: 10px; }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li a { display: flex; align-items: center; gap: 10px; padding: 11px 20px; color: var(--nav-font-color); opacity: 0.85; text-decoration: none; font-size: 13px; transition: all 0.2s; border-left: 3px solid transparent; }
        .sidebar-nav li a:hover { background: rgba(255,255,255,0.1); opacity: 1; border-left-color: var(--nav-font-color); }
        .sidebar-nav li a.active { background: rgba(255,255,255,0.15); opacity: 1; border-left-color: var(--nav-font-color); font-weight: 500; }
        .sidebar-nav li a .icon { font-size: 16px; width: 20px; text-align: center; opacity: 0.9; }
        .sidebar-nav li a .badge { margin-left: auto; background: rgba(255,255,255,0.2); color: var(--nav-font-color); font-size: 10px; padding: 2px 7px; border-radius: 10px; }
        .main-content { flex: 1; padding: 35px 40px; background: #f9fafb; min-width: 0; overflow-y: auto; }

        .section-header { font-size: 18px; font-weight: 600; color: #333; margin: 30px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #e5e7eb; }
        .section-header:first-of-type { margin-top: 0; }

        .grc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
        .grc-table th { background: #f3f4f6; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .grc-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #333; vertical-align: top; }
        .grc-table tr:hover td { background: #f9fafb; }

        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; align-items: flex-end; }
        .filter-bar label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 4px; }
        .filter-bar select { padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; min-width: 140px; }

        .grc-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
        .grc-form label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px; }
        .grc-form input, .grc-form select, .grc-form textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; margin-bottom: 12px; font-family: inherit; }
        .grc-form textarea { min-height: 80px; resize: vertical; }
        .grc-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grc-form .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

        .btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--theme-button-color, #ff6543); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f3f4f6; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-info { background: #3b82f6; color: #fff !important; }
        .btn-info:hover { opacity: 0.9; text-decoration: none; }
        .export-menu { display: none; position: absolute; right: 0; top: 100%; margin-top: 4px; background: #fff; border: 1px solid #d1d5db; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100; min-width: 100px; overflow: hidden; }
        .export-menu.show { display: block; }
        .export-menu a { display: block; padding: 8px 16px; font-size: 13px; color: #333; text-decoration: none; }
        .export-menu a:hover { background: #f3f4f6; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .back-link { display: inline-block; margin-bottom: 16px; color: #6b7280; text-decoration: none; font-size: 13px; }
        .back-link:hover { color: #333; }

        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; text-transform: capitalize; }
        .status-planning { background: #f3f4f6; color: #6b7280; }
        .status-fieldwork { background: #dbeafe; color: #1e40af; }
        .status-reporting { background: #fef3c7; color: #92400e; }
        .status-remediation { background: #fce7f3; color: #9d174d; }
        .status-closed { background: #d1fae5; color: #065f46; }
        .status-not_assessed { background: #f3f4f6; color: #6b7280; }
        .status-conforming { background: #d1fae5; color: #065f46; }
        .status-non_conforming { background: #fee2e2; color: #991b1b; }
        .status-partially_conforming { background: #fef3c7; color: #92400e; }
        .status-not_applicable { background: #e5e7eb; color: #6b7280; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-danger:hover { opacity: 0.9; }

        .severity-critical { color: #991b1b; font-weight: 600; }
        .severity-high { color: #dc3545; font-weight: 600; }
        .severity-medium { color: #f59e0b; }
        .severity-low { color: #3b82f6; }
        .severity-informational { color: #6b7280; }

        .detail-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
        .detail-card h3 { margin: 0 0 12px; font-size: 15px; color: #333; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .detail-item { font-size: 13px; }
        .detail-item .label { color: #6b7280; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
        .detail-item .value { color: #333; }

        /* Notes button */
        .btn-notes { padding:3px 10px; border:1px solid #d1d5db; border-radius:4px; font-size:11px; background:#fff; color:#6b7280; cursor:pointer; white-space:nowrap; }
        .btn-notes:hover { background:#f3f4f6; color:#333; }
        .btn-notes.has-notes { background:#dbeafe; color:#1e40af; border-color:#93c5fd; }

        /* Requirement info "?" button */
        .btn-req-info { width:22px; height:22px; border-radius:50%; border:1px solid #93c5fd; background:#eff6ff; color:#3b82f6; font-size:12px; font-weight:700; cursor:pointer; line-height:20px; padding:0; text-align:center; }
        .btn-req-info:hover { background:#3b82f6; color:#fff; }

        /* Modal overlay */
        .grc-modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; justify-content:center; align-items:center; }
        .grc-modal-overlay.active { display:flex; }
        .grc-modal { background:#fff; border-radius:10px; width:700px; max-width:92vw; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.3); }
        .grc-modal-header { padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; }
        .grc-modal-header h4 { margin:0; font-size:15px; color:#333; }
        .grc-modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#6b7280; padding:0 4px; line-height:1; }
        .grc-modal-close:hover { color:#333; }
        .grc-modal-body { padding:20px; overflow-y:auto; flex:1; }
        .grc-modal-footer { padding:14px 20px; border-top:1px solid #e5e7eb; display:flex; gap:8px; justify-content:flex-end; }

        /* Notes modal textarea */
        .notes-textarea { width:100%; min-height:160px; border:1px solid #d1d5db; border-radius:6px; padding:10px; font-size:13px; font-family:inherit; resize:vertical; }
        .notes-textarea:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,0.15); }

        /* AI refine preview */
        .refine-preview { background:#f0fdf4; border:1px solid #86efac; border-radius:6px; padding:12px; margin-top:12px; font-size:13px; color:#14532d; display:none; }
        .refine-preview-label { font-size:11px; font-weight:600; text-transform:uppercase; color:#059669; margin-bottom:6px; }

        /* Evidence section in notes modal */
        .evidence-section { margin-top:16px; border-top:1px solid #e5e7eb; padding-top:14px; }
        .evidence-section-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:8px; }
        .evidence-list { list-style:none; padding:0; margin:0 0 10px; }
        .evidence-list li { display:flex; align-items:center; gap:8px; padding:6px 8px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:5px; margin-bottom:4px; font-size:12px; }
        .evidence-list li .ev-icon { font-size:14px; flex-shrink:0; }
        .evidence-list li .ev-info { flex:1; min-width:0; }
        .evidence-list li .ev-title { font-weight:500; color:#333; }
        .evidence-list li .ev-meta { color:#9ca3af; font-size:10px; }
        .evidence-list li .ev-remove { background:none; border:none; color:#dc3545; cursor:pointer; font-size:14px; padding:0 4px; flex-shrink:0; }
        .evidence-list li .ev-remove:hover { color:#b91c1c; }
        .evidence-upload-row { display:flex; gap:8px; align-items:center; }
        .evidence-upload-row input[type="file"] { flex:1; font-size:12px; }
        .btn-upload { padding:4px 12px; font-size:11px; border:1px solid #3b82f6; background:#eff6ff; color:#1e40af; border-radius:4px; cursor:pointer; white-space:nowrap; }
        .btn-upload:hover { background:#dbeafe; }
        .btn-upload:disabled { opacity:0.5; cursor:not-allowed; }

        /* Requirement detail modal body */
        .req-detail-content h5 { font-size:13px; font-weight:600; color:#1e40af; margin:14px 0 6px; }
        .req-detail-content h5:first-child { margin-top:0; }
        .req-detail-content p { font-size:13px; color:#374151; margin:0 0 8px; line-height:1.5; }
        .req-detail-content ul { margin:4px 0 10px 18px; padding:0; font-size:13px; }
        .req-detail-content li { margin-bottom:4px; color:#374151; line-height:1.5; }

        .btn-ai { background:linear-gradient(135deg,#8b5cf6,#6366f1); color:#fff; border:none; padding:6px 14px; border-radius:5px; font-size:12px; cursor:pointer; }
        .btn-ai:hover { opacity:0.9; }
        .btn-ai:disabled { opacity:0.5; cursor:not-allowed; }

        .spinner-sm { display:inline-block; width:14px; height:14px; border:2px solid #fff; border-top-color:transparent; border-radius:50%; animation:spin .6s linear infinite; vertical-align:middle; margin-right:4px; }
        @keyframes spin { to { transform:rotate(360deg); } }

        @media (max-width: 900px) {
            .grc-form .form-row, .grc-form .form-row-3 { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
            .filter-bar { flex-direction: column; }
            .grc-modal { width: 95vw; }
        }
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar">
        <span style="margin-right:auto;font-size:14px;color:#333;">Welcome, <?php echo e($user['full_name']); ?></span>
        <div class="user-menu">
            <?php if ($isAdmin): ?><a href="admin.php">Admin</a><?php endif; ?>
            <a href="profile.php">Profile</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="main-layout">
        <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

        <main class="main-content">
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;">Audit Management</h1>
            <p style="color:#6b7280;margin:0 0 30px;">Plan audits, record findings, and track remediation</p>

            <?php if ($msg): ?>
            <div class="alert alert-<?php echo e($msgType); ?>"><?php echo e($msg); ?></div>
            <?php endif; ?>

            <?php if ($auditDetail): ?>
            <!-- Audit Detail -->
            <a href="grc-audits.php" class="back-link">&larr; Back to audits</a>

            <div class="detail-card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                    <h3><?php echo e($auditDetail['audit_ref']); ?> - <?php echo e($auditDetail['title']); ?></h3>
                    <div style="display:flex;gap:8px;">
                        <?php if (!$readOnly): ?>
                        <a href="grc-audits.php?edit=<?php echo (int)$auditDetail['id']; ?>" class="btn btn-sm btn-outline">Edit</a>
                        <?php endif; ?>
                        <div class="export-dropdown" style="position:relative;display:inline-block;">
                            <button type="button" class="btn btn-sm btn-info" data-action="toggle-export">Export &#9662;</button>
                            <div class="export-menu">
                                <a href="grc-audits.php?export=<?php echo (int)$auditDetail['id']; ?>&amp;format=pdf">PDF</a>
                                <a href="grc-audits.php?export=<?php echo (int)$auditDetail['id']; ?>&amp;format=rtf">RTF</a>
                            </div>
                        </div>
                        <?php if ($isAdmin): ?>
                        <form method="post" style="display:inline;" data-confirm="Delete this audit and all its findings, assessments, and evidence requests? This cannot be undone.">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="delete_audit">
                            <input type="hidden" name="audit_id" value="<?php echo (int)$auditDetail['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete Entire Audit</button>
                        </form>
                        <?php endif; ?>
                        <?php $hasReport = !empty($auditDetail['report_file_name']); ?>
                        <a href="grc-audit-report.php?audit_id=<?php echo (int)$auditDetail['id']; ?>" class="btn btn-sm btn-outline" style="background:#eef2ff;border-color:#6366f1;color:#4338ca;"><?php echo $hasReport ? 'Recreate Report' : 'Generate Report'; ?></a>
                        <?php if ($hasReport): ?>
                        <a href="api/grc-report-download.php?audit_id=<?php echo (int)$auditDetail['id']; ?>" class="btn btn-sm btn-outline" style="background:#f0fdf4;border-color:#22c55e;color:#15803d;">Download Latest Report</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="detail-grid">
                    <div class="detail-item"><div class="label">Status</div><div class="value"><span class="status-badge status-<?php echo e($auditDetail['status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $auditDetail['status']))); ?></span></div></div>
                    <div class="detail-item"><div class="label">Type</div><div class="value"><?php echo e(ucfirst($auditDetail['audit_type'] ?? '-')); ?></div></div>
                    <div class="detail-item"><div class="label">Framework</div><div class="value"><?php echo e($auditDetail['framework_name'] ?? ($auditDetail['framework_code'] ?? 'N/A')); ?></div></div>
                    <div class="detail-item"><div class="label">Scope</div><div class="value"><?php echo e($auditDetail['scope_name'] ?? '-'); ?></div></div>
                    <div class="detail-item"><div class="label">Lead Auditor</div><div class="value"><?php echo e($auditDetail['lead_auditor_name'] ?? '-'); ?></div></div>
                    <div class="detail-item"><div class="label">Planned Dates</div><div class="value"><?php echo $auditDetail['planned_start'] ? e($auditDetail['planned_start'] . ' to ' . ($auditDetail['planned_end'] ?? '?')) : '-'; ?></div></div>
                    <div class="detail-item"><div class="label">Actual Dates</div><div class="value"><?php echo $auditDetail['actual_start'] ? e($auditDetail['actual_start'] . ' to ' . ($auditDetail['actual_end'] ?? 'ongoing')) : '-'; ?></div></div>
                </div>
                <?php if (!empty($auditDetail['description'])): ?>
                <p style="font-size:13px;color:#374151;"><?php echo e($auditDetail['description']); ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($frameworkRequirements)): ?>
            <!-- Framework Requirements Assessment -->
            <div class="detail-card">
                <h3>Framework Requirements &mdash; <?php echo e($auditDetail['framework_name'] ?? $auditDetail['framework_code']); ?> (<?php echo count($frameworkRequirements); ?>)</h3>
                <?php
                    $assessedCount = 0;
                    $conformingCount = 0;
                    $nonConformingCount = 0;
                    foreach ($frameworkRequirements as $fr) {
                        $a = $requirementAssessments[(int)$fr['id']] ?? null;
                        if ($a && $a['assessment_status'] !== 'not_assessed') $assessedCount++;
                        if ($a && $a['assessment_status'] === 'conforming') $conformingCount++;
                        if ($a && $a['assessment_status'] === 'non_conforming') $nonConformingCount++;
                    }
                ?>
                <div style="display:flex;gap:20px;margin-bottom:16px;font-size:13px;">
                    <span>Assessed: <strong><?php echo $assessedCount; ?>/<?php echo count($frameworkRequirements); ?></strong></span>
                    <span style="color:#065f46;">Conforming: <strong><?php echo $conformingCount; ?></strong></span>
                    <span style="color:#991b1b;">Non-Conforming: <strong><?php echo $nonConformingCount; ?></strong></span>
                </div>

                <?php if (!$readOnly): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_requirement_assessments">
                    <input type="hidden" name="audit_id" value="<?php echo (int)$auditDetail['id']; ?>">
                <?php endif; ?>

                <div style="border:1px solid #e5e7eb;border-radius:6px;">
                <table class="grc-table" style="margin-bottom:0;">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Requirement</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th style="width:36px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($frameworkRequirements as $fr):
                        $assessment = $requirementAssessments[(int)$fr['id']] ?? null;
                        $currentStatus = $assessment['assessment_status'] ?? 'not_assessed';
                        $currentNotes = $assessment['notes'] ?? '';
                    ?>
                        <tr>
                            <td style="white-space:nowrap;font-weight:500;"><?php echo e($fr['requirement_ref']); ?></td>
                            <td title="<?php echo e($fr['description'] ?? ''); ?>"><?php echo e($fr['title']); ?></td>
                            <td>
                                <?php if (!$readOnly): ?>
                                <select name="assessments[<?php echo (int)$fr['id']; ?>]" style="padding:3px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:11px;width:auto;">
                                    <?php foreach (['not_assessed' => 'Not Assessed', 'conforming' => 'Conforming', 'non_conforming' => 'Non-Conforming', 'partially_conforming' => 'Partial', 'not_applicable' => 'N/A'] as $sv => $sl): ?>
                                    <option value="<?php echo e($sv); ?>" <?php echo $currentStatus === $sv ? 'selected' : ''; ?>><?php echo e($sl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <span class="status-badge status-<?php echo e($currentStatus); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $currentStatus))); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="hidden" name="assessment_notes[<?php echo (int)$fr['id']; ?>]" id="notes-val-<?php echo (int)$fr['id']; ?>" value="<?php echo e($currentNotes); ?>">
                                <?php if (!$readOnly): ?>
                                <button type="button" class="btn-notes <?php echo $currentNotes ? 'has-notes' : ''; ?>" data-action="open-notes" data-req-id="<?php echo (int)$fr['id']; ?>" data-req-ref="<?php echo e($fr['requirement_ref']); ?>" data-req-title="<?php echo e($fr['title']); ?>" title="<?php echo $currentNotes ? e($currentNotes) : 'Add notes'; ?>"><?php echo $currentNotes ? 'Edit Notes' : 'Add Notes'; ?></button>
                                <?php else: ?>
                                <?php if ($currentNotes): ?>
                                <button type="button" class="btn-notes has-notes" data-action="open-notes" data-req-id="<?php echo (int)$fr['id']; ?>" data-req-ref="<?php echo e($fr['requirement_ref']); ?>" data-req-title="<?php echo e($fr['title']); ?>" title="<?php echo e($currentNotes); ?>">View Notes</button>
                                <?php else: ?>
                                <span style="color:#9ca3af;font-size:11px;">—</span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <button type="button" class="btn-req-info" data-action="open-req-detail" data-req-id="<?php echo (int)$fr['id']; ?>" data-req-ref="<?php echo e($fr['requirement_ref']); ?>" data-req-title="<?php echo e($fr['title']); ?>" data-req-desc="<?php echo e($fr['description'] ?? ''); ?>" title="View requirement details">?</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if (!$readOnly): ?>
                    <button type="submit" class="btn btn-primary" style="margin-top:12px;">Save Assessments</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Findings -->
            <div class="detail-card">
                <h3>Findings (<?php echo count($findings); ?>)</h3>
                <?php if (!empty($findings)): ?>
                <table class="grc-table">
                    <thead><tr><th>Ref</th><th>Title</th><th>Severity</th><th>Status</th><th>Control / Requirement</th><?php if (!$readOnly): ?><th>Actions</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($findings as $f): ?>
                    <tr>
                        <td><?php echo e($f['finding_ref']); ?></td>
                        <td><?php echo e($f['title']); ?></td>
                        <td><span class="severity-<?php echo e($f['severity']); ?>"><?php echo e(ucfirst($f['severity'])); ?></span></td>
                        <td><span class="status-badge status-<?php echo e(str_replace(' ', '_', $f['status'])); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $f['status']))); ?></span></td>
                        <td><?php echo $f['control_ref'] ? e($f['control_ref']) : ($f['requirement_ref'] ? e($f['requirement_ref']) : '-'); ?></td>
                        <?php if (!$readOnly): ?>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="update_finding">
                                <input type="hidden" name="finding_id" value="<?php echo (int)$f['id']; ?>">
                                <input type="hidden" name="severity" value="<?php echo e($f['severity']); ?>">
                                <select name="finding_status" class="auto-submit" style="padding:3px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:11px;width:auto;">
                                    <?php foreach (['open', 'in_remediation', 'remediated', 'verified_closed', 'risk_accepted'] as $fs): ?>
                                    <option value="<?php echo e($fs); ?>" <?php echo $f['status'] === $fs ? 'selected' : ''; ?>><?php echo e(ucfirst(str_replace('_', ' ', $fs))); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="color:#6b7280;font-size:13px;">No findings recorded yet.</p>
                <?php endif; ?>

                <?php if (!$readOnly): ?>
                <details style="margin-top:16px;">
                    <summary style="cursor:pointer;font-size:13px;font-weight:500;color:#374151;">Add Finding</summary>
                    <form method="post" style="margin-top:12px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="add_finding">
                        <input type="hidden" name="audit_id" value="<?php echo (int)$auditDetail['id']; ?>">
                        <div class="form-row">
                            <div>
                                <label>Finding Title</label>
                                <input type="text" name="finding_title" required placeholder="Finding title">
                            </div>
                            <div>
                                <label>Severity</label>
                                <select name="severity">
                                    <option value="critical">Critical</option>
                                    <option value="high">High</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="low">Low</option>
                                    <option value="informational">Informational</option>
                                </select>
                            </div>
                        </div>
                        <label>Description</label>
                        <textarea name="finding_description" placeholder="Provide a detailed description of the finding, including the condition observed, the criteria or standard it was measured against, the cause of the deviation, and the effect or potential risk to the organization." style="min-height:200px;resize:vertical;"></textarea>
                        <div class="form-row">
                            <div>
                                <label>Related Control</label>
                                <select name="control_id">
                                    <option value="">-- None --</option>
                                    <?php foreach ($allControls as $ac): ?>
                                    <option value="<?php echo (int)$ac['id']; ?>"><?php echo e($ac['control_ref']); ?> - <?php echo e($ac['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Related Requirement</label>
                                <select name="requirement_id">
                                    <option value="">-- None --</option>
                                    <?php foreach ($findingRequirements as $afr): ?>
                                    <option value="<?php echo (int)$afr['id']; ?>"><?php echo e($afr['requirement_ref']); ?> - <?php echo e($afr['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <label>Recommendation</label>
                        <textarea name="recommendation" placeholder="Provide the recommended corrective action, including specific steps management should take to remediate the finding, the expected timeline, and any compensating controls to consider in the interim." style="min-height:200px;resize:vertical;"></textarea>
                        <button type="submit" class="btn btn-primary">Add Finding</button>
                    </form>
                </details>
                <?php endif; ?>
            </div>

            <!-- Remediation Plans -->
            <?php if (!empty($remediationPlans)): ?>
            <div class="detail-card">
                <h3>Remediation Plans</h3>
                <table class="grc-table">
                    <thead><tr><th>Title</th><th>Assigned To</th><th>Target Date</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($remediationPlans as $rp):
                        $isOverdue = !empty($rp['planned_completion']) && strtotime($rp['planned_completion']) < time() && $rp['status'] !== 'completed';
                    ?>
                    <tr>
                        <td><?php echo e($rp['title']); ?></td>
                        <td><?php echo e($rp['assigned_to_name'] ?? '-'); ?></td>
                        <td style="<?php echo $isOverdue ? 'color:#dc3545;font-weight:600;' : ''; ?>"><?php echo $rp['planned_completion'] ? e(date('M j, Y', strtotime($rp['planned_completion']))) : '-'; ?><?php if ($isOverdue): ?> (Overdue)<?php endif; ?></td>
                        <td><?php echo e(ucfirst(str_replace('_', ' ', $rp['status']))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Add Remediation (for open findings) -->
            <?php if (!$readOnly && !empty($findings)): ?>
            <?php $openFindings = array_filter($findings, function($f) { return in_array($f['status'], ['open', 'in_remediation']); }); ?>
            <?php if (!empty($openFindings)): ?>
            <div class="detail-card">
                <details>
                    <summary style="cursor:pointer;font-size:14px;font-weight:500;color:#333;">Add Remediation Plan</summary>
                    <form method="post" style="margin-top:12px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="add_remediation">
                        <div class="form-row">
                            <div>
                                <label>Finding</label>
                                <select name="finding_id" required>
                                    <?php foreach ($openFindings as $of): ?>
                                    <option value="<?php echo (int)$of['id']; ?>"><?php echo e($of['finding_ref']); ?> - <?php echo e($of['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Plan Title</label>
                                <input type="text" name="plan_title" required placeholder="Remediation plan title">
                            </div>
                        </div>
                        <label>Description</label>
                        <textarea name="plan_description" placeholder="Describe the remediation steps"></textarea>
                        <div class="form-row">
                            <div>
                                <label>Assigned To</label>
                                <select name="assigned_to">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($users_list as $u): ?>
                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo e($u['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Target Completion</label>
                                <input type="date" name="planned_completion">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Add Remediation Plan</button>
                    </form>
                </details>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Evidence Requests -->
            <div class="detail-card">
                <h3>Evidence Requests</h3>
                <?php if (!empty($evidenceRequests)): ?>
                <table class="grc-table">
                    <thead><tr><th>Description</th><th>Assigned To</th><th>Due Date</th><th>Status</th><th>Requested By</th></tr></thead>
                    <tbody>
                    <?php foreach ($evidenceRequests as $er): ?>
                    <tr>
                        <td><?php echo e($er['description']); ?></td>
                        <td><?php echo e($er['assigned_to_name'] ?? '-'); ?></td>
                        <td><?php echo $er['due_date'] ? e(date('M j, Y', strtotime($er['due_date']))) : '-'; ?></td>
                        <td><?php echo e(ucfirst($er['status'])); ?></td>
                        <td><?php echo e($er['requested_by_name'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="color:#6b7280;font-size:13px;">No evidence requests.</p>
                <?php endif; ?>

                <?php if (!$readOnly): ?>
                <details style="margin-top:12px;">
                    <summary style="cursor:pointer;font-size:13px;font-weight:500;color:#374151;">Request Evidence</summary>
                    <form method="post" style="margin-top:12px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="request_evidence">
                        <input type="hidden" name="audit_id" value="<?php echo (int)$auditDetail['id']; ?>">
                        <div class="form-row">
                            <div>
                                <label>Description <span style="color:#dc3545;">*</span></label>
                                <textarea name="request_description" required placeholder="What evidence is needed?"></textarea>
                            </div>
                            <div>
                                <label>Related Control</label>
                                <select name="control_id">
                                    <option value="">-- Select Control --</option>
                                    <?php foreach ($allControls as $ctrl): ?>
                                    <option value="<?php echo (int)$ctrl['id']; ?>"><?php echo e($ctrl['control_ref'] . ' - ' . $ctrl['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div>
                                <label>Assign To</label>
                                <select name="request_assigned_to">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($users_list as $u): ?>
                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo e($u['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Due Date</label>
                                <input type="date" name="request_due_date">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                    </form>
                </details>
                <?php endif; ?>
            </div>

            <?php elseif (($editAudit || isset($_GET['new'])) && !$readOnly): ?>
            <!-- Create/Edit Audit -->
            <a href="grc-audits.php" class="back-link">&larr; Back to audits</a>

            <div class="grc-form">
                <h3 style="margin:0 0 16px;font-size:16px;color:#333;"><?php echo $editAudit ? 'Edit Audit' : 'Create Audit'; ?></h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="<?php echo $editAudit ? 'update_audit' : 'create_audit'; ?>">
                    <?php if ($editAudit): ?>
                    <input type="hidden" name="audit_id" value="<?php echo (int)$editAudit['id']; ?>">
                    <?php endif; ?>

                    <label for="title">Audit Title</label>
                    <input type="text" id="title" name="title" value="<?php echo e($editAudit['title'] ?? ''); ?>" required>

                    <label for="description">Description
                        <?php if ($aiEnabled): ?>
                        <button type="button" class="btn-ai" data-action="ai-suggest-description" style="font-size:10px;padding:2px 8px;margin-left:8px;vertical-align:middle;">Suggest with AI</button>
                        <?php endif; ?>
                    </label>
                    <textarea id="description" name="description" style="min-height:100px;resize:vertical;"><?php echo e($editAudit['description'] ?? ''); ?></textarea>

                    <div class="form-row">
                        <div>
                            <label for="audit_type">Type</label>
                            <select id="audit_type" name="audit_type">
                                <?php foreach (['internal', 'external', 'certification', 'surveillance', 'readiness'] as $at): ?>
                                <option value="<?php echo e($at); ?>" <?php echo ($editAudit['audit_type'] ?? '') === $at ? 'selected' : ''; ?>><?php echo e(ucfirst($at)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="framework_id">Framework</label>
                            <select id="framework_id" name="framework_id">
                                <option value="">-- None --</option>
                                <?php foreach ($frameworks as $fw): ?>
                                <option value="<?php echo (int)$fw['id']; ?>" <?php echo ((int)($editAudit['framework_id'] ?? 0)) === (int)$fw['id'] ? 'selected' : ''; ?>><?php echo e($fw['code']); ?> - <?php echo e($fw['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div>
                            <label for="scope_id">Scope</label>
                            <select id="scope_id" name="scope_id">
                                <option value="">-- Select Scope --</option>
                                <?php foreach ($scopes as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)($editAudit['scope_id'] ?? 0)) === (int)$s['id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="lead_auditor_id">Lead Auditor</label>
                            <select id="lead_auditor_id" name="lead_auditor_id">
                                <option value="">-- Select --</option>
                                <?php foreach ($users_list as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>" <?php echo ((int)($editAudit['lead_auditor_user_id'] ?? 0)) === (int)$u['id'] ? 'selected' : ''; ?>><?php echo e($u['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if ($editAudit): ?>
                    <div>
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach (['planning', 'fieldwork', 'reporting', 'remediation', 'closed'] as $as): ?>
                            <option value="<?php echo e($as); ?>" <?php echo ($editAudit['status'] ?? '') === $as ? 'selected' : ''; ?>><?php echo e(ucfirst(str_replace('_', ' ', $as))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div>
                            <label for="planned_start_date">Planned Start</label>
                            <input type="date" id="planned_start_date" name="planned_start_date" value="<?php echo e($editAudit['planned_start'] ?? ''); ?>">
                        </div>
                        <div>
                            <label for="planned_end_date">Planned End</label>
                            <input type="date" id="planned_end_date" name="planned_end_date" value="<?php echo e($editAudit['planned_end'] ?? ''); ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo $editAudit ? 'Update Audit' : 'Create Audit'; ?></button>
                    <a href="grc-audits.php" class="btn btn-outline">Cancel</a>
                </form>
            </div>

            <?php if ($aiEnabled): ?>
            <script nonce="<?php echo cspNonce(); ?>">
            (function() {
                var csrfToken = <?php echo json_encode($csrfToken); ?>;
                document.addEventListener('click', function(e) {
                    var btn = e.target.closest('[data-action="ai-suggest-description"]');
                    if (!btn) return;
                    var titleEl = document.getElementById('title');
                    var typeEl = document.getElementById('audit_type');
                    var fwEl = document.getElementById('framework_id');
                    var descEl = document.getElementById('description');
                    var title = titleEl ? titleEl.value.trim() : '';
                    var auditType = typeEl ? typeEl.value : '';
                    var fwText = fwEl && fwEl.selectedIndex > 0 ? fwEl.options[fwEl.selectedIndex].text : '';
                    if (!title) { alert('Please enter an audit title first.'); return; }
                    btn.disabled = true;
                    btn.textContent = 'Generating...';

                    fetch('api/grc-refine-notes.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            notes: 'Generate a professional audit-friendly description for a ' + auditType + ' audit titled "' + title + '"' + (fwText ? ' covering the ' + fwText + ' framework' : '') + '. Write 2-3 sentences in formal language describing the audit scope, objectives, and methodology. Write in the affirmative present tense.',
                            requirement_ref: 'Audit Description',
                            requirement_title: title,
                            assessment_status: ''
                        })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.csrf_token) csrfToken = data.csrf_token;
                        btn.disabled = false;
                        btn.textContent = 'Suggest with AI';
                        if (data.error) { alert('AI error: ' + data.error); return; }
                        if (data.refined) {
                            descEl.value = data.refined;
                            descEl.style.borderColor = '#6366f1';
                            setTimeout(function() { descEl.style.borderColor = ''; }, 2000);
                        }
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        btn.textContent = 'Suggest with AI';
                        alert('Request failed: ' + err.message);
                    });
                });
            })();
            </script>
            <?php endif; ?>

            <?php else: ?>
            <!-- Audit List -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <form method="get" class="filter-bar">
                    <div>
                        <label>Status</label>
                        <select name="status" class="auto-submit">
                            <option value="">All Statuses</option>
                            <?php foreach (['planning', 'fieldwork', 'reporting', 'remediation', 'closed'] as $s): ?>
                            <option value="<?php echo e($s); ?>" <?php echo ($_GET['status'] ?? '') === $s ? 'selected' : ''; ?>><?php echo e(ucfirst(str_replace('_', ' ', $s))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Framework</label>
                        <select name="framework_id" class="auto-submit">
                            <option value="">All Frameworks</option>
                            <?php foreach ($frameworks as $fw): ?>
                            <option value="<?php echo (int)$fw['id']; ?>" <?php echo ((int)($_GET['framework_id'] ?? 0)) === (int)$fw['id'] ? 'selected' : ''; ?>><?php echo e($fw['code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-outline btn-sm" style="margin-bottom:0;">Filter</button>
                </form>
                <?php if (!$readOnly): ?>
                <a href="grc-audits.php?new=1" class="btn btn-primary">+ New Audit</a>
                <?php endif; ?>
            </div>

            <table class="grc-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Framework</th>
                        <th>Scope</th>
                        <th>Lead Auditor</th>
                        <th>Findings</th>
                        <th>Open</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audits)): ?>
                    <tr><td colspan="9" style="text-align:center;color:#6b7280;padding:30px;">No audits found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($audits as $a): ?>
                    <tr>
                        <td><a href="grc-audits.php?view=<?php echo (int)$a['id']; ?>" style="color:#3b82f6;text-decoration:none;font-weight:500;"><?php echo e($a['audit_ref']); ?></a></td>
                        <td><?php echo e($a['title']); ?></td>
                        <td><span class="status-badge status-<?php echo e($a['status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $a['status']))); ?></span></td>
                        <td><?php echo e(ucfirst($a['audit_type'] ?? '-')); ?></td>
                        <td><?php echo e($a['framework_code'] ?? '-'); ?></td>
                        <td><?php echo e($a['scope_name'] ?? '-'); ?></td>
                        <td><?php echo e($a['lead_auditor_name'] ?? '-'); ?></td>
                        <td style="text-align:center;"><?php echo (int)$a['finding_count']; ?></td>
                        <td style="text-align:center;<?php echo (int)$a['open_findings'] > 0 ? 'color:#dc3545;font-weight:600;' : ''; ?>"><?php echo (int)$a['open_findings']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>

        </main>
    </div>
    <!-- Footer -->
    <footer class="section footer-modern bg-gray-13">
        <div class="footer-modern-body">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                    <?php if (!empty($theme['footer_logo_url'])): ?>
                        <a class="brand" href="index.php">
                            <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="Footer Logo" style="max-height: 45px;">
                        </a>
                    <?php endif; ?>
                    <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                </div>
                <p class="rights" style="margin: 0;">
                    <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                    <span class="copyright-year"><?php echo date('Y'); ?></span>
                    <span>.&nbsp;</span>
                    <span>All Rights Reserved</span>
                </p>
            </div>
        </div>
    </footer>
</div>

<!-- Notes Modal -->
<div class="grc-modal-overlay" id="notesModal">
    <div class="grc-modal">
        <div class="grc-modal-header">
            <h4 id="notesModalTitle">Requirement Notes</h4>
            <button type="button" class="grc-modal-close" data-action="close-notes">&times;</button>
        </div>
        <div class="grc-modal-body">
            <div style="font-size:12px;color:#6b7280;margin-bottom:10px;" id="notesModalRef"></div>
            <textarea class="notes-textarea" id="notesTextarea" placeholder="Enter auditor notes for this requirement..."></textarea>
            <div class="refine-preview" id="refinePreview">
                <div class="refine-preview-label">AI-Refined Version (Preview)</div>
                <div id="refinePreviewText"></div>
                <div style="margin-top:10px;display:flex;gap:8px;">
                    <button type="button" class="btn btn-sm btn-primary" data-action="accept-refine">Accept</button>
                    <button type="button" class="btn btn-sm btn-outline" data-action="reject-refine">Reject</button>
                </div>
            </div>
            <!-- Linked Controls section -->
            <div class="evidence-section">
                <div class="evidence-section-label">Linked Controls</div>
                <ul class="evidence-list" id="controlList">
                    <li style="color:#9ca3af;border:none;background:none;padding:4px 0;font-style:italic;">No controls linked</li>
                </ul>
                <div class="evidence-upload-row" id="controlActionRow">
                    <select id="linkControlSelect" style="flex:1;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
                        <option value="">-- Link existing control --</option>
                    </select>
                    <button type="button" class="btn-upload" data-action="link-control">Link</button>
                    <button type="button" class="btn-upload" data-action="open-create-control" style="background:#f0fdf4;border-color:#22c55e;color:#15803d;">+ Create</button>
                </div>
            </div>
            <!-- Evidence section -->
            <div class="evidence-section">
                <div class="evidence-section-label">Evidence Attachments</div>
                <ul class="evidence-list" id="evidenceList">
                    <li style="color:#9ca3af;border:none;background:none;padding:4px 0;font-style:italic;">No evidence attached</li>
                </ul>
                <div class="evidence-upload-row" id="evidenceUploadRow">
                    <input type="file" id="evidenceFileInput" accept=".pdf,.png,.jpg,.jpeg,.gif,.doc,.docx,.xls,.xlsx,.csv,.json,.xml,.txt">
                    <button type="button" class="btn-upload" data-action="upload-evidence">Upload File</button>
                </div>
                <div class="evidence-upload-row" id="evidenceLinkRow" style="margin-top:6px;">
                    <input type="text" id="evidenceLinkUrl" placeholder="https://..." style="flex:1;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
                    <input type="text" id="evidenceLinkTitle" placeholder="Title (optional)" style="width:140px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
                    <button type="button" class="btn-upload" data-action="link-evidence-url">Add Link</button>
                </div>
            </div>
        </div>
        <div class="grc-modal-footer">
            <?php if ($aiEnabled): ?>
            <button type="button" class="btn-ai" id="refineBtn" data-action="refine-notes" title="Use AI to rewrite in auditor-friendly language">Refine with AI</button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline" data-action="open-create-finding" style="background:#fef3c7;border-color:#f59e0b;color:#92400e;" id="createFindingBtn">Create Finding</button>
            <button type="button" class="btn btn-primary" data-action="save-notes">Save</button>
            <button type="button" class="btn btn-outline" data-action="close-notes">Cancel</button>
        </div>
    </div>
</div>

<!-- Create Finding Modal (overlays on top of Notes modal) -->
<div class="grc-modal-overlay" id="createFindingModal" style="z-index:10001;">
    <div class="grc-modal" style="width:700px;">
        <div class="grc-modal-header">
            <h4 id="createFindingTitle">Create Finding</h4>
            <button type="button" class="grc-modal-close" data-action="close-create-finding">&times;</button>
        </div>
        <div class="grc-modal-body">
            <div style="font-size:12px;color:#6b7280;margin-bottom:10px;" id="createFindingContext"></div>
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Finding Title <span style="color:#dc3545;">*</span></label>
                <input type="text" id="cfTitle" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" placeholder="e.g., Missing Access Review Documentation">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Severity</label>
                    <select id="cfSeverity" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="medium" selected>Medium</option>
                        <option value="low">Low</option>
                        <option value="informational">Informational</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Finding Type</label>
                    <select id="cfType" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="nonconformity" selected>Nonconformity</option>
                        <option value="observation">Observation</option>
                        <option value="opportunity">Opportunity for Improvement</option>
                        <option value="strength">Strength</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Description</label>
                <textarea id="cfDescription" style="width:100%;min-height:150px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical;" placeholder="Describe the condition observed, the criteria or standard it was measured against, the cause of the deviation, and the effect or potential risk to the organization."></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Related Control</label>
                    <select id="cfControlId" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="">-- None --</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Due Date</label>
                    <input type="date" id="cfDueDate" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                </div>
            </div>
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Recommendation</label>
                <textarea id="cfRecommendation" style="width:100%;min-height:150px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical;" placeholder="Provide the recommended corrective action, including specific steps management should take to remediate the finding."></textarea>
            </div>
        </div>
        <div class="grc-modal-footer">
            <button type="button" class="btn btn-primary" data-action="save-create-finding" id="cfSaveBtn">Create Finding</button>
            <button type="button" class="btn btn-outline" data-action="close-create-finding">Cancel</button>
        </div>
    </div>
</div>

<!-- Requirement Detail Modal -->
<div class="grc-modal-overlay" id="reqDetailModal">
    <div class="grc-modal">
        <div class="grc-modal-header">
            <h4 id="reqDetailTitle">Requirement Detail</h4>
            <button type="button" class="grc-modal-close" data-action="close-req-detail">&times;</button>
        </div>
        <div class="grc-modal-body">
            <div style="margin-bottom:12px;">
                <span style="font-weight:600;color:#1e40af;font-size:13px;" id="reqDetailRef"></span>
                <span style="color:#374151;font-size:13px;" id="reqDetailName"></span>
            </div>
            <div style="font-size:13px;color:#6b7280;margin-bottom:14px;padding:10px;background:#f9fafb;border-radius:6px;" id="reqDetailDesc"></div>
            <div class="req-detail-content" id="reqDetailContent">
                <div style="text-align:center;padding:30px;color:#6b7280;">
                    <span class="spinner-sm" style="border-color:#6b7280;border-top-color:transparent;"></span> Loading requirement details...
                </div>
            </div>
        </div>
        <div class="grc-modal-footer">
            <button type="button" class="btn btn-outline" data-action="close-req-detail">Close</button>
        </div>
    </div>
</div>

<!-- Create Control Modal (overlays on top of Notes modal) -->
<div class="grc-modal-overlay" id="createControlModal" style="z-index:10001;">
    <div class="grc-modal" style="width:600px;">
        <div class="grc-modal-header">
            <h4 id="createControlTitle">Create Control</h4>
            <button type="button" class="grc-modal-close" data-action="close-create-control">&times;</button>
        </div>
        <div class="grc-modal-body">
            <div style="font-size:12px;color:#6b7280;margin-bottom:6px;" id="createControlContext"></div>
            <div id="ccAiStatus" style="font-size:11px;color:#6366f1;margin-bottom:10px;display:none;"><span class="spinner-sm" style="border-color:#6366f1;border-top-color:transparent;"></span> AI is generating control suggestions...</div>
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Suggested Title</label>
                <input type="text" id="ccTitle" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" placeholder="Control title">
            </div>
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Suggested Description</label>
                <textarea id="ccDescription" style="width:100%;min-height:120px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical;" placeholder="Describe the control"></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Type</label>
                    <select id="ccType" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="preventive">Preventive</option>
                        <option value="detective">Detective</option>
                        <option value="corrective">Corrective</option>
                        <option value="directive">Directive</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Category</label>
                    <select id="ccCategory" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="technical">Technical</option>
                        <option value="administrative">Administrative</option>
                        <option value="physical">Physical</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Status</label>
                    <select id="ccStatus" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="planned">Planned</option>
                        <option value="in_progress">In Progress</option>
                        <option value="implemented">Implemented</option>
                        <option value="not_applicable">Not Applicable</option>
                    </select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Frequency</label>
                    <select id="ccFrequency" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="continuous">Continuous</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="annually">Annually</option>
                        <option value="ad_hoc" selected>Ad Hoc</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Risk Level</label>
                    <select id="ccRiskLevel" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:3px;">Notes (optional)</label>
                <textarea id="ccNotes" style="width:100%;min-height:50px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical;" placeholder="Additional notes"></textarea>
            </div>
        </div>
        <div class="grc-modal-footer">
            <button type="button" class="btn btn-primary" data-action="save-create-control" id="ccSaveBtn">Create &amp; Link</button>
            <button type="button" class="btn btn-outline" data-action="close-create-control">Cancel</button>
        </div>
    </div>
</div>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var _notesReqId = 0;
    var _notesReqRef = '';
    var _notesReqTitle = '';
    var _auditId = <?php echo json_encode($viewId ?? 0); ?>;
    var _frameworkCode = <?php echo json_encode($auditDetail['framework_code'] ?? ''); ?>;
    var _frameworkName = <?php echo json_encode($auditDetail['framework_name'] ?? ''); ?>;
    var _readOnly = <?php echo json_encode((bool)($readOnly ?? false)); ?>;
    var _csrfToken = <?php echo json_encode($csrfToken ?? ''); ?>;
    var _reqDetailCache = {};
    var _aiEnabled = <?php echo json_encode((bool)($aiEnabled ?? false)); ?>;

    // --- Example notes generator ---
    function generateExampleNotes(reqRef, reqTitle) {
        var examples = {
            'CC': 'Reviewed organizational controls for ' + reqTitle + '. Verified that management has established policies and procedures to support this requirement. Inspected documentation and interviewed key personnel to confirm operational effectiveness. [Describe specific evidence reviewed and any gaps identified.]',
            'A1': 'Evaluated availability controls for ' + reqTitle + '. Confirmed that systems and infrastructure are designed to meet availability commitments. Reviewed uptime metrics, failover procedures, and disaster recovery testing results. [Describe specific findings.]',
            'C1': 'Assessed confidentiality controls for ' + reqTitle + '. Verified that data classification policies are in place and enforced. Inspected access controls, encryption configurations, and data handling procedures. [Describe specific evidence.]',
            'PI': 'Reviewed processing integrity controls for ' + reqTitle + '. Confirmed that data processing is complete, valid, accurate, and timely. Inspected input validation, error handling, and reconciliation procedures. [Describe specific findings.]',
            'P1': 'Evaluated privacy controls for ' + reqTitle + '. Verified that personal information is collected, used, retained, and disclosed in conformity with privacy commitments. Reviewed consent mechanisms and data subject request procedures. [Describe specific evidence.]',
        };
        // Match prefix from requirement ref
        var prefix = reqRef.replace(/[^A-Za-z]/g, '').substring(0, 2).toUpperCase();
        if (examples[prefix]) return examples[prefix];
        // Generic fallback
        return 'Assessed requirement ' + reqRef + ' - ' + reqTitle + '. Reviewed applicable policies, procedures, and supporting documentation. Evaluated the design and operating effectiveness of controls. [Describe the specific evidence reviewed, testing performed, personnel interviewed, and any observations or gaps identified.]';
    }

    // --- Notes Modal ---
    function openNotesModal(reqId, reqRef, reqTitle) {
        _notesReqId = reqId;
        _notesReqRef = reqRef;
        _notesReqTitle = reqTitle;
        var hidden = document.getElementById('notes-val-' + reqId);
        var val = hidden ? hidden.value : '';
        document.getElementById('notesModalTitle').textContent = reqRef + ' - Notes';
        document.getElementById('notesModalRef').textContent = reqTitle;
        var ta = document.getElementById('notesTextarea');
        // If no notes yet, pre-populate with example text
        if (!val.trim()) {
            ta.value = generateExampleNotes(reqRef, reqTitle);
        } else {
            ta.value = val;
        }
        ta.readOnly = _readOnly;
        document.getElementById('refinePreview').style.display = 'none';
        var refineBtn = document.getElementById('refineBtn');
        if (refineBtn) refineBtn.style.display = _readOnly ? 'none' : '';
        document.querySelector('[data-action="save-notes"]').style.display = _readOnly ? 'none' : '';
        document.getElementById('createFindingBtn').style.display = _readOnly ? 'none' : '';
        document.getElementById('evidenceUploadRow').style.display = _readOnly ? 'none' : '';
        document.getElementById('evidenceLinkRow').style.display = _readOnly ? 'none' : '';
        document.getElementById('evidenceFileInput').value = '';
        document.getElementById('evidenceLinkUrl').value = '';
        document.getElementById('evidenceLinkTitle').value = '';
        document.getElementById('notesModal').classList.add('active');
        if (!_readOnly) ta.focus();
        document.getElementById('controlActionRow').style.display = _readOnly ? 'none' : '';
        // Load controls and evidence for this requirement
        loadControls(reqId);
        loadEvidence(reqId);
    }

    function closeNotesModal() {
        document.getElementById('notesModal').classList.remove('active');
        _notesReqId = 0;
    }

    function saveNotes() {
        var val = document.getElementById('notesTextarea').value;
        var hidden = document.getElementById('notes-val-' + _notesReqId);
        if (hidden) hidden.value = val;
        var btn = hidden ? hidden.nextElementSibling : null;
        if (btn && btn.classList.contains('btn-notes')) {
            if (val.trim()) {
                btn.textContent = 'Edit Notes';
                btn.classList.add('has-notes');
                btn.title = val;
            } else {
                btn.textContent = 'Add Notes';
                btn.classList.remove('has-notes');
                btn.title = 'Add notes';
            }
        }
        closeNotesModal();
    }

    function refineNotes() {
        var notes = document.getElementById('notesTextarea').value.trim();
        if (!notes) { alert('Please enter notes first before refining.'); return; }
        var btn = document.getElementById('refineBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-sm"></span> Refining...';
        var statusSelect = document.querySelector('select[name="assessments[' + _notesReqId + ']"]');
        var assessmentStatus = statusSelect ? statusSelect.value : '';
        fetch('api/grc-refine-notes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: _csrfToken, notes: notes, requirement_ref: _notesReqRef, requirement_title: _notesReqTitle, assessment_status: assessmentStatus })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            btn.disabled = false;
            btn.innerHTML = 'Refine with AI';
            if (data.error) { alert('Error: ' + data.error); return; }
            document.getElementById('refinePreviewText').textContent = data.refined;
            document.getElementById('refinePreview').style.display = 'block';
        })
        .catch(function(err) { btn.disabled = false; btn.innerHTML = 'Refine with AI'; alert('Request failed: ' + err.message); });
    }

    // --- Evidence Functions ---
    function renderEvidenceList(items) {
        var list = document.getElementById('evidenceList');
        if (!items || items.length === 0) {
            list.innerHTML = '<li style="color:#9ca3af;border:none;background:none;padding:4px 0;font-style:italic;">No evidence attached</li>';
            return;
        }
        var html = '';
        for (var i = 0; i < items.length; i++) {
            var ev = items[i];
            var isLink = !!ev.external_url;
            var icon = isLink ? '&#128279;' : (ev.evidence_type === 'screenshot' ? '&#128247;' : '&#128196;');
            var size = ev.file_size ? ' (' + formatFileSize(ev.file_size) + ')' : '';
            var meta = isLink ? ev.external_url : (ev.file_name || ev.evidence_type);
            html += '<li>';
            html += '<span class="ev-icon">' + icon + '</span>';
            html += '<span class="ev-info">';
            if (isLink) {
                html += '<a href="' + escapeHtml(ev.external_url) + '" target="_blank" rel="noopener" style="color:#3b82f6;font-weight:500;font-size:12px;">' + escapeHtml(ev.evidence_ref + ' - ' + ev.title) + '</a><br>';
            } else {
                html += '<span class="ev-title">' + escapeHtml(ev.evidence_ref + ' - ' + ev.title) + '</span><br>';
            }
            html += '<span class="ev-meta">' + escapeHtml(meta) + size + '</span></span>';
            if (!_readOnly) {
                html += '<button type="button" class="ev-remove" data-action="unlink-evidence" data-evidence-id="' + ev.id + '" title="Remove link">&times;</button>';
            }
            html += '</li>';
        }
        list.innerHTML = html;
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function loadEvidence(reqId) {
        if (!_auditId) { renderEvidenceList([]); return; }
        fetch('api/grc-assessment-evidence.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: _csrfToken, action: 'list', audit_id: _auditId, requirement_id: reqId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            renderEvidenceList(data.evidence || []);
        })
        .catch(function() { renderEvidenceList([]); });
    }

    function uploadEvidence() {
        var fileInput = document.getElementById('evidenceFileInput');
        if (!fileInput.files || !fileInput.files[0]) { alert('Please select a file to upload.'); return; }
        var btn = document.querySelector('[data-action="upload-evidence"]');
        btn.disabled = true;
        btn.textContent = 'Uploading...';
        var fd = new FormData();
        fd.append('evidence_file', fileInput.files[0]);
        fd.append('csrf_token', _csrfToken);
        fd.append('action', 'upload');
        fd.append('audit_id', _auditId);
        fd.append('requirement_id', _notesReqId);
        fetch('api/grc-assessment-evidence.php', {
            method: 'POST',
            body: fd
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            btn.disabled = false;
            btn.textContent = 'Upload';
            if (data.error) { alert('Error: ' + data.error); return; }
            fileInput.value = '';
            renderEvidenceList(data.evidence || []);
        })
        .catch(function(err) { btn.disabled = false; btn.textContent = 'Upload'; alert('Upload failed: ' + err.message); });
    }

    function unlinkEvidence(evidenceId) {
        fetch('api/grc-assessment-evidence.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: _csrfToken, action: 'unlink', audit_id: _auditId, requirement_id: _notesReqId, evidence_id: evidenceId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            renderEvidenceList(data.evidence || []);
        })
        .catch(function(err) { alert('Failed to remove: ' + err.message); });
    }

    function linkEvidenceUrl() {
        var url = document.getElementById('evidenceLinkUrl').value.trim();
        if (!url) { alert('Please enter a URL.'); return; }
        if (!/^https?:\/\//i.test(url)) { alert('URL must start with http:// or https://'); return; }
        var title = document.getElementById('evidenceLinkTitle').value.trim();
        var btn = document.querySelector('[data-action="link-evidence-url"]');
        btn.disabled = true;
        btn.textContent = 'Adding...';
        fetch('api/grc-assessment-evidence.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: _csrfToken,
                action: 'add_link',
                audit_id: _auditId,
                requirement_id: _notesReqId,
                external_url: url,
                evidence_title: title
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            btn.disabled = false;
            btn.textContent = 'Add Link';
            if (data.error) { alert('Error: ' + data.error); return; }
            document.getElementById('evidenceLinkUrl').value = '';
            document.getElementById('evidenceLinkTitle').value = '';
            renderEvidenceList(data.evidence || []);
        })
        .catch(function(err) { btn.disabled = false; btn.textContent = 'Add Link'; alert('Failed: ' + err.message); });
    }

    // --- Control Functions ---
    function renderControlList(linked, available) {
        var list = document.getElementById('controlList');
        if (!linked || linked.length === 0) {
            list.innerHTML = '<li style="color:#9ca3af;border:none;background:none;padding:4px 0;font-style:italic;">No controls linked</li>';
        } else {
            var html = '';
            for (var i = 0; i < linked.length; i++) {
                var c = linked[i];
                var statusLabel = c.implementation_status.replace(/_/g, ' ');
                statusLabel = statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1);
                var typeLabel = c.control_type.charAt(0).toUpperCase() + c.control_type.slice(1);
                var catLabel = c.control_category.charAt(0).toUpperCase() + c.control_category.slice(1);
                html += '<li>';
                html += '<span class="ev-icon" style="font-size:13px;">&#9881;</span>';
                html += '<span class="ev-info"><span class="ev-title">' + escapeHtml(c.control_ref + ' - ' + c.title) + '</span><br>';
                html += '<span class="ev-meta">' + escapeHtml(typeLabel + ' / ' + catLabel + ' / ' + statusLabel) + '</span></span>';
                if (!_readOnly) {
                    html += '<button type="button" class="ev-remove" data-action="unlink-control" data-control-id="' + c.id + '" title="Unlink control">&times;</button>';
                }
                html += '</li>';
            }
            list.innerHTML = html;
        }
        // Update the link dropdown
        var sel = document.getElementById('linkControlSelect');
        sel.innerHTML = '<option value="">-- Link existing control --</option>';
        if (available) {
            for (var j = 0; j < available.length; j++) {
                var a = available[j];
                var opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.control_ref + ' - ' + a.title;
                sel.appendChild(opt);
            }
        }
    }

    function loadControls(reqId) {
        fetch('api/grc-assessment-control.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: _csrfToken, action: 'list', requirement_id: reqId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            renderControlList(data.linked || [], data.available || []);
        })
        .catch(function() { renderControlList([], []); });
    }

    function linkExistingControl() {
        var sel = document.getElementById('linkControlSelect');
        var controlId = parseInt(sel.value);
        if (!controlId) { alert('Please select a control to link.'); return; }
        fetch('api/grc-assessment-control.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: _csrfToken, action: 'link', requirement_id: _notesReqId, control_id: controlId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            if (data.error) { alert('Error: ' + data.error); return; }
            renderControlList(data.linked || [], data.available || []);
        })
        .catch(function(err) { alert('Failed to link: ' + err.message); });
    }

    function unlinkControl(controlId) {
        fetch('api/grc-assessment-control.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: _csrfToken, action: 'unlink', requirement_id: _notesReqId, control_id: controlId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            renderControlList(data.linked || [], data.available || []);
        })
        .catch(function(err) { alert('Failed to unlink: ' + err.message); });
    }

    function openCreateControlModal() {
        var ctx = document.getElementById('createControlContext');
        ctx.textContent = 'For requirement: ' + _notesReqRef + ' - ' + _notesReqTitle;
        // Set non-AI defaults immediately (button always enabled)
        var fallbackTitle = _notesReqTitle;
        var fallbackDesc = 'This control addresses ' + _notesReqRef + ' (' + _notesReqTitle + '). [Describe the specific control activities, responsible parties, and how effectiveness is measured.]';
        document.getElementById('ccTitle').value = fallbackTitle;
        document.getElementById('ccDescription').value = fallbackDesc;
        document.getElementById('ccType').value = 'preventive';
        document.getElementById('ccCategory').value = 'technical';
        document.getElementById('ccStatus').value = 'planned';
        document.getElementById('ccFrequency').value = 'ad_hoc';
        document.getElementById('ccRiskLevel').value = 'medium';
        document.getElementById('ccNotes').value = '';
        document.getElementById('ccSaveBtn').disabled = false;
        document.getElementById('createControlModal').classList.add('active');

        if (_aiEnabled) {
            // Show AI loading indicator and fetch suggestions
            document.getElementById('ccAiStatus').style.display = 'block';
            fetch('api/grc-suggest-control.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: _csrfToken,
                    requirement_id: _notesReqId,
                    requirement_ref: _notesReqRef,
                    requirement_title: _notesReqTitle,
                    framework_code: _frameworkCode,
                    framework_name: _frameworkName
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.csrf_token) _csrfToken = data.csrf_token;
                document.getElementById('ccAiStatus').style.display = 'none';
                if (data.error) return; // Keep non-AI defaults
                var s = data.suggestion;
                document.getElementById('ccTitle').value = s.title || fallbackTitle;
                document.getElementById('ccDescription').value = s.description || fallbackDesc;
                if (s.control_type) document.getElementById('ccType').value = s.control_type;
                if (s.control_category) document.getElementById('ccCategory').value = s.control_category;
                if (s.implementation_status) document.getElementById('ccStatus').value = s.implementation_status;
                if (s.frequency) document.getElementById('ccFrequency').value = s.frequency;
                if (s.risk_level) document.getElementById('ccRiskLevel').value = s.risk_level;
            })
            .catch(function() {
                document.getElementById('ccAiStatus').style.display = 'none';
            });
        } else {
            document.getElementById('ccAiStatus').style.display = 'none';
        }

        document.getElementById('ccTitle').focus();
    }

    function closeCreateControlModal() {
        document.getElementById('createControlModal').classList.remove('active');
    }

    function saveCreateControl() {
        var title = document.getElementById('ccTitle').value.trim();
        if (!title) { alert('Title is required.'); return; }
        var btn = document.getElementById('ccSaveBtn');
        btn.disabled = true;
        btn.textContent = 'Creating...';
        fetch('api/grc-assessment-control.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: _csrfToken,
                action: 'create',
                requirement_id: _notesReqId,
                title: title,
                description: document.getElementById('ccDescription').value.trim(),
                control_type: document.getElementById('ccType').value,
                control_category: document.getElementById('ccCategory').value,
                implementation_status: document.getElementById('ccStatus').value,
                frequency: document.getElementById('ccFrequency').value,
                risk_level: document.getElementById('ccRiskLevel').value,
                notes: document.getElementById('ccNotes').value.trim()
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            btn.disabled = false;
            btn.textContent = 'Create & Link';
            if (data.error) { alert('Error: ' + data.error); return; }
            renderControlList(data.linked || [], data.available || []);
            closeCreateControlModal();
        })
        .catch(function(err) { btn.disabled = false; btn.textContent = 'Create & Link'; alert('Failed: ' + err.message); });
    }

    // --- Requirement Detail Modal ---
    function openRequirementDetail(reqId, reqRef, reqTitle, reqDesc) {
        document.getElementById('reqDetailTitle').textContent = reqRef + ' - Detail';
        document.getElementById('reqDetailRef').textContent = reqRef;
        document.getElementById('reqDetailName').textContent = ' - ' + reqTitle;
        document.getElementById('reqDetailDesc').textContent = reqDesc || 'No description available.';
        document.getElementById('reqDetailModal').classList.add('active');
        if (_reqDetailCache[reqId]) {
            document.getElementById('reqDetailContent').innerHTML = _reqDetailCache[reqId];
            return;
        }
        if (!_aiEnabled) {
            // No AI — show requirement description only
            var noAiHtml = reqDesc ? '<p style="color:#374151;font-size:13px;">' + escapeHtml(reqDesc) + '</p>' : '<p style="color:#6b7280;font-style:italic;">No additional detail available. Enable AI for enhanced requirement analysis.</p>';
            _reqDetailCache[reqId] = noAiHtml;
            document.getElementById('reqDetailContent').innerHTML = noAiHtml;
            return;
        }
        document.getElementById('reqDetailContent').innerHTML = '<div style="text-align:center;padding:30px;color:#6b7280;"><span class="spinner-sm" style="border-color:#6b7280;border-top-color:transparent;"></span> Generating requirement details...</div>';
        fetch('api/grc-requirement-detail.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: _csrfToken, requirement_id: reqId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            if (data.error) { document.getElementById('reqDetailContent').innerHTML = '<p style="color:#6b7280;font-style:italic;">' + escapeHtml(data.error) + '</p>'; return; }
            _reqDetailCache[reqId] = data.content;
            document.getElementById('reqDetailContent').innerHTML = data.content;
        })
        .catch(function(err) { document.getElementById('reqDetailContent').innerHTML = '<p style="color:#dc3545;">Request failed: ' + escapeHtml(err.message) + '</p>'; });
    }

    function closeReqDetailModal() {
        document.getElementById('reqDetailModal').classList.remove('active');
    }

    // --- Create Finding Functions ---
    function openCreateFindingModal() {
        var ctx = document.getElementById('createFindingContext');
        ctx.textContent = 'Audit finding for: ' + _notesReqRef + ' - ' + _notesReqTitle;
        document.getElementById('cfTitle').value = '';
        document.getElementById('cfDescription').value = '';
        document.getElementById('cfRecommendation').value = '';
        document.getElementById('cfSeverity').value = 'medium';
        document.getElementById('cfType').value = 'nonconformity';
        document.getElementById('cfDueDate').value = '';

        // Populate related controls from the linked controls in Notes modal
        var controlSelect = document.getElementById('cfControlId');
        controlSelect.innerHTML = '<option value="">-- None --</option>';
        var controlItems = document.querySelectorAll('#controlList li [data-action="unlink-control"]');
        controlItems.forEach(function(item) {
            var li = item.closest('li');
            var titleEl = li.querySelector('.ev-title');
            if (titleEl) {
                var opt = document.createElement('option');
                opt.value = item.getAttribute('data-control-id');
                opt.textContent = titleEl.textContent;
                controlSelect.appendChild(opt);
            }
        });

        document.getElementById('createFindingModal').classList.add('active');
        document.getElementById('cfTitle').focus();
    }

    function closeCreateFindingModal() {
        document.getElementById('createFindingModal').classList.remove('active');
    }

    function saveCreateFinding() {
        var title = document.getElementById('cfTitle').value.trim();
        if (!title) { alert('Finding title is required.'); return; }
        var btn = document.getElementById('cfSaveBtn');
        btn.disabled = true;
        btn.textContent = 'Creating...';
        fetch('api/grc-audit-finding.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: _csrfToken,
                action: 'create',
                audit_id: _auditId,
                title: title,
                description: document.getElementById('cfDescription').value.trim(),
                severity: document.getElementById('cfSeverity').value,
                finding_type: document.getElementById('cfType').value,
                control_id: document.getElementById('cfControlId').value || null,
                requirement_id: _notesReqId,
                due_date: document.getElementById('cfDueDate').value || null,
                recommendation: document.getElementById('cfRecommendation').value.trim()
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            btn.disabled = false;
            btn.textContent = 'Create Finding';
            if (data.error) { alert('Error: ' + data.error); return; }
            alert('Finding ' + data.finding_ref + ' created successfully.');
            closeCreateFindingModal();
        })
        .catch(function(err) { btn.disabled = false; btn.textContent = 'Create Finding'; alert('Failed: ' + err.message); });
    }

    // --- Event delegation for all data-action buttons ---
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) {
            // Close export dropdown when clicking outside
            if (!e.target.closest('.export-dropdown')) {
                document.querySelectorAll('.export-menu.show').forEach(function(m) { m.classList.remove('show'); });
            }
            return;
        }
        var action = btn.getAttribute('data-action');
        switch (action) {
            case 'open-notes':
                openNotesModal(parseInt(btn.getAttribute('data-req-id')), btn.getAttribute('data-req-ref'), btn.getAttribute('data-req-title'));
                break;
            case 'close-notes':
                closeNotesModal();
                break;
            case 'save-notes':
                saveNotes();
                break;
            case 'refine-notes':
                refineNotes();
                break;
            case 'accept-refine':
                document.getElementById('notesTextarea').value = document.getElementById('refinePreviewText').textContent;
                document.getElementById('refinePreview').style.display = 'none';
                break;
            case 'reject-refine':
                document.getElementById('refinePreview').style.display = 'none';
                break;
            case 'open-req-detail':
                openRequirementDetail(parseInt(btn.getAttribute('data-req-id')), btn.getAttribute('data-req-ref'), btn.getAttribute('data-req-title'), btn.getAttribute('data-req-desc'));
                break;
            case 'close-req-detail':
                closeReqDetailModal();
                break;
            case 'toggle-export':
                btn.nextElementSibling.classList.toggle('show');
                break;
            case 'upload-evidence':
                uploadEvidence();
                break;
            case 'link-evidence-url':
                linkEvidenceUrl();
                break;
            case 'unlink-evidence':
                unlinkEvidence(parseInt(btn.getAttribute('data-evidence-id')));
                break;
            case 'link-control':
                linkExistingControl();
                break;
            case 'unlink-control':
                unlinkControl(parseInt(btn.getAttribute('data-control-id')));
                break;
            case 'open-create-control':
                openCreateControlModal();
                break;
            case 'close-create-control':
                closeCreateControlModal();
                break;
            case 'save-create-control':
                saveCreateControl();
                break;
            case 'open-create-finding':
                openCreateFindingModal();
                break;
            case 'close-create-finding':
                closeCreateFindingModal();
                break;
            case 'save-create-finding':
                saveCreateFinding();
                break;
        }
    });

    // Auto-submit selects (CSP-safe replacement for inline onchange)
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('auto-submit')) {
            var form = e.target.closest('form');
            if (form) form.submit();
        }
    });

    // Form confirm dialogs (CSP-safe replacement for inline onsubmit)
    document.addEventListener('submit', function(e) {
        var form = e.target.closest('form[data-confirm]');
        if (form) {
            if (!confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        }
    });
})();
</script>
<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
