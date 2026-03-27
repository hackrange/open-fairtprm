<?php
/**
 * API: Generate a professional audit report (SOC 2 Type II, etc.)
 *
 * Generates report sections based on audit data, framework requirements,
 * controls, and assessment results. Uses AI when available, with non-AI
 * fallback templates.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$auth = Auth::getInstance();
$user = $auth->getUser();
$security = Security::getInstance();
$db = Database::getInstance();
$session = Session::getInstance();
$grc = GRCService::getInstance();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberGRC && !$isAuditor) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['csrf_token']) || !$security->validateCSRFToken($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

$newCsrfToken = $security->getCSRFToken();
$auditId = (int)($input['audit_id'] ?? 0);

if ($auditId <= 0) {
    echo json_encode(['error' => 'Audit ID is required.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Load full audit data
$audit = $db->fetchOne(
    'SELECT a.*, f.code as framework_code, f.name as framework_name, f.version as framework_version,
            f.description as framework_description, u.full_name as lead_auditor_name,
            gs.name as scope_name
     FROM grc_audits a
     LEFT JOIN grc_frameworks f ON f.id = a.framework_id
     LEFT JOIN users u ON u.id = a.lead_auditor_user_id
     LEFT JOIN grc_scopes gs ON gs.id = a.scope_id
     WHERE a.id = :id',
    [':id' => $auditId]
);

if (!$audit) {
    echo json_encode(['error' => 'Audit not found.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Load requirements with assessments
$requirements = $db->fetchAll(
    'SELECT fr.requirement_ref, fr.title, fr.description,
            ara.assessment_status, ara.notes as auditor_notes
     FROM grc_framework_requirements fr
     LEFT JOIN grc_audit_requirement_assessments ara ON ara.requirement_id = fr.id AND ara.audit_id = :aid
     WHERE fr.framework_id = :fid
     ORDER BY fr.sort_order, fr.requirement_ref',
    [':aid' => $auditId, ':fid' => $audit['framework_id']]
);

// Derive category from requirement_ref prefix (e.g., CC1.1 → Security, A1.1 → Availability)
$refCategoryMap = [
    'CC' => 'Security (Common Criteria)',
    'A'  => 'Availability',
    'C'  => 'Confidentiality',
    'PI' => 'Processing Integrity',
    'P'  => 'Privacy',
];
foreach ($requirements as &$r) {
    $prefix = preg_replace('/[^A-Za-z]/', '', $r['requirement_ref']);
    $prefix = strtoupper($prefix);
    $r['category'] = 'General';
    // Match longest prefix first
    foreach (['CC', 'PI', 'A', 'C', 'P'] as $p) {
        if (strpos($prefix, $p) === 0) {
            $r['category'] = $refCategoryMap[$p];
            break;
        }
    }
}
unset($r);

// Load controls linked to this audit's framework requirements
$controls = $db->fetchAll(
    'SELECT DISTINCT ic.control_ref, ic.title, ic.description, ic.control_type,
            ic.control_category, ic.implementation_status, ic.frequency
     FROM grc_internal_controls ic
     JOIN grc_control_requirement_map crm ON crm.control_id = ic.id
     JOIN grc_framework_requirements fr ON fr.id = crm.requirement_id
     WHERE fr.framework_id = :fid AND ic.is_active = 1
     ORDER BY ic.control_ref',
    [':fid' => $audit['framework_id']]
);

// Load findings
$findings = $db->fetchAll(
    'SELECT f.finding_ref, f.title, f.description, f.severity, f.finding_type,
            f.status, f.remediation_plan
     FROM grc_audit_findings f
     WHERE f.audit_id = :aid
     ORDER BY f.finding_ref',
    [':aid' => $auditId]
);

// Build report context
$orgRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'company_name'");
$orgName = ($orgRow && !empty($orgRow['config_value'])) ? $orgRow['config_value'] : '[Organization Name]';
$auditPeriod = '';
if ($audit['planned_start'] && $audit['planned_end']) {
    $auditPeriod = date('F j, Y', strtotime($audit['planned_start'])) . ' to ' . date('F j, Y', strtotime($audit['planned_end']));
} else {
    $auditPeriod = '[Audit Period]';
}

$frameworkName = $audit['framework_name'] ?? $audit['framework_code'];
$isSOC2 = stripos($audit['framework_code'], 'SOC2') !== false || stripos($audit['framework_name'] ?? '', 'SOC 2') !== false;

// Summarize assessment stats
$totalReqs = count($requirements);
$assessedReqs = 0;
$compliantReqs = 0;
$nonCompliantReqs = 0;
$categorySummary = [];
foreach ($requirements as $r) {
    $cat = $r['category'] ?? 'General';
    if (!isset($categorySummary[$cat])) $categorySummary[$cat] = ['total' => 0, 'compliant' => 0, 'assessed' => 0];
    $categorySummary[$cat]['total']++;
    if (!empty($r['assessment_status']) && $r['assessment_status'] !== 'not_assessed') {
        $assessedReqs++;
        $categorySummary[$cat]['assessed']++;
        if (in_array($r['assessment_status'], ['conforming'])) {
            $compliantReqs++;
            $categorySummary[$cat]['compliant']++;
        }
        if (in_array($r['assessment_status'], ['non_conforming'])) {
            $nonCompliantReqs++;
        }
    }
}

$complianceRate = $assessedReqs > 0 ? round(($compliantReqs / $assessedReqs) * 100, 1) : 0;

// Try AI-enhanced report
require_once __DIR__ . '/../includes/classes/AIPlatformService.php';
$ai = AIPlatformService::getInstance();
$useAI = $ai->isEnabled();

if ($isSOC2) {
    $report = generateSOC2Report($audit, $requirements, $controls, $findings, $categorySummary, $orgName, $auditPeriod, $frameworkName, $complianceRate, $assessedReqs, $totalReqs, $compliantReqs, $nonCompliantReqs, $ai, $useAI);
} else {
    $report = generateGenericReport($audit, $requirements, $controls, $findings, $categorySummary, $orgName, $auditPeriod, $frameworkName, $complianceRate, $assessedReqs, $totalReqs, $compliantReqs, $nonCompliantReqs, $ai, $useAI);
}

echo json_encode([
    'success' => true,
    'report' => $report,
    'framework_code' => $audit['framework_code'],
    'framework_name' => $frameworkName,
    'csrf_token' => $newCsrfToken,
]);

/**
 * Generate SOC 2 Type II report
 */
function generateSOC2Report($audit, $requirements, $controls, $findings, $categorySummary, $orgName, $auditPeriod, $frameworkName, $complianceRate, $assessedReqs, $totalReqs, $compliantReqs, $nonCompliantReqs, $ai, $useAI) {
    $sections = [];

    // Build data context for AI
    $controlsSummary = '';
    foreach ($controls as $c) {
        $controlsSummary .= $c['control_ref'] . ': ' . $c['title'] . ' (' . $c['control_type'] . '/' . $c['control_category'] . ")\n";
    }

    $findingsSummary = '';
    foreach ($findings as $f) {
        $findingsSummary .= $f['finding_ref'] . ': ' . $f['title'] . ' (Severity: ' . $f['severity'] . ', Status: ' . $f['status'] . ")\n";
    }

    $categoryBreakdown = '';
    foreach ($categorySummary as $cat => $stats) {
        $categoryBreakdown .= $cat . ': ' . $stats['compliant'] . '/' . $stats['assessed'] . " assessed as compliant\n";
    }

    $auditContext = "Organization: $orgName\nAudit Period: $auditPeriod\nFramework: $frameworkName\n"
        . "Lead Auditor: " . ($audit['lead_auditor_name'] ?? '[Lead Auditor]') . "\n"
        . "Total Requirements: $totalReqs\nAssessed: $assessedReqs\nCompliant: $compliantReqs\n"
        . "Non-Compliant: $nonCompliantReqs\nCompliance Rate: $complianceRate%\n"
        . "Number of Controls: " . count($controls) . "\n"
        . "Number of Findings: " . count($findings) . "\n\n"
        . "Trust Services Category Breakdown:\n$categoryBreakdown\n"
        . "Controls:\n$controlsSummary\n"
        . "Findings:\n" . ($findingsSummary ?: "No findings recorded.\n");

    if ($useAI) {
        $sections = generateSOC2SectionsWithAI($ai, $auditContext, $orgName, $auditPeriod, $audit);
    }

    // Fallback/supplement with template sections if AI didn't produce all sections
    $templateSections = generateSOC2TemplateSections($orgName, $auditPeriod, $audit, $requirements, $controls, $findings, $categorySummary, $complianceRate, $assessedReqs, $totalReqs, $compliantReqs, $nonCompliantReqs);

    foreach ($templateSections as $key => $section) {
        if (!isset($sections[$key]) || empty($sections[$key]['content'])) {
            $sections[$key] = $section;
        }
    }

    return $sections;
}

/**
 * Generate SOC 2 sections using AI
 */
function generateSOC2SectionsWithAI($ai, $auditContext, $orgName, $auditPeriod, $audit) {
    $sections = [];
    $escapedOrg = addslashes($orgName);

    $messages = [
        [
            'role' => 'system',
            'content' => 'You are a senior CPA and IT audit partner writing a SOC 2 Type II report following AICPA standards. Write professional, formal audit report content suitable for distribution to user entities and their auditors.

CRITICAL RULES:
- Write in formal, professional audit language using Arial/sans-serif formatting
- Use the EXACT organization name "' . $escapedOrg . '" — do NOT use placeholders
- Write in present perfect or past tense appropriate for completed audit work
- Include standard AICPA SOC 2 report language
- Be specific to the Trust Services Criteria categories assessed
- Reference specific TSC criteria numbers where appropriate
- Every section must contain COMPLETE, SUBSTANTIVE content — no placeholder text like "[Describe...]" or "[Insert...]"
- For the system description, write realistic but generic technology stack content appropriate for a SaaS/technology company

You must respond with ONLY valid JSON (no markdown fences). Return an object with these keys, each containing a "title" and "content" (HTML-formatted string with <p>, <ul>, <li>, <strong>, <em> tags):

- "opinion": "Section I: Independent Service Auditor\'s Report" — formal audit opinion with Scope, Responsibilities, and Opinion subsections
- "assertion": "Section II: Management\'s Assertion" — management responsibility and assertion statement
- "system_description": "Section III: Description of the System" — MUST include ALL of these subsections with substantive content (2+ paragraphs each):
  * System Overview — what the system does and services provided
  * Infrastructure — data centers, cloud providers, networks, servers, load balancers, firewalls
  * Software — operating systems, databases, application frameworks, monitoring tools, security software
  * People — organizational structure, roles (CISO, DevOps, compliance), hiring practices, background checks, training programs
  * Procedures — change management, incident response, backup and recovery, business continuity, vendor management
  * Data — data classification, encryption at rest and in transit, retention policies, disposal procedures
- "control_environment": "Section IV: Control Environment" — risk assessment process, monitoring activities, information and communication, control activities overview
- "complementary_criteria": "Section VII: Complementary User Entity Controls" — specific CUECs that user entities must implement'
        ],
        [
            'role' => 'user',
            'content' => "Generate the SOC 2 Type II report sections for this audit:\n\n$auditContext\n\nIMPORTANT: Every section must have complete, substantive content. The system_description section MUST include detailed Infrastructure, Software, People, Procedures, and Data subsections with realistic content. Do NOT leave any placeholders or brackets.\n\nRespond with ONLY the JSON object."
        ]
    ];

    $result = $ai->chatCompletion($messages, [
        'max_tokens' => max($ai->getMaxTokens(), 4000),
        'temperature' => 0.3,
    ]);

    if ($result['success'] && !empty($result['content'])) {
        $content = trim($result['content']);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/```\s*$/s', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);
        if ($parsed && is_array($parsed)) {
            foreach ($parsed as $key => $section) {
                if (is_array($section) && !empty($section['content'])) {
                    $sections[$key] = [
                        'title' => $section['title'] ?? ucfirst(str_replace('_', ' ', $key)),
                        'content' => $section['content'],
                    ];
                }
            }
        }
    }

    return $sections;
}

/**
 * Template-based SOC 2 sections (non-AI fallback)
 */
function generateSOC2TemplateSections($orgName, $auditPeriod, $audit, $requirements, $controls, $findings, $categorySummary, $complianceRate, $assessedReqs, $totalReqs, $compliantReqs, $nonCompliantReqs) {
    $auditorName = htmlspecialchars($audit['lead_auditor_name'] ?? '[Lead Auditor Name]', ENT_QUOTES, 'UTF-8');
    $scopeName = htmlspecialchars($audit['scope_name'] ?? '[System Name]', ENT_QUOTES, 'UTF-8');
    $h = function($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };

    $sections = [];

    // Disclaimer — Self-Assessment Notice (clean HTML, no red inline styles)
    $sections['disclaimer'] = [
        'title' => 'Important Disclaimer',
        'content' => "<p><strong>SELF-ASSESSMENT &mdash; NOT AN INDEPENDENTLY AUDITED REPORT</strong></p>"
            . "<p>This report has been prepared by $orgName as a <strong>self-assessment</strong> of its internal controls based on the AICPA Trust Services Criteria. "
            . "This report has <strong>NOT</strong> been examined, reviewed, or certified by a licensed CPA firm, an accredited independent auditor, or any authorized third-party attestation organization.</p>"
            . "<p>A SOC 2 Type II report, as defined by the AICPA, requires examination by an independent service auditor in accordance with AT-C Section 205, <em>Attestation Standards: Examination Engagements</em>. "
            . "This self-assessment does not constitute a SOC 2 Type II attestation report and should not be represented as such to third parties, user entities, regulators, or prospective customers.</p>"
            . "<p>Organizations seeking an official SOC 2 Type II report should engage a licensed CPA firm authorized to perform such attestation engagements.</p>",
    ];

    // Section I: Independent Service Auditor's Report
    $qualifiedText = count($findings) > 0
        ? 'Except for the matters described in the findings section below, in our opinion,'
        : 'In our opinion,';

    $sections['opinion'] = [
        'title' => "Section I: Independent Service Auditor's Report",
        'content' => "<p><strong>Independent Service Auditor&rsquo;s Report</strong></p>"
            . "<p>To the Management of $orgName:</p>"
            . "<p><strong>Scope</strong></p>"
            . "<p>We have examined $orgName&rsquo;s description of its $scopeName system (the &ldquo;system description&rdquo;) and the suitability of the design and operating effectiveness of controls to meet the criteria for the "
            . implode(', ', array_map($h, array_keys($categorySummary)))
            . " Trust Services Categories established by the American Institute of Certified Public Accountants (AICPA) in its <em>2017 Trust Services Criteria for Security, Availability, Processing Integrity, Confidentiality, and Privacy</em> (applicable trust services criteria) throughout the period $auditPeriod (the &ldquo;description criteria&rdquo;).</p>"
            . "<p><strong>Service Organization&rsquo;s Responsibilities</strong></p>"
            . "<p>$orgName has provided its assertion in Section II that the description fairly presents the system that was designed and implemented throughout the period $auditPeriod, based on the criteria in DC section 200A, <em>2018 Description Criteria for a Description of a Service Organization&rsquo;s System in a SOC 2 Report</em>. $orgName is responsible for (1) preparing the description and its assertion, (2) designing, implementing, and operating controls to meet the applicable trust services criteria, and (3) selecting the trust services categories and the applicable criteria to be included in the scope of the engagement.</p>"
            . "<p><strong>Service Auditor&rsquo;s Responsibilities</strong></p>"
            . "<p>Our responsibility is to express an opinion on the fairness of the presentation of the description and on the suitability of the design and operating effectiveness of the controls to meet the applicable trust services criteria, based on our examination. We conducted our examination in accordance with attestation standards established by the AICPA and the standards applicable to attestation engagements contained in <em>Government Auditing Standards</em>. Those standards require that we plan and perform our examination to obtain reasonable assurance about whether, in all material respects, the description is fairly presented and the controls were suitably designed and operating effectively to meet the applicable trust services criteria throughout the period $auditPeriod.</p>"
            . "<p><strong>Opinion</strong></p>"
            . "<p>$qualifiedText the description fairly presents the $scopeName system that was designed and implemented throughout the period $auditPeriod, in accordance with the description criteria. The controls stated in the description were suitably designed and operating effectively to provide reasonable assurance that the applicable trust services criteria were met throughout the period $auditPeriod.</p>"
            . "<p style='margin-top:20px;'>$auditorName<br>$auditPeriod</p>",
    ];

    // Section II: Management's Assertion
    $sections['assertion'] = [
        'title' => "Section II: Management's Assertion",
        'content' => "<p><strong>Management&rsquo;s Assertion of $orgName</strong></p>"
            . "<p>We are responsible for designing, implementing, operating, and maintaining effective internal controls over the $scopeName system throughout the period $auditPeriod to provide reasonable assurance that $orgName&rsquo;s service commitments and system requirements were achieved based on the applicable trust services criteria. We confirm, to the best of our knowledge and belief, that:</p>"
            . "<ol>"
            . "<li>The accompanying description of the $scopeName system fairly presents the system throughout the period $auditPeriod in accordance with the description criteria.</li>"
            . "<li>The controls stated in the description were suitably designed and operated effectively throughout the period $auditPeriod to meet the applicable trust services criteria.</li>"
            . "</ol>",
    ];

    // Section III: System Description
    $sections['system_description'] = [
        'title' => "Section III: Description of the System",
        'content' => "<p><strong>System Overview</strong></p>"
            . "<p>$orgName&rsquo;s $scopeName system provides cloud-based services to its user entities. The system is designed and operated to meet the organization&rsquo;s service commitments and system requirements as they relate to the applicable trust services criteria. The system boundaries include the infrastructure, software, people, procedures, and data components described below.</p>"
            . "<p>The principal service activities provided by the system include secure data processing, storage, and transmission of information in accordance with established service level agreements and the organization&rsquo;s information security policies.</p>"

            . "<p><strong>Infrastructure</strong></p>"
            . "<p>$orgName&rsquo;s production infrastructure is hosted in geographically distributed, SOC 2-certified data centers that provide physical security, environmental controls, and redundant power and cooling systems. The infrastructure employs a multi-tier architecture consisting of load balancers, web application servers, application processing servers, and database servers.</p>"
            . "<p>Network security is enforced through enterprise-grade firewalls, intrusion detection and prevention systems (IDS/IPS), and network segmentation. All external communications are encrypted using TLS 1.2 or higher. Virtual private networks (VPNs) are required for administrative access to production environments. Infrastructure monitoring tools provide continuous visibility into system performance, availability, and security events.</p>"

            . "<p><strong>Software</strong></p>"
            . "<p>The system operates on hardened Linux-based operating systems with automated patch management procedures. The application tier utilizes industry-standard web frameworks and programming languages with security-focused development practices. Database systems employ relational database management systems with encryption at rest using AES-256 encryption.</p>"
            . "<p>Security software components include web application firewalls (WAF), antivirus and malware detection systems, security information and event management (SIEM) platforms, and vulnerability scanning tools. Application dependencies are monitored through software composition analysis to identify known vulnerabilities in third-party libraries.</p>"

            . "<p><strong>People</strong></p>"
            . "<p>$orgName maintains a defined organizational structure with clear roles and responsibilities for information security governance. Key roles include executive management, the Chief Information Security Officer (CISO) or equivalent, system administrators, application developers, and compliance personnel. The organization maintains documented job descriptions for all security-relevant positions.</p>"
            . "<p>Personnel security practices include background checks for all employees prior to hire, confidentiality and acceptable use agreements, and mandatory security awareness training upon hire and annually thereafter. The organization conducts role-based security training for personnel with access to production systems and sensitive data. Termination procedures include timely revocation of system access and return of organizational assets.</p>"

            . "<p><strong>Procedures</strong></p>"
            . "<p>$orgName maintains documented policies and procedures governing information security, including but not limited to: change management, incident response, business continuity and disaster recovery, access management, and vendor management. Change management procedures require documented approval, testing, and review prior to deployment to production environments.</p>"
            . "<p>The incident response program includes defined procedures for identifying, reporting, evaluating, and responding to security incidents. Business continuity and disaster recovery plans are documented, tested annually, and updated based on test results. Regular backups of critical data and system configurations are performed and verified through periodic restoration testing.</p>"

            . "<p><strong>Data</strong></p>"
            . "<p>$orgName classifies data according to a defined data classification policy that categorizes information based on sensitivity and regulatory requirements. Data at rest is encrypted using AES-256 encryption, and data in transit is encrypted using TLS 1.2 or higher. Access to data is restricted based on the principle of least privilege and enforced through role-based access controls.</p>"
            . "<p>Data retention policies define the retention periods for different data categories in accordance with legal, regulatory, and contractual requirements. Data disposal procedures ensure that data is securely destroyed when no longer required, using methods appropriate to the classification level of the data. The organization maintains data flow diagrams documenting the movement of sensitive data through the system.</p>",
    ];

    // Build criteria/controls table
    $criteriaHtml = '';
    $currentCategory = '';
    foreach ($requirements as $r) {
        $cat = $r['category'] ?? 'General';
        if ($cat !== $currentCategory) {
            if ($currentCategory !== '') $criteriaHtml .= '</tbody></table>';
            $currentCategory = $cat;
            $catStats = $categorySummary[$cat] ?? ['total' => 0, 'assessed' => 0, 'compliant' => 0];
            $criteriaHtml .= "<h4 style='margin-top:16px;color:#1e40af;'>" . $h($cat) . "</h4>";
            $criteriaHtml .= "<p style='font-size:12px;color:#6b7280;'>$catStats[assessed] of $catStats[total] criteria assessed, $catStats[compliant] compliant</p>";
            $criteriaHtml .= "<table style='width:100%;border-collapse:collapse;font-size:12px;margin-bottom:12px;'>"
                . "<thead><tr style='background:#f3f4f6;'>"
                . "<th style='padding:6px 8px;text-align:left;border:1px solid #e5e7eb;width:100px;'>Criteria</th>"
                . "<th style='padding:6px 8px;text-align:left;border:1px solid #e5e7eb;'>Description</th>"
                . "<th style='padding:6px 8px;text-align:center;border:1px solid #e5e7eb;width:120px;'>Assessment</th>"
                . "</tr></thead><tbody>";
        }
        $status = $r['assessment_status'] ?? 'not_assessed';
        $statusLabel = ucfirst(str_replace('_', ' ', $status));
        $statusColor = '#6b7280';
        if ($status === 'conforming') $statusColor = '#059669';
        elseif ($status === 'non_conforming') $statusColor = '#dc2626';
        elseif ($status === 'partially_conforming') $statusColor = '#d97706';

        $criteriaHtml .= "<tr>"
            . "<td style='padding:6px 8px;border:1px solid #e5e7eb;font-weight:500;'>" . $h($r['requirement_ref']) . "</td>"
            . "<td style='padding:6px 8px;border:1px solid #e5e7eb;'>" . $h($r['title']) . "</td>"
            . "<td style='padding:6px 8px;border:1px solid #e5e7eb;text-align:center;color:$statusColor;font-weight:500;'>$statusLabel</td>"
            . "</tr>";
    }
    if ($currentCategory !== '') $criteriaHtml .= '</tbody></table>';

    // Section IV: Control Environment
    $sections['control_environment'] = [
        'title' => "Section IV: Control Environment",
        'content' => "<p><strong>Risk Assessment</strong></p>"
            . "<p>$orgName has established a formal risk assessment process to identify, analyze, and manage risks that could affect the achievement of its service commitments and system requirements. Risk assessments are conducted annually and upon significant changes to the operating environment. Identified risks are evaluated based on likelihood and impact, and appropriate mitigation strategies are developed and implemented. The risk register is reviewed by senior management and updated to reflect changes in the threat landscape.</p>"

            . "<p><strong>Monitoring Activities</strong></p>"
            . "<p>$orgName maintains continuous monitoring of its control environment through automated monitoring tools, periodic control testing, and management review. Security events and alerts are monitored through a centralized security information and event management (SIEM) platform. Key performance indicators and security metrics are reported to management on a regular basis. Internal audits of security controls are conducted to evaluate the continued effectiveness of the control environment.</p>"

            . "<p><strong>Information and Communication</strong></p>"
            . "<p>$orgName has established formal channels for communicating information security policies, changes, and incidents to relevant personnel and stakeholders. Security policies and procedures are published and accessible to all employees through the organization&rsquo;s internal documentation system. Material changes to services, security posture, or the control environment are communicated to affected user entities in a timely manner. The organization maintains a whistleblower mechanism for reporting security concerns.</p>"

            . "<p><strong>Control Activities</strong></p>"
            . "<p>Control activities are designed and implemented at all levels of the organization to mitigate identified risks to acceptable levels. These control activities include preventive controls (such as access controls, encryption, and input validation), detective controls (such as monitoring, logging, and anomaly detection), and corrective controls (such as incident response procedures and patch management). Segregation of duties is enforced for critical functions including code deployment, access provisioning, and financial processing.</p>",
    ];

    // Section V: Trust Services Criteria, Related Controls, and Test Results
    $sections['criteria_controls'] = [
        'title' => "Section V: Trust Services Criteria, Controls, and Test Results",
        'content' => "<p>The following table presents the applicable Trust Services Criteria, the controls implemented to address each criterion, and the results of our testing procedures performed during the audit period.</p>"
            . "<p><strong>Overall Compliance Rate: {$complianceRate}%</strong> ($compliantReqs of $assessedReqs assessed criteria met)</p>"
            . $criteriaHtml,
    ];

    // Section V-B: Controls Matrix
    if (count($controls) > 0) {
        $controlsHtml = "<table style='width:100%;border-collapse:collapse;font-size:12px;'>"
            . "<thead><tr style='background:#f3f4f6;'>"
            . "<th style='padding:6px 8px;text-align:left;border:1px solid #e5e7eb;width:80px;'>Ref</th>"
            . "<th style='padding:6px 8px;text-align:left;border:1px solid #e5e7eb;'>Control Description</th>"
            . "<th style='padding:6px 8px;text-align:center;border:1px solid #e5e7eb;width:80px;'>Type</th>"
            . "<th style='padding:6px 8px;text-align:center;border:1px solid #e5e7eb;width:80px;'>Category</th>"
            . "<th style='padding:6px 8px;text-align:center;border:1px solid #e5e7eb;width:90px;'>Status</th>"
            . "</tr></thead><tbody>";
        foreach ($controls as $c) {
            $statusLabel = ucfirst(str_replace('_', ' ', $c['implementation_status']));
            $statusColor = $c['implementation_status'] === 'implemented' ? '#059669' : ($c['implementation_status'] === 'in_progress' ? '#d97706' : '#6b7280');
            $controlsHtml .= "<tr>"
                . "<td style='padding:6px 8px;border:1px solid #e5e7eb;font-weight:500;'>" . $h($c['control_ref']) . "</td>"
                . "<td style='padding:6px 8px;border:1px solid #e5e7eb;'>" . $h($c['title']) . "</td>"
                . "<td style='padding:6px 8px;border:1px solid #e5e7eb;text-align:center;'>" . $h(ucfirst($c['control_type'])) . "</td>"
                . "<td style='padding:6px 8px;border:1px solid #e5e7eb;text-align:center;'>" . $h(ucfirst($c['control_category'])) . "</td>"
                . "<td style='padding:6px 8px;border:1px solid #e5e7eb;text-align:center;color:$statusColor;font-weight:500;'>$statusLabel</td>"
                . "</tr>";
        }
        $controlsHtml .= '</tbody></table>';

        $sections['controls_matrix'] = [
            'title' => 'Controls Matrix',
            'content' => "<p>The following internal controls were identified and tested during the audit period:</p>" . $controlsHtml,
        ];
    }

    // Section VI: Findings and Observations
    if (count($findings) > 0) {
        $findingsHtml = '';
        foreach ($findings as $f) {
            $sevColor = '#6b7280';
            if ($f['severity'] === 'critical') $sevColor = '#dc2626';
            elseif ($f['severity'] === 'high') $sevColor = '#ea580c';
            elseif ($f['severity'] === 'medium') $sevColor = '#d97706';

            $findingsHtml .= "<div style='border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:10px;'>"
                . "<div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;'>"
                . "<strong>" . $h($f['finding_ref']) . ": " . $h($f['title']) . "</strong>"
                . "<span style='font-size:11px;padding:2px 8px;border-radius:4px;background:" . ($f['severity'] === 'critical' ? '#fef2f2' : ($f['severity'] === 'high' ? '#fff7ed' : '#fefce8')) . ";color:$sevColor;font-weight:600;'>" . $h(ucfirst($f['severity'])) . "</span>"
                . "</div>"
                . ($f['description'] ? "<p style='font-size:12px;color:#374151;margin:4px 0;'>" . $h($f['description']) . "</p>" : '')
                . ($f['remediation_plan'] ? "<p style='font-size:12px;color:#6b7280;margin:4px 0;'><strong>Recommendation:</strong> " . $h($f['remediation_plan']) . "</p>" : '')
                . "<p style='font-size:11px;color:#9ca3af;margin:4px 0 0;'>Status: " . $h(ucfirst(str_replace('_', ' ', $f['status']))) . "</p>"
                . "</div>";
        }
        $sections['findings'] = [
            'title' => "Section VI: Findings and Observations",
            'content' => "<p>The following findings were identified during our examination:</p>" . $findingsHtml,
        ];
    } else {
        $sections['findings'] = [
            'title' => "Section VI: Findings and Observations",
            'content' => "<p>No exceptions or findings were identified during our examination of the controls as they relate to the applicable trust services criteria throughout the period $auditPeriod.</p>",
        ];
    }

    // Section VII: Complementary User Entity Controls
    $sections['complementary_criteria'] = [
        'title' => "Section VII: Complementary User Entity Controls",
        'content' => "<p>$orgName&rsquo;s controls were designed with the assumption that certain complementary user entity controls (CUECs) will be implemented by user entities. User entities should evaluate whether they have established the following controls:</p>"
            . "<ul>"
            . "<li>User entities are responsible for ensuring that user accounts and access credentials are managed appropriately, including timely notification to $orgName of personnel changes.</li>"
            . "<li>User entities are responsible for maintaining the security of their own systems and networks that connect to $orgName&rsquo;s services.</li>"
            . "<li>User entities are responsible for establishing their own disaster recovery and business continuity plans as they relate to the use of $orgName&rsquo;s services.</li>"
            . "<li>User entities are responsible for ensuring that data transmitted to $orgName is accurate, complete, and authorized.</li>"
            . "<li>User entities are responsible for reviewing and retaining reports and notifications provided by $orgName.</li>"
            . "</ul>",
    ];

    return $sections;
}

/**
 * Generate a generic (non-SOC2) audit report
 */
function generateGenericReport($audit, $requirements, $controls, $findings, $categorySummary, $orgName, $auditPeriod, $frameworkName, $complianceRate, $assessedReqs, $totalReqs, $compliantReqs, $nonCompliantReqs, $ai, $useAI) {
    // Reuse the template approach from SOC2 with framework-agnostic language
    $h = function($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $auditorName = htmlspecialchars($audit['lead_auditor_name'] ?? '[Lead Auditor Name]', ENT_QUOTES, 'UTF-8');
    $scopeName = htmlspecialchars($audit['scope_name'] ?? '[System Name]', ENT_QUOTES, 'UTF-8');

    $sections = [];

    $sections['summary'] = [
        'title' => 'Executive Summary',
        'content' => "<p>This report presents the results of the $frameworkName compliance audit conducted for $orgName during the period $auditPeriod.</p>"
            . "<p><strong>Audit Scope:</strong> $scopeName</p>"
            . "<p><strong>Lead Auditor:</strong> $auditorName</p>"
            . "<p><strong>Overall Compliance Rate:</strong> {$complianceRate}% ($compliantReqs of $assessedReqs requirements assessed as compliant)</p>"
            . "<p><strong>Total Requirements:</strong> $totalReqs</p>"
            . "<p><strong>Controls Evaluated:</strong> " . count($controls) . "</p>"
            . "<p><strong>Findings:</strong> " . count($findings) . "</p>",
    ];

    // Requirements summary by category
    $catHtml = '';
    foreach ($categorySummary as $cat => $stats) {
        $pct = $stats['assessed'] > 0 ? round(($stats['compliant'] / $stats['assessed']) * 100, 1) : 0;
        $catHtml .= "<tr>"
            . "<td style='padding:6px 8px;border:1px solid #e5e7eb;'>" . $h($cat) . "</td>"
            . "<td style='padding:6px 8px;border:1px solid #e5e7eb;text-align:center;'>$stats[total]</td>"
            . "<td style='padding:6px 8px;border:1px solid #e5e7eb;text-align:center;'>$stats[assessed]</td>"
            . "<td style='padding:6px 8px;border:1px solid #e5e7eb;text-align:center;'>$stats[compliant]</td>"
            . "<td style='padding:6px 8px;border:1px solid #e5e7eb;text-align:center;font-weight:500;'>{$pct}%</td>"
            . "</tr>";
    }

    $sections['compliance'] = [
        'title' => 'Compliance Assessment Summary',
        'content' => "<table style='width:100%;border-collapse:collapse;font-size:12px;'>"
            . "<thead><tr style='background:#f3f4f6;'>"
            . "<th style='padding:6px 8px;text-align:left;border:1px solid #e5e7eb;'>Category</th>"
            . "<th style='padding:6px 8px;text-align:center;border:1px solid #e5e7eb;'>Total</th>"
            . "<th style='padding:6px 8px;text-align:center;border:1px solid #e5e7eb;'>Assessed</th>"
            . "<th style='padding:6px 8px;text-align:center;border:1px solid #e5e7eb;'>Compliant</th>"
            . "<th style='padding:6px 8px;text-align:center;border:1px solid #e5e7eb;'>Rate</th>"
            . "</tr></thead><tbody>" . $catHtml . "</tbody></table>",
    ];

    // Findings
    if (count($findings) > 0) {
        $findingsHtml = '';
        foreach ($findings as $f) {
            $findingsHtml .= "<div style='border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:10px;'>"
                . "<strong>" . $h($f['finding_ref']) . ": " . $h($f['title']) . "</strong>"
                . " <span style='font-size:11px;color:#6b7280;'>(" . $h(ucfirst($f['severity'])) . ")</span>"
                . ($f['description'] ? "<p style='font-size:12px;margin:4px 0;'>" . $h($f['description']) . "</p>" : '')
                . ($f['remediation_plan'] ? "<p style='font-size:12px;color:#6b7280;margin:4px 0;'><strong>Recommendation:</strong> " . $h($f['remediation_plan']) . "</p>" : '')
                . "</div>";
        }
        $sections['findings'] = [
            'title' => 'Findings and Observations',
            'content' => $findingsHtml,
        ];
    }

    return $sections;
}
