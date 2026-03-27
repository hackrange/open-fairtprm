<?php
/**
 * Cross-framework Requirement Crosswalk Mappings
 * Maps equivalent requirements between compliance frameworks
 *
 * strength values:
 *   'exact'   — 1:1 mapping, requirements are essentially identical
 *   'strong'  — very similar scope and intent, minor differences in wording or depth
 *   'partial' — overlapping coverage, one may be broader or narrower than the other
 *   'related' — loosely related, addressing the same general domain but different aspects
 *
 * Format: ['source' => 'FRAMEWORK:ref', 'target' => 'FRAMEWORK:ref', 'strength' => '...']
 *
 * Framework ref code conventions:
 *   ISO27001  — A.5.1 through A.8.34
 *   NIST-CSF  — GV.xx-nn, ID.xx-nn, PR.xx-nn, DE.xx-nn, RS.xx-nn, RC.xx-nn
 *   SOC2      — CC1.1–CC9.9, A1.1–A1.3, C1.1–C1.2, PI1.1–PI1.5
 *   NIST-171  — 3.1.1 through 3.14.7
 *   CMMC      — AC.L1-3.1.1, AC.L2-3.1.3, etc.
 *   SOX       — COSO-1 through COSO-17
 *   PCI-DSS   — 1.1, 1.2, 2.1, etc.
 */
return [

    // ═══════════════════════════════════════════════════════════════════
    //  1. ISO 27001 <-> NIST CSF 2.0
    //     Mapping ISO 27001:2022 Annex A controls to NIST CSF subcategories
    // ═══════════════════════════════════════════════════════════════════

    // -- A.5 Organizational controls --
    ['source' => 'ISO27001:A.5.1',  'target' => 'NIST-CSF:GV.PO-01', 'strength' => 'strong'],   // Information security policies -> Governance policies
    ['source' => 'ISO27001:A.5.1',  'target' => 'NIST-CSF:GV.PO-02', 'strength' => 'partial'],   // Policies -> Policy communication
    ['source' => 'ISO27001:A.5.2',  'target' => 'NIST-CSF:GV.RR-01', 'strength' => 'strong'],   // Roles and responsibilities -> Roles established
    ['source' => 'ISO27001:A.5.2',  'target' => 'NIST-CSF:GV.RR-02', 'strength' => 'partial'],   // Roles -> Roles communicated
    ['source' => 'ISO27001:A.5.3',  'target' => 'NIST-CSF:PR.AA-01', 'strength' => 'strong'],   // Segregation of duties -> Access provisioning
    ['source' => 'ISO27001:A.5.4',  'target' => 'NIST-CSF:GV.RR-01', 'strength' => 'partial'],   // Management responsibilities -> Roles
    ['source' => 'ISO27001:A.5.5',  'target' => 'NIST-CSF:GV.PO-01', 'strength' => 'partial'],   // Contact with authorities -> Governance
    ['source' => 'ISO27001:A.5.6',  'target' => 'NIST-CSF:ID.RA-02', 'strength' => 'partial'],   // Contact with special interest groups -> Threat intelligence
    ['source' => 'ISO27001:A.5.7',  'target' => 'NIST-CSF:ID.RA-01', 'strength' => 'strong'],   // Threat intelligence -> Risk assessment vulnerabilities
    ['source' => 'ISO27001:A.5.7',  'target' => 'NIST-CSF:ID.RA-02', 'strength' => 'strong'],   // Threat intelligence -> Threat intelligence
    ['source' => 'ISO27001:A.5.8',  'target' => 'NIST-CSF:GV.PO-01', 'strength' => 'partial'],   // Info security in project mgmt -> Governance policy
    ['source' => 'ISO27001:A.5.9',  'target' => 'NIST-CSF:ID.AM-01', 'strength' => 'strong'],   // Inventory of information -> Asset management
    ['source' => 'ISO27001:A.5.9',  'target' => 'NIST-CSF:ID.AM-02', 'strength' => 'strong'],   // Inventory -> Software inventory
    ['source' => 'ISO27001:A.5.10', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Acceptable use -> Data security
    ['source' => 'ISO27001:A.5.11', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Return of assets -> Data protection
    ['source' => 'ISO27001:A.5.12', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'strong'],   // Classification -> Data protection
    ['source' => 'ISO27001:A.5.12', 'target' => 'NIST-CSF:PR.DS-02', 'strength' => 'partial'],   // Classification -> Data in transit
    ['source' => 'ISO27001:A.5.13', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'strong'],   // Labelling -> Data security at rest
    ['source' => 'ISO27001:A.5.14', 'target' => 'NIST-CSF:PR.DS-02', 'strength' => 'strong'],   // Information transfer -> Data in transit
    ['source' => 'ISO27001:A.5.15', 'target' => 'NIST-CSF:PR.AA-01', 'strength' => 'strong'],   // Access control -> Identity mgmt & access
    ['source' => 'ISO27001:A.5.15', 'target' => 'NIST-CSF:PR.AA-03', 'strength' => 'strong'],   // Access control -> Access enforcement
    ['source' => 'ISO27001:A.5.16', 'target' => 'NIST-CSF:PR.AA-01', 'strength' => 'strong'],   // Identity management -> Identity mgmt
    ['source' => 'ISO27001:A.5.17', 'target' => 'NIST-CSF:PR.AA-02', 'strength' => 'strong'],   // Authentication information -> Authentication
    ['source' => 'ISO27001:A.5.18', 'target' => 'NIST-CSF:PR.AA-03', 'strength' => 'strong'],   // Access rights -> Access enforcement
    ['source' => 'ISO27001:A.5.18', 'target' => 'NIST-CSF:PR.AA-05', 'strength' => 'strong'],   // Access rights -> Access review
    ['source' => 'ISO27001:A.5.19', 'target' => 'NIST-CSF:GV.SC-01', 'strength' => 'strong'],   // Supplier security -> Supply chain risk mgmt
    ['source' => 'ISO27001:A.5.20', 'target' => 'NIST-CSF:GV.SC-02', 'strength' => 'strong'],   // Supplier agreements -> Supply chain requirements
    ['source' => 'ISO27001:A.5.21', 'target' => 'NIST-CSF:GV.SC-03', 'strength' => 'strong'],   // Supply chain ICT -> Supply chain risk
    ['source' => 'ISO27001:A.5.22', 'target' => 'NIST-CSF:GV.SC-04', 'strength' => 'partial'],   // Monitoring of suppliers -> Supplier monitoring
    ['source' => 'ISO27001:A.5.23', 'target' => 'NIST-CSF:GV.SC-01', 'strength' => 'partial'],   // Cloud services security -> Supply chain
    ['source' => 'ISO27001:A.5.24', 'target' => 'NIST-CSF:RS.MA-01', 'strength' => 'strong'],   // Incident mgmt planning -> Incident management
    ['source' => 'ISO27001:A.5.25', 'target' => 'NIST-CSF:RS.MA-01', 'strength' => 'partial'],   // Assessment of info sec events -> Incident analysis
    ['source' => 'ISO27001:A.5.26', 'target' => 'NIST-CSF:RS.MA-02', 'strength' => 'strong'],   // Response to incidents -> Incident response
    ['source' => 'ISO27001:A.5.27', 'target' => 'NIST-CSF:RS.MA-03', 'strength' => 'partial'],   // Learning from incidents -> Incident reporting
    ['source' => 'ISO27001:A.5.28', 'target' => 'NIST-CSF:RS.MA-01', 'strength' => 'partial'],   // Collection of evidence -> Incident management
    ['source' => 'ISO27001:A.5.29', 'target' => 'NIST-CSF:RC.RP-01', 'strength' => 'strong'],   // ICT continuity -> Recovery planning
    ['source' => 'ISO27001:A.5.30', 'target' => 'NIST-CSF:RC.RP-02', 'strength' => 'strong'],   // ICT readiness for BC -> Recovery execution
    ['source' => 'ISO27001:A.5.31', 'target' => 'NIST-CSF:GV.OC-01', 'strength' => 'partial'],   // Legal requirements -> Organizational context
    ['source' => 'ISO27001:A.5.32', 'target' => 'NIST-CSF:GV.OC-02', 'strength' => 'partial'],   // IP rights -> Legal obligations
    ['source' => 'ISO27001:A.5.33', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Protection of records -> Data security
    ['source' => 'ISO27001:A.5.34', 'target' => 'NIST-CSF:GV.OC-02', 'strength' => 'partial'],   // Privacy/PII -> Legal/regulatory
    ['source' => 'ISO27001:A.5.35', 'target' => 'NIST-CSF:GV.PO-01', 'strength' => 'partial'],   // Independent review -> Governance
    ['source' => 'ISO27001:A.5.36', 'target' => 'NIST-CSF:GV.PO-01', 'strength' => 'partial'],   // Compliance with policies -> Governance
    ['source' => 'ISO27001:A.5.37', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Documented operating procedures -> Platform security

    // -- A.6 People controls --
    ['source' => 'ISO27001:A.6.1',  'target' => 'NIST-CSF:GV.RR-01', 'strength' => 'partial'],   // Screening -> Roles
    ['source' => 'ISO27001:A.6.2',  'target' => 'NIST-CSF:GV.RR-01', 'strength' => 'partial'],   // Terms of employment -> Roles
    ['source' => 'ISO27001:A.6.3',  'target' => 'NIST-CSF:GV.RR-04', 'strength' => 'strong'],   // Awareness/training -> Awareness training
    ['source' => 'ISO27001:A.6.4',  'target' => 'NIST-CSF:GV.RR-01', 'strength' => 'partial'],   // Disciplinary process -> Accountability
    ['source' => 'ISO27001:A.6.5',  'target' => 'NIST-CSF:GV.RR-01', 'strength' => 'partial'],   // Termination responsibilities -> Roles
    ['source' => 'ISO27001:A.6.6',  'target' => 'NIST-CSF:GV.RR-01', 'strength' => 'related'],   // Confidentiality agreements -> Roles
    ['source' => 'ISO27001:A.6.7',  'target' => 'NIST-CSF:PR.AA-03', 'strength' => 'partial'],   // Remote working -> Access enforcement
    ['source' => 'ISO27001:A.6.8',  'target' => 'NIST-CSF:DE.CM-01', 'strength' => 'partial'],   // Security event reporting -> Continuous monitoring

    // -- A.7 Physical controls --
    ['source' => 'ISO27001:A.7.1',  'target' => 'NIST-CSF:PR.AA-01', 'strength' => 'partial'],   // Physical perimeters -> Access
    ['source' => 'ISO27001:A.7.2',  'target' => 'NIST-CSF:PR.AA-01', 'strength' => 'partial'],   // Physical entry -> Access
    ['source' => 'ISO27001:A.7.3',  'target' => 'NIST-CSF:PR.AA-01', 'strength' => 'partial'],   // Securing offices -> Access
    ['source' => 'ISO27001:A.7.4',  'target' => 'NIST-CSF:DE.CM-01', 'strength' => 'partial'],   // Physical monitoring -> Continuous monitoring
    ['source' => 'ISO27001:A.7.5',  'target' => 'NIST-CSF:PR.IR-01', 'strength' => 'partial'],   // Environmental threats -> Resilience
    ['source' => 'ISO27001:A.7.6',  'target' => 'NIST-CSF:PR.IR-01', 'strength' => 'partial'],   // Working in secure areas -> Resilience
    ['source' => 'ISO27001:A.7.7',  'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Clear desk/screen -> Data protection
    ['source' => 'ISO27001:A.7.8',  'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Equipment siting -> Platform security
    ['source' => 'ISO27001:A.7.9',  'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Asset security off-premises -> Data protection
    ['source' => 'ISO27001:A.7.10', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Storage media -> Data protection
    ['source' => 'ISO27001:A.7.11', 'target' => 'NIST-CSF:PR.IR-01', 'strength' => 'partial'],   // Supporting utilities -> Resilience
    ['source' => 'ISO27001:A.7.12', 'target' => 'NIST-CSF:PR.IR-01', 'strength' => 'partial'],   // Cabling security -> Resilience
    ['source' => 'ISO27001:A.7.13', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Equipment maintenance -> Platform security
    ['source' => 'ISO27001:A.7.14', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Secure disposal -> Data protection

    // -- A.8 Technological controls --
    ['source' => 'ISO27001:A.8.1',  'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'strong'],   // User endpoint devices -> Platform security
    ['source' => 'ISO27001:A.8.2',  'target' => 'NIST-CSF:PR.AA-03', 'strength' => 'strong'],   // Privileged access -> Access enforcement
    ['source' => 'ISO27001:A.8.3',  'target' => 'NIST-CSF:PR.AA-03', 'strength' => 'strong'],   // Information access restriction -> Access enforcement
    ['source' => 'ISO27001:A.8.4',  'target' => 'NIST-CSF:PR.AA-03', 'strength' => 'partial'],   // Access to source code -> Access enforcement
    ['source' => 'ISO27001:A.8.5',  'target' => 'NIST-CSF:PR.AA-02', 'strength' => 'strong'],   // Secure authentication -> Authentication
    ['source' => 'ISO27001:A.8.6',  'target' => 'NIST-CSF:ID.AM-01', 'strength' => 'partial'],   // Capacity management -> Asset management
    ['source' => 'ISO27001:A.8.7',  'target' => 'NIST-CSF:DE.CM-01', 'strength' => 'strong'],   // Protection against malware -> Continuous monitoring
    ['source' => 'ISO27001:A.8.8',  'target' => 'NIST-CSF:ID.RA-01', 'strength' => 'strong'],   // Tech vulnerability mgmt -> Vulnerability identification
    ['source' => 'ISO27001:A.8.9',  'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'strong'],   // Configuration management -> Platform security
    ['source' => 'ISO27001:A.8.10', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Information deletion -> Data at rest protection
    ['source' => 'ISO27001:A.8.11', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'strong'],   // Data masking -> Data security
    ['source' => 'ISO27001:A.8.12', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Data leakage prevention -> Data security
    ['source' => 'ISO27001:A.8.13', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Information backup -> Data protection
    ['source' => 'ISO27001:A.8.13', 'target' => 'NIST-CSF:PR.IR-01', 'strength' => 'partial'],   // Backup -> Resilience
    ['source' => 'ISO27001:A.8.14', 'target' => 'NIST-CSF:PR.IR-02', 'strength' => 'partial'],   // Redundancy -> Resilience
    ['source' => 'ISO27001:A.8.15', 'target' => 'NIST-CSF:DE.CM-01', 'strength' => 'strong'],   // Logging -> Continuous monitoring
    ['source' => 'ISO27001:A.8.16', 'target' => 'NIST-CSF:DE.CM-01', 'strength' => 'strong'],   // Monitoring -> Continuous monitoring
    ['source' => 'ISO27001:A.8.16', 'target' => 'NIST-CSF:DE.AE-02', 'strength' => 'strong'],   // Monitoring -> Anomaly analysis
    ['source' => 'ISO27001:A.8.17', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Clock synchronization -> Platform security
    ['source' => 'ISO27001:A.8.18', 'target' => 'NIST-CSF:PR.AA-03', 'strength' => 'partial'],   // Use of privileged utility -> Access enforcement
    ['source' => 'ISO27001:A.8.19', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Installation of software -> Platform security
    ['source' => 'ISO27001:A.8.20', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'strong'],   // Network security -> Network security
    ['source' => 'ISO27001:A.8.21', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Security of network services -> Network security
    ['source' => 'ISO27001:A.8.22', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Segregation of networks -> Network security
    ['source' => 'ISO27001:A.8.23', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Web filtering -> Platform security
    ['source' => 'ISO27001:A.8.24', 'target' => 'NIST-CSF:PR.DS-02', 'strength' => 'strong'],   // Use of cryptography -> Data in transit
    ['source' => 'ISO27001:A.8.25', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Secure dev lifecycle -> Secure development
    ['source' => 'ISO27001:A.8.26', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // App security requirements -> Platform security
    ['source' => 'ISO27001:A.8.27', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Secure system architecture -> Platform security
    ['source' => 'ISO27001:A.8.28', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Secure coding -> Platform security
    ['source' => 'ISO27001:A.8.29', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Security testing -> Platform security
    ['source' => 'ISO27001:A.8.30', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Outsourced development -> Supply chain
    ['source' => 'ISO27001:A.8.31', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Separation of environments -> Platform security
    ['source' => 'ISO27001:A.8.32', 'target' => 'NIST-CSF:PR.PS-01', 'strength' => 'partial'],   // Change management -> Configuration management
    ['source' => 'ISO27001:A.8.33', 'target' => 'NIST-CSF:PR.DS-01', 'strength' => 'partial'],   // Test information -> Data protection
    ['source' => 'ISO27001:A.8.34', 'target' => 'NIST-CSF:DE.CM-01', 'strength' => 'partial'],   // Audit testing protection -> Monitoring

    // ═══════════════════════════════════════════════════════════════════
    //  2. ISO 27001 <-> SOC 2 (Trust Services Criteria)
    // ═══════════════════════════════════════════════════════════════════

    // -- Organizational controls -> Common Criteria --
    ['source' => 'ISO27001:A.5.1',  'target' => 'SOC2:CC1.1',  'strength' => 'strong'],   // Policies -> COSO integrity/ethics
    ['source' => 'ISO27001:A.5.1',  'target' => 'SOC2:CC1.4',  'strength' => 'strong'],   // Policies -> Oversight and code of conduct
    ['source' => 'ISO27001:A.5.2',  'target' => 'SOC2:CC1.3',  'strength' => 'strong'],   // Roles -> Establishes structure/authority
    ['source' => 'ISO27001:A.5.3',  'target' => 'SOC2:CC5.1',  'strength' => 'partial'],   // Segregation of duties -> Control activities
    ['source' => 'ISO27001:A.5.4',  'target' => 'SOC2:CC1.4',  'strength' => 'partial'],   // Management responsibilities -> Board oversight
    ['source' => 'ISO27001:A.5.7',  'target' => 'SOC2:CC3.2',  'strength' => 'strong'],   // Threat intelligence -> Risk assessment
    ['source' => 'ISO27001:A.5.9',  'target' => 'SOC2:CC6.1',  'strength' => 'partial'],   // Inventory -> Logical/physical access
    ['source' => 'ISO27001:A.5.10', 'target' => 'SOC2:CC6.1',  'strength' => 'partial'],   // Acceptable use -> Access controls
    ['source' => 'ISO27001:A.5.12', 'target' => 'SOC2:CC6.1',  'strength' => 'partial'],   // Classification -> Access controls
    ['source' => 'ISO27001:A.5.14', 'target' => 'SOC2:CC6.7',  'strength' => 'strong'],   // Information transfer -> Transmission security
    ['source' => 'ISO27001:A.5.15', 'target' => 'SOC2:CC6.1',  'strength' => 'strong'],   // Access control -> Logical/physical access
    ['source' => 'ISO27001:A.5.16', 'target' => 'SOC2:CC6.1',  'strength' => 'strong'],   // Identity management -> Access controls
    ['source' => 'ISO27001:A.5.17', 'target' => 'SOC2:CC6.1',  'strength' => 'strong'],   // Authentication -> Access controls
    ['source' => 'ISO27001:A.5.18', 'target' => 'SOC2:CC6.2',  'strength' => 'strong'],   // Access rights -> Access provisioning
    ['source' => 'ISO27001:A.5.18', 'target' => 'SOC2:CC6.3',  'strength' => 'strong'],   // Access rights -> Access removal
    ['source' => 'ISO27001:A.5.19', 'target' => 'SOC2:CC9.2',  'strength' => 'strong'],   // Supplier security -> Vendor risk mgmt
    ['source' => 'ISO27001:A.5.20', 'target' => 'SOC2:CC9.2',  'strength' => 'strong'],   // Supplier agreements -> Vendor management
    ['source' => 'ISO27001:A.5.21', 'target' => 'SOC2:CC9.2',  'strength' => 'partial'],   // Supply chain ICT -> Vendor risk
    ['source' => 'ISO27001:A.5.24', 'target' => 'SOC2:CC7.3',  'strength' => 'strong'],   // Incident mgmt -> Response to identified issues
    ['source' => 'ISO27001:A.5.25', 'target' => 'SOC2:CC7.3',  'strength' => 'strong'],   // Assessment of events -> Evaluates events
    ['source' => 'ISO27001:A.5.26', 'target' => 'SOC2:CC7.4',  'strength' => 'strong'],   // Response to incidents -> Responds to incidents
    ['source' => 'ISO27001:A.5.27', 'target' => 'SOC2:CC7.5',  'strength' => 'strong'],   // Learning from incidents -> Remediates incidents
    ['source' => 'ISO27001:A.5.29', 'target' => 'SOC2:A1.2',   'strength' => 'strong'],   // ICT continuity -> Recovery planning
    ['source' => 'ISO27001:A.5.30', 'target' => 'SOC2:A1.2',   'strength' => 'strong'],   // ICT readiness for BC -> Recovery objectives
    ['source' => 'ISO27001:A.5.35', 'target' => 'SOC2:CC4.1',  'strength' => 'strong'],   // Independent review -> Monitoring/evaluation

    // -- Technological controls -> SOC 2 --
    ['source' => 'ISO27001:A.8.1',  'target' => 'SOC2:CC6.8',  'strength' => 'partial'],   // Endpoint devices -> Controls against threats
    ['source' => 'ISO27001:A.8.2',  'target' => 'SOC2:CC6.1',  'strength' => 'strong'],   // Privileged access -> Logical access
    ['source' => 'ISO27001:A.8.3',  'target' => 'SOC2:CC6.1',  'strength' => 'strong'],   // Info access restriction -> Logical access
    ['source' => 'ISO27001:A.8.5',  'target' => 'SOC2:CC6.1',  'strength' => 'strong'],   // Secure auth -> Logical access controls
    ['source' => 'ISO27001:A.8.7',  'target' => 'SOC2:CC6.8',  'strength' => 'strong'],   // Malware protection -> Malware controls
    ['source' => 'ISO27001:A.8.8',  'target' => 'SOC2:CC7.1',  'strength' => 'strong'],   // Vulnerability mgmt -> Detects changes
    ['source' => 'ISO27001:A.8.9',  'target' => 'SOC2:CC8.1',  'strength' => 'strong'],   // Configuration mgmt -> Change management
    ['source' => 'ISO27001:A.8.13', 'target' => 'SOC2:A1.2',   'strength' => 'strong'],   // Backup -> Recovery mechanisms
    ['source' => 'ISO27001:A.8.15', 'target' => 'SOC2:CC7.2',  'strength' => 'strong'],   // Logging -> Monitors components
    ['source' => 'ISO27001:A.8.16', 'target' => 'SOC2:CC7.2',  'strength' => 'strong'],   // Monitoring -> Monitors system components
    ['source' => 'ISO27001:A.8.20', 'target' => 'SOC2:CC6.6',  'strength' => 'strong'],   // Network security -> Network boundaries
    ['source' => 'ISO27001:A.8.22', 'target' => 'SOC2:CC6.6',  'strength' => 'strong'],   // Network segregation -> Logical boundaries
    ['source' => 'ISO27001:A.8.24', 'target' => 'SOC2:CC6.7',  'strength' => 'strong'],   // Cryptography -> Encryption in transit
    ['source' => 'ISO27001:A.8.25', 'target' => 'SOC2:CC8.1',  'strength' => 'partial'],   // Secure dev lifecycle -> Change management
    ['source' => 'ISO27001:A.8.32', 'target' => 'SOC2:CC8.1',  'strength' => 'strong'],   // Change management -> Change management

    // -- People controls -> SOC 2 --
    ['source' => 'ISO27001:A.6.1',  'target' => 'SOC2:CC1.4',  'strength' => 'partial'],   // Screening -> Attracts/develops/retains
    ['source' => 'ISO27001:A.6.3',  'target' => 'SOC2:CC1.4',  'strength' => 'strong'],   // Awareness/training -> Competent individuals
    ['source' => 'ISO27001:A.6.4',  'target' => 'SOC2:CC1.5',  'strength' => 'strong'],   // Disciplinary -> Accountability

    // -- Physical controls -> SOC 2 --
    ['source' => 'ISO27001:A.7.1',  'target' => 'SOC2:CC6.4',  'strength' => 'strong'],   // Physical perimeters -> Physical access
    ['source' => 'ISO27001:A.7.2',  'target' => 'SOC2:CC6.4',  'strength' => 'strong'],   // Physical entry -> Physical access
    ['source' => 'ISO27001:A.7.3',  'target' => 'SOC2:CC6.4',  'strength' => 'partial'],   // Securing offices -> Physical restrictions
    ['source' => 'ISO27001:A.7.4',  'target' => 'SOC2:CC6.4',  'strength' => 'partial'],   // Physical monitoring -> Physical access monitoring

    // ═══════════════════════════════════════════════════════════════════
    //  3. NIST 800-171 <-> CMMC (nearly 1:1 mapping)
    //     CMMC L2 practices directly reference NIST 800-171 controls
    // ═══════════════════════════════════════════════════════════════════

    // -- Access Control (AC) --
    ['source' => 'NIST-171:3.1.1',  'target' => 'CMMC:AC.L1-3.1.1',  'strength' => 'exact'],    // Authorized access control
    ['source' => 'NIST-171:3.1.2',  'target' => 'CMMC:AC.L1-3.1.2',  'strength' => 'exact'],    // Transaction & function control
    ['source' => 'NIST-171:3.1.3',  'target' => 'CMMC:AC.L2-3.1.3',  'strength' => 'exact'],    // CUI flow control
    ['source' => 'NIST-171:3.1.4',  'target' => 'CMMC:AC.L2-3.1.4',  'strength' => 'exact'],    // Separation of duties
    ['source' => 'NIST-171:3.1.5',  'target' => 'CMMC:AC.L2-3.1.5',  'strength' => 'exact'],    // Least privilege
    ['source' => 'NIST-171:3.1.6',  'target' => 'CMMC:AC.L2-3.1.6',  'strength' => 'exact'],    // Non-privileged account use
    ['source' => 'NIST-171:3.1.7',  'target' => 'CMMC:AC.L2-3.1.7',  'strength' => 'exact'],    // Privileged functions
    ['source' => 'NIST-171:3.1.8',  'target' => 'CMMC:AC.L2-3.1.8',  'strength' => 'exact'],    // Unsuccessful logon attempts
    ['source' => 'NIST-171:3.1.9',  'target' => 'CMMC:AC.L2-3.1.9',  'strength' => 'exact'],    // Privacy & security notices
    ['source' => 'NIST-171:3.1.10', 'target' => 'CMMC:AC.L2-3.1.10', 'strength' => 'exact'],    // Session lock
    ['source' => 'NIST-171:3.1.11', 'target' => 'CMMC:AC.L2-3.1.11', 'strength' => 'exact'],    // Session termination
    ['source' => 'NIST-171:3.1.12', 'target' => 'CMMC:AC.L2-3.1.12', 'strength' => 'exact'],    // Remote access control
    ['source' => 'NIST-171:3.1.13', 'target' => 'CMMC:AC.L2-3.1.13', 'strength' => 'exact'],    // Remote access encryption
    ['source' => 'NIST-171:3.1.14', 'target' => 'CMMC:AC.L2-3.1.14', 'strength' => 'exact'],    // Remote access routing
    ['source' => 'NIST-171:3.1.15', 'target' => 'CMMC:AC.L2-3.1.15', 'strength' => 'exact'],    // Privileged remote access
    ['source' => 'NIST-171:3.1.16', 'target' => 'CMMC:AC.L2-3.1.16', 'strength' => 'exact'],    // Wireless access authorization
    ['source' => 'NIST-171:3.1.17', 'target' => 'CMMC:AC.L2-3.1.17', 'strength' => 'exact'],    // Wireless access protection
    ['source' => 'NIST-171:3.1.18', 'target' => 'CMMC:AC.L2-3.1.18', 'strength' => 'exact'],    // Mobile device connection
    ['source' => 'NIST-171:3.1.19', 'target' => 'CMMC:AC.L2-3.1.19', 'strength' => 'exact'],    // Encrypt CUI on mobile
    ['source' => 'NIST-171:3.1.20', 'target' => 'CMMC:AC.L1-3.1.20', 'strength' => 'exact'],    // External connections
    ['source' => 'NIST-171:3.1.21', 'target' => 'CMMC:AC.L2-3.1.21', 'strength' => 'exact'],    // Portable storage
    ['source' => 'NIST-171:3.1.22', 'target' => 'CMMC:AC.L1-3.1.22', 'strength' => 'exact'],    // Public information control

    // -- Awareness and Training (AT) --
    ['source' => 'NIST-171:3.2.1',  'target' => 'CMMC:AT.L2-3.2.1',  'strength' => 'exact'],    // Risk awareness
    ['source' => 'NIST-171:3.2.2',  'target' => 'CMMC:AT.L2-3.2.2',  'strength' => 'exact'],    // Role-based training
    ['source' => 'NIST-171:3.2.3',  'target' => 'CMMC:AT.L2-3.2.3',  'strength' => 'exact'],    // Insider threat awareness

    // -- Audit and Accountability (AU) --
    ['source' => 'NIST-171:3.3.1',  'target' => 'CMMC:AU.L2-3.3.1',  'strength' => 'exact'],    // System auditing
    ['source' => 'NIST-171:3.3.2',  'target' => 'CMMC:AU.L2-3.3.2',  'strength' => 'exact'],    // User accountability
    ['source' => 'NIST-171:3.3.3',  'target' => 'CMMC:AU.L2-3.3.3',  'strength' => 'exact'],    // Event review
    ['source' => 'NIST-171:3.3.4',  'target' => 'CMMC:AU.L2-3.3.4',  'strength' => 'exact'],    // Audit failure alerting
    ['source' => 'NIST-171:3.3.5',  'target' => 'CMMC:AU.L2-3.3.5',  'strength' => 'exact'],    // Audit correlation
    ['source' => 'NIST-171:3.3.6',  'target' => 'CMMC:AU.L2-3.3.6',  'strength' => 'exact'],    // Audit reduction/reporting
    ['source' => 'NIST-171:3.3.7',  'target' => 'CMMC:AU.L2-3.3.7',  'strength' => 'exact'],    // Authoritative time source
    ['source' => 'NIST-171:3.3.8',  'target' => 'CMMC:AU.L2-3.3.8',  'strength' => 'exact'],    // Audit protection
    ['source' => 'NIST-171:3.3.9',  'target' => 'CMMC:AU.L2-3.3.9',  'strength' => 'exact'],    // Audit management protection

    // -- Configuration Management (CM) --
    ['source' => 'NIST-171:3.4.1',  'target' => 'CMMC:CM.L2-3.4.1',  'strength' => 'exact'],    // System baselining
    ['source' => 'NIST-171:3.4.2',  'target' => 'CMMC:CM.L2-3.4.2',  'strength' => 'exact'],    // Security configuration enforcement
    ['source' => 'NIST-171:3.4.3',  'target' => 'CMMC:CM.L2-3.4.3',  'strength' => 'exact'],    // System change management
    ['source' => 'NIST-171:3.4.4',  'target' => 'CMMC:CM.L2-3.4.4',  'strength' => 'exact'],    // Security impact analysis
    ['source' => 'NIST-171:3.4.5',  'target' => 'CMMC:CM.L2-3.4.5',  'strength' => 'exact'],    // Access restrictions for change
    ['source' => 'NIST-171:3.4.6',  'target' => 'CMMC:CM.L2-3.4.6',  'strength' => 'exact'],    // Least functionality
    ['source' => 'NIST-171:3.4.7',  'target' => 'CMMC:CM.L2-3.4.7',  'strength' => 'exact'],    // Nonessential functionality
    ['source' => 'NIST-171:3.4.8',  'target' => 'CMMC:CM.L2-3.4.8',  'strength' => 'exact'],    // Application execution policy
    ['source' => 'NIST-171:3.4.9',  'target' => 'CMMC:CM.L2-3.4.9',  'strength' => 'exact'],    // User-installed software

    // -- Identification and Authentication (IA) --
    ['source' => 'NIST-171:3.5.1',  'target' => 'CMMC:IA.L1-3.5.1',  'strength' => 'exact'],    // Identification
    ['source' => 'NIST-171:3.5.2',  'target' => 'CMMC:IA.L1-3.5.2',  'strength' => 'exact'],    // Authentication
    ['source' => 'NIST-171:3.5.3',  'target' => 'CMMC:IA.L2-3.5.3',  'strength' => 'exact'],    // Multi-factor auth
    ['source' => 'NIST-171:3.5.4',  'target' => 'CMMC:IA.L2-3.5.4',  'strength' => 'exact'],    // Replay-resistant auth
    ['source' => 'NIST-171:3.5.5',  'target' => 'CMMC:IA.L2-3.5.5',  'strength' => 'exact'],    // Identifier management
    ['source' => 'NIST-171:3.5.6',  'target' => 'CMMC:IA.L2-3.5.6',  'strength' => 'exact'],    // Identifier inactivity
    ['source' => 'NIST-171:3.5.7',  'target' => 'CMMC:IA.L2-3.5.7',  'strength' => 'exact'],    // Password complexity
    ['source' => 'NIST-171:3.5.8',  'target' => 'CMMC:IA.L2-3.5.8',  'strength' => 'exact'],    // Password reuse
    ['source' => 'NIST-171:3.5.9',  'target' => 'CMMC:IA.L2-3.5.9',  'strength' => 'exact'],    // Temp passwords
    ['source' => 'NIST-171:3.5.10', 'target' => 'CMMC:IA.L2-3.5.10', 'strength' => 'exact'],    // Crypto-protected passwords
    ['source' => 'NIST-171:3.5.11', 'target' => 'CMMC:IA.L2-3.5.11', 'strength' => 'exact'],    // Obscured feedback

    // -- Incident Response (IR) --
    ['source' => 'NIST-171:3.6.1',  'target' => 'CMMC:IR.L2-3.6.1',  'strength' => 'exact'],    // Incident handling
    ['source' => 'NIST-171:3.6.2',  'target' => 'CMMC:IR.L2-3.6.2',  'strength' => 'exact'],    // Incident reporting
    ['source' => 'NIST-171:3.6.3',  'target' => 'CMMC:IR.L2-3.6.3',  'strength' => 'exact'],    // Incident response testing

    // -- Maintenance (MA) --
    ['source' => 'NIST-171:3.7.1',  'target' => 'CMMC:MA.L2-3.7.1',  'strength' => 'exact'],    // System maintenance
    ['source' => 'NIST-171:3.7.2',  'target' => 'CMMC:MA.L2-3.7.2',  'strength' => 'exact'],    // Maintenance control
    ['source' => 'NIST-171:3.7.3',  'target' => 'CMMC:MA.L2-3.7.3',  'strength' => 'exact'],    // Equipment sanitization
    ['source' => 'NIST-171:3.7.4',  'target' => 'CMMC:MA.L2-3.7.4',  'strength' => 'exact'],    // Media inspection
    ['source' => 'NIST-171:3.7.5',  'target' => 'CMMC:MA.L2-3.7.5',  'strength' => 'exact'],    // Nonlocal maintenance
    ['source' => 'NIST-171:3.7.6',  'target' => 'CMMC:MA.L2-3.7.6',  'strength' => 'exact'],    // Maintenance personnel

    // -- Media Protection (MP) --
    ['source' => 'NIST-171:3.8.1',  'target' => 'CMMC:MP.L2-3.8.1',  'strength' => 'exact'],    // Media protection
    ['source' => 'NIST-171:3.8.2',  'target' => 'CMMC:MP.L2-3.8.2',  'strength' => 'exact'],    // Media access
    ['source' => 'NIST-171:3.8.3',  'target' => 'CMMC:MP.L1-3.8.3',  'strength' => 'exact'],    // Media sanitization
    ['source' => 'NIST-171:3.8.4',  'target' => 'CMMC:MP.L2-3.8.4',  'strength' => 'exact'],    // Media marking
    ['source' => 'NIST-171:3.8.5',  'target' => 'CMMC:MP.L2-3.8.5',  'strength' => 'exact'],    // Media accountability
    ['source' => 'NIST-171:3.8.6',  'target' => 'CMMC:MP.L2-3.8.6',  'strength' => 'exact'],    // Portable storage encryption
    ['source' => 'NIST-171:3.8.7',  'target' => 'CMMC:MP.L2-3.8.7',  'strength' => 'exact'],    // Removable media
    ['source' => 'NIST-171:3.8.8',  'target' => 'CMMC:MP.L2-3.8.8',  'strength' => 'exact'],    // Shared media
    ['source' => 'NIST-171:3.8.9',  'target' => 'CMMC:MP.L2-3.8.9',  'strength' => 'exact'],    // Media backup protection

    // -- Personnel Security (PS) --
    ['source' => 'NIST-171:3.9.1',  'target' => 'CMMC:PS.L2-3.9.1',  'strength' => 'exact'],    // Position screening
    ['source' => 'NIST-171:3.9.2',  'target' => 'CMMC:PS.L2-3.9.2',  'strength' => 'exact'],    // Personnel termination/transfer

    // -- Physical Protection (PE) --
    ['source' => 'NIST-171:3.10.1', 'target' => 'CMMC:PE.L1-3.10.1', 'strength' => 'exact'],    // Physical access limits
    ['source' => 'NIST-171:3.10.2', 'target' => 'CMMC:PE.L1-3.10.2', 'strength' => 'exact'],    // Physical access monitoring
    ['source' => 'NIST-171:3.10.3', 'target' => 'CMMC:PE.L2-3.10.3', 'strength' => 'exact'],    // Escort visitors
    ['source' => 'NIST-171:3.10.4', 'target' => 'CMMC:PE.L2-3.10.4', 'strength' => 'exact'],    // Audit logs physical access
    ['source' => 'NIST-171:3.10.5', 'target' => 'CMMC:PE.L2-3.10.5', 'strength' => 'exact'],    // Physical access devices
    ['source' => 'NIST-171:3.10.6', 'target' => 'CMMC:PE.L2-3.10.6', 'strength' => 'exact'],    // Alternative work sites

    // -- Risk Assessment (RA) --
    ['source' => 'NIST-171:3.11.1', 'target' => 'CMMC:RA.L2-3.11.1', 'strength' => 'exact'],    // Risk assessments
    ['source' => 'NIST-171:3.11.2', 'target' => 'CMMC:RA.L2-3.11.2', 'strength' => 'exact'],    // Vulnerability scanning
    ['source' => 'NIST-171:3.11.3', 'target' => 'CMMC:RA.L2-3.11.3', 'strength' => 'exact'],    // Vulnerability remediation

    // -- Security Assessment (CA) --
    ['source' => 'NIST-171:3.12.1', 'target' => 'CMMC:CA.L2-3.12.1', 'strength' => 'exact'],    // Security control assessment
    ['source' => 'NIST-171:3.12.2', 'target' => 'CMMC:CA.L2-3.12.2', 'strength' => 'exact'],    // Plan of action
    ['source' => 'NIST-171:3.12.3', 'target' => 'CMMC:CA.L2-3.12.3', 'strength' => 'exact'],    // Continuous monitoring
    ['source' => 'NIST-171:3.12.4', 'target' => 'CMMC:CA.L2-3.12.4', 'strength' => 'exact'],    // System security plans

    // -- System and Communications Protection (SC) --
    ['source' => 'NIST-171:3.13.1',  'target' => 'CMMC:SC.L2-3.13.1',  'strength' => 'exact'],  // Boundary protection
    ['source' => 'NIST-171:3.13.2',  'target' => 'CMMC:SC.L2-3.13.2',  'strength' => 'exact'],  // Architectural designs
    ['source' => 'NIST-171:3.13.3',  'target' => 'CMMC:SC.L2-3.13.3',  'strength' => 'exact'],  // Role separation
    ['source' => 'NIST-171:3.13.4',  'target' => 'CMMC:SC.L2-3.13.4',  'strength' => 'exact'],  // Shared resources
    ['source' => 'NIST-171:3.13.5',  'target' => 'CMMC:SC.L1-3.13.5',  'strength' => 'exact'],  // Public-access system separation
    ['source' => 'NIST-171:3.13.6',  'target' => 'CMMC:SC.L2-3.13.6',  'strength' => 'exact'],  // Network communication by exception
    ['source' => 'NIST-171:3.13.7',  'target' => 'CMMC:SC.L2-3.13.7',  'strength' => 'exact'],  // Split tunneling
    ['source' => 'NIST-171:3.13.8',  'target' => 'CMMC:SC.L2-3.13.8',  'strength' => 'exact'],  // CUI encryption in transit
    ['source' => 'NIST-171:3.13.9',  'target' => 'CMMC:SC.L2-3.13.9',  'strength' => 'exact'],  // Session termination
    ['source' => 'NIST-171:3.13.10', 'target' => 'CMMC:SC.L2-3.13.10', 'strength' => 'exact'],  // Key management
    ['source' => 'NIST-171:3.13.11', 'target' => 'CMMC:SC.L2-3.13.11', 'strength' => 'exact'],  // FIPS-validated cryptography
    ['source' => 'NIST-171:3.13.12', 'target' => 'CMMC:SC.L2-3.13.12', 'strength' => 'exact'],  // Collaborative device control
    ['source' => 'NIST-171:3.13.13', 'target' => 'CMMC:SC.L2-3.13.13', 'strength' => 'exact'],  // Mobile code
    ['source' => 'NIST-171:3.13.14', 'target' => 'CMMC:SC.L2-3.13.14', 'strength' => 'exact'],  // VoIP
    ['source' => 'NIST-171:3.13.15', 'target' => 'CMMC:SC.L2-3.13.15', 'strength' => 'exact'],  // Session authenticity
    ['source' => 'NIST-171:3.13.16', 'target' => 'CMMC:SC.L2-3.13.16', 'strength' => 'exact'],  // CUI at rest encryption

    // -- System and Information Integrity (SI) --
    ['source' => 'NIST-171:3.14.1', 'target' => 'CMMC:SI.L1-3.14.1', 'strength' => 'exact'],    // Flaw remediation
    ['source' => 'NIST-171:3.14.2', 'target' => 'CMMC:SI.L1-3.14.2', 'strength' => 'exact'],    // Malicious code protection
    ['source' => 'NIST-171:3.14.3', 'target' => 'CMMC:SI.L2-3.14.3', 'strength' => 'exact'],    // Security alerts
    ['source' => 'NIST-171:3.14.4', 'target' => 'CMMC:SI.L1-3.14.4', 'strength' => 'exact'],    // Update malicious code protection
    ['source' => 'NIST-171:3.14.5', 'target' => 'CMMC:SI.L1-3.14.5', 'strength' => 'exact'],    // System/file scanning
    ['source' => 'NIST-171:3.14.6', 'target' => 'CMMC:SI.L2-3.14.6', 'strength' => 'exact'],    // Monitor for attacks
    ['source' => 'NIST-171:3.14.7', 'target' => 'CMMC:SI.L2-3.14.7', 'strength' => 'exact'],    // Identify unauthorized use

    // ═══════════════════════════════════════════════════════════════════
    //  4. SOC 2 <-> SOX / COSO 2013
    //     Common Criteria (CC1-CC5) map closely to COSO principles
    // ═══════════════════════════════════════════════════════════════════

    // -- CC1 (Control Environment) -> COSO Control Environment --
    ['source' => 'SOC2:CC1.1', 'target' => 'SOX:COSO-1',  'strength' => 'exact'],    // Integrity/ethics -> Principle 1
    ['source' => 'SOC2:CC1.2', 'target' => 'SOX:COSO-2',  'strength' => 'exact'],    // Board oversight -> Principle 2
    ['source' => 'SOC2:CC1.3', 'target' => 'SOX:COSO-3',  'strength' => 'exact'],    // Authority/responsibility -> Principle 3
    ['source' => 'SOC2:CC1.4', 'target' => 'SOX:COSO-4',  'strength' => 'exact'],    // Competence -> Principle 4
    ['source' => 'SOC2:CC1.5', 'target' => 'SOX:COSO-5',  'strength' => 'exact'],    // Accountability -> Principle 5

    // -- CC2 (Communication and Information) -> COSO Information and Communication --
    ['source' => 'SOC2:CC2.1', 'target' => 'SOX:COSO-13', 'strength' => 'exact'],    // Uses relevant info -> Principle 13
    ['source' => 'SOC2:CC2.2', 'target' => 'SOX:COSO-14', 'strength' => 'exact'],    // Internal communication -> Principle 14
    ['source' => 'SOC2:CC2.3', 'target' => 'SOX:COSO-15', 'strength' => 'exact'],    // External communication -> Principle 15

    // -- CC3 (Risk Assessment) -> COSO Risk Assessment --
    ['source' => 'SOC2:CC3.1', 'target' => 'SOX:COSO-6',  'strength' => 'exact'],    // Suitable objectives -> Principle 6
    ['source' => 'SOC2:CC3.2', 'target' => 'SOX:COSO-7',  'strength' => 'exact'],    // Identifies/analyzes risk -> Principle 7
    ['source' => 'SOC2:CC3.3', 'target' => 'SOX:COSO-8',  'strength' => 'exact'],    // Fraud risk -> Principle 8
    ['source' => 'SOC2:CC3.4', 'target' => 'SOX:COSO-9',  'strength' => 'exact'],    // Significant change -> Principle 9

    // -- CC4 (Monitoring Activities) -> COSO Monitoring --
    ['source' => 'SOC2:CC4.1', 'target' => 'SOX:COSO-16', 'strength' => 'exact'],    // Ongoing/separate evaluations -> Principle 16
    ['source' => 'SOC2:CC4.2', 'target' => 'SOX:COSO-17', 'strength' => 'exact'],    // Communicates deficiencies -> Principle 17

    // -- CC5 (Control Activities) -> COSO Control Activities --
    ['source' => 'SOC2:CC5.1', 'target' => 'SOX:COSO-10', 'strength' => 'exact'],    // Control activities -> Principle 10
    ['source' => 'SOC2:CC5.2', 'target' => 'SOX:COSO-11', 'strength' => 'exact'],    // Technology controls -> Principle 11
    ['source' => 'SOC2:CC5.3', 'target' => 'SOX:COSO-12', 'strength' => 'exact'],    // Policies/procedures -> Principle 12

    // -- Additional SOC 2 -> SOX mappings --
    ['source' => 'SOC2:CC6.1', 'target' => 'SOX:COSO-10', 'strength' => 'partial'],  // Logical/physical access -> Control activities
    ['source' => 'SOC2:CC6.2', 'target' => 'SOX:COSO-10', 'strength' => 'partial'],  // Access provisioning -> Control activities
    ['source' => 'SOC2:CC6.3', 'target' => 'SOX:COSO-10', 'strength' => 'partial'],  // Access removal -> Control activities
    ['source' => 'SOC2:CC7.1', 'target' => 'SOX:COSO-16', 'strength' => 'partial'],  // Detect changes -> Monitoring
    ['source' => 'SOC2:CC7.2', 'target' => 'SOX:COSO-16', 'strength' => 'partial'],  // Monitor components -> Monitoring
    ['source' => 'SOC2:CC7.3', 'target' => 'SOX:COSO-7',  'strength' => 'partial'],  // Evaluates events -> Risk analysis
    ['source' => 'SOC2:CC7.4', 'target' => 'SOX:COSO-17', 'strength' => 'partial'],  // Responds -> Deficiency communication
    ['source' => 'SOC2:CC7.5', 'target' => 'SOX:COSO-17', 'strength' => 'partial'],  // Remediates -> Deficiency communication
    ['source' => 'SOC2:CC8.1', 'target' => 'SOX:COSO-12', 'strength' => 'partial'],  // Change management -> Policies/procedures
    ['source' => 'SOC2:CC9.1', 'target' => 'SOX:COSO-7',  'strength' => 'partial'],  // Risk mitigation -> Risk identification
    ['source' => 'SOC2:CC9.2', 'target' => 'SOX:COSO-9',  'strength' => 'partial'],  // Vendor management -> Significant change

    // ═══════════════════════════════════════════════════════════════════
    //  5. NIST CSF <-> SOC 2
    //     Mapping CSF functions/subcategories to Trust Services Criteria
    // ═══════════════════════════════════════════════════════════════════

    // -- Govern (GV) -> CC1-CC2 --
    ['source' => 'NIST-CSF:GV.OC-01', 'target' => 'SOC2:CC1.1', 'strength' => 'partial'],   // Org context -> Integrity/ethics
    ['source' => 'NIST-CSF:GV.OC-02', 'target' => 'SOC2:CC1.1', 'strength' => 'partial'],   // Legal obligations -> Integrity/ethics
    ['source' => 'NIST-CSF:GV.PO-01', 'target' => 'SOC2:CC5.3', 'strength' => 'strong'],    // Governance policies -> Policies/procedures
    ['source' => 'NIST-CSF:GV.PO-02', 'target' => 'SOC2:CC2.2', 'strength' => 'strong'],    // Policy communication -> Internal communication
    ['source' => 'NIST-CSF:GV.RR-01', 'target' => 'SOC2:CC1.3', 'strength' => 'strong'],    // Roles -> Structure/authority
    ['source' => 'NIST-CSF:GV.RR-02', 'target' => 'SOC2:CC1.3', 'strength' => 'partial'],   // Roles communicated -> Structure
    ['source' => 'NIST-CSF:GV.RR-04', 'target' => 'SOC2:CC1.4', 'strength' => 'strong'],    // Awareness/training -> Competence
    ['source' => 'NIST-CSF:GV.SC-01', 'target' => 'SOC2:CC9.2', 'strength' => 'strong'],    // Supply chain RM -> Vendor risk
    ['source' => 'NIST-CSF:GV.SC-02', 'target' => 'SOC2:CC9.2', 'strength' => 'strong'],    // Supply chain requirements -> Vendor risk
    ['source' => 'NIST-CSF:GV.SC-03', 'target' => 'SOC2:CC9.2', 'strength' => 'partial'],   // Supply chain risk -> Vendor risk
    ['source' => 'NIST-CSF:GV.SC-04', 'target' => 'SOC2:CC9.2', 'strength' => 'partial'],   // Supplier monitoring -> Vendor monitoring

    // -- Identify (ID) -> CC3, CC6 --
    ['source' => 'NIST-CSF:ID.AM-01', 'target' => 'SOC2:CC6.1', 'strength' => 'partial'],   // Hardware inventory -> Access controls
    ['source' => 'NIST-CSF:ID.AM-02', 'target' => 'SOC2:CC6.1', 'strength' => 'partial'],   // Software inventory -> Access controls
    ['source' => 'NIST-CSF:ID.RA-01', 'target' => 'SOC2:CC3.2', 'strength' => 'strong'],    // Vulnerability ID -> Risk identification
    ['source' => 'NIST-CSF:ID.RA-02', 'target' => 'SOC2:CC3.2', 'strength' => 'strong'],    // Threat intelligence -> Risk identification
    ['source' => 'NIST-CSF:ID.RA-03', 'target' => 'SOC2:CC3.2', 'strength' => 'partial'],   // Risk likelihood -> Risk identification
    ['source' => 'NIST-CSF:ID.RA-04', 'target' => 'SOC2:CC3.2', 'strength' => 'partial'],   // Risk impact -> Risk identification
    ['source' => 'NIST-CSF:ID.IM-01', 'target' => 'SOC2:CC4.1', 'strength' => 'strong'],    // Improvement from evaluations -> Monitoring

    // -- Protect (PR) -> CC5, CC6 --
    ['source' => 'NIST-CSF:PR.AA-01', 'target' => 'SOC2:CC6.1', 'strength' => 'strong'],    // Identity mgmt -> Logical/physical access
    ['source' => 'NIST-CSF:PR.AA-02', 'target' => 'SOC2:CC6.1', 'strength' => 'strong'],    // Authentication -> Logical access
    ['source' => 'NIST-CSF:PR.AA-03', 'target' => 'SOC2:CC6.1', 'strength' => 'strong'],    // Access enforcement -> Logical access
    ['source' => 'NIST-CSF:PR.AA-05', 'target' => 'SOC2:CC6.2', 'strength' => 'strong'],    // Access review -> Access provisioning
    ['source' => 'NIST-CSF:PR.DS-01', 'target' => 'SOC2:CC6.1', 'strength' => 'partial'],   // Data at rest -> Access controls
    ['source' => 'NIST-CSF:PR.DS-02', 'target' => 'SOC2:CC6.7', 'strength' => 'strong'],    // Data in transit -> Transmission security
    ['source' => 'NIST-CSF:PR.PS-01', 'target' => 'SOC2:CC6.8', 'strength' => 'partial'],   // Platform security -> Controls against threats
    ['source' => 'NIST-CSF:PR.IR-01', 'target' => 'SOC2:A1.1',  'strength' => 'strong'],    // Resilience -> Recovery mechanisms
    ['source' => 'NIST-CSF:PR.IR-02', 'target' => 'SOC2:A1.2',  'strength' => 'strong'],    // Resilience -> Recovery objectives

    // -- Detect (DE) -> CC7 --
    ['source' => 'NIST-CSF:DE.CM-01', 'target' => 'SOC2:CC7.2', 'strength' => 'strong'],    // Continuous monitoring -> Monitors components
    ['source' => 'NIST-CSF:DE.AE-02', 'target' => 'SOC2:CC7.2', 'strength' => 'strong'],    // Anomaly analysis -> Monitors components
    ['source' => 'NIST-CSF:DE.AE-03', 'target' => 'SOC2:CC7.3', 'strength' => 'strong'],    // Event analysis -> Evaluates events

    // -- Respond (RS) -> CC7 --
    ['source' => 'NIST-CSF:RS.MA-01', 'target' => 'SOC2:CC7.3', 'strength' => 'strong'],    // Incident management -> Response to issues
    ['source' => 'NIST-CSF:RS.MA-02', 'target' => 'SOC2:CC7.4', 'strength' => 'strong'],    // Incident response -> Responds to incidents
    ['source' => 'NIST-CSF:RS.MA-03', 'target' => 'SOC2:CC7.4', 'strength' => 'partial'],   // Incident reporting -> Responds to incidents

    // -- Recover (RC) -> A1 --
    ['source' => 'NIST-CSF:RC.RP-01', 'target' => 'SOC2:A1.2',  'strength' => 'strong'],    // Recovery planning -> Recovery mechanisms
    ['source' => 'NIST-CSF:RC.RP-02', 'target' => 'SOC2:A1.2',  'strength' => 'strong'],    // Recovery execution -> Recovery
    ['source' => 'NIST-CSF:RC.RP-03', 'target' => 'SOC2:A1.3',  'strength' => 'partial'],   // Recovery communication -> Recovery testing

    // ═══════════════════════════════════════════════════════════════════
    //  6. ISO 27001 <-> PCI DSS 4.0
    //     Mapping relevant ISO controls to PCI DSS requirements
    // ═══════════════════════════════════════════════════════════════════

    // -- Access control --
    ['source' => 'ISO27001:A.5.15', 'target' => 'PCI-DSS:7.2',   'strength' => 'strong'],   // Access control -> Restrict access by need to know
    ['source' => 'ISO27001:A.5.16', 'target' => 'PCI-DSS:8.2.1', 'strength' => 'strong'],   // Identity management -> Unique IDs
    ['source' => 'ISO27001:A.5.17', 'target' => 'PCI-DSS:8.3',   'strength' => 'strong'],   // Authentication info -> Strong authentication
    ['source' => 'ISO27001:A.5.18', 'target' => 'PCI-DSS:7.2.1', 'strength' => 'strong'],   // Access rights -> Access model
    ['source' => 'ISO27001:A.5.18', 'target' => 'PCI-DSS:7.2.4', 'strength' => 'strong'],   // Access rights -> Review accounts
    ['source' => 'ISO27001:A.5.3',  'target' => 'PCI-DSS:7.2.2', 'strength' => 'partial'],  // Segregation of duties -> Role-based access

    // -- Network and system security --
    ['source' => 'ISO27001:A.8.20', 'target' => 'PCI-DSS:1.2',   'strength' => 'strong'],   // Network security -> NSC configuration
    ['source' => 'ISO27001:A.8.22', 'target' => 'PCI-DSS:1.3',   'strength' => 'strong'],   // Network segregation -> CDE access restriction
    ['source' => 'ISO27001:A.8.22', 'target' => 'PCI-DSS:1.4',   'strength' => 'partial'],  // Network segregation -> Trust boundaries
    ['source' => 'ISO27001:A.8.9',  'target' => 'PCI-DSS:2.2',   'strength' => 'strong'],   // Configuration management -> Secure configurations
    ['source' => 'ISO27001:A.8.9',  'target' => 'PCI-DSS:2.2.1', 'strength' => 'strong'],   // Configuration management -> Config standards

    // -- Cryptography and data protection --
    ['source' => 'ISO27001:A.8.24', 'target' => 'PCI-DSS:4.2.1', 'strength' => 'strong'],   // Cryptography -> Strong crypto during transmission
    ['source' => 'ISO27001:A.8.24', 'target' => 'PCI-DSS:3.5.1', 'strength' => 'partial'],  // Cryptography -> PAN rendered unreadable
    ['source' => 'ISO27001:A.5.12', 'target' => 'PCI-DSS:3.2',   'strength' => 'partial'],  // Classification -> Minimize data storage
    ['source' => 'ISO27001:A.8.11', 'target' => 'PCI-DSS:3.4.1', 'strength' => 'strong'],   // Data masking -> PAN masking
    ['source' => 'ISO27001:A.8.10', 'target' => 'PCI-DSS:3.2.1', 'strength' => 'partial'],  // Information deletion -> Data retention/disposal

    // -- Vulnerability management --
    ['source' => 'ISO27001:A.8.8',  'target' => 'PCI-DSS:6.3',   'strength' => 'strong'],   // Vulnerability mgmt -> Identify/address vulnerabilities
    ['source' => 'ISO27001:A.8.8',  'target' => 'PCI-DSS:6.3.3', 'strength' => 'strong'],   // Vulnerability mgmt -> Apply patches
    ['source' => 'ISO27001:A.8.8',  'target' => 'PCI-DSS:11.3',  'strength' => 'strong'],   // Vulnerability mgmt -> Vulnerability scanning
    ['source' => 'ISO27001:A.8.7',  'target' => 'PCI-DSS:5.2',   'strength' => 'strong'],   // Malware protection -> Anti-malware
    ['source' => 'ISO27001:A.8.7',  'target' => 'PCI-DSS:5.3',   'strength' => 'strong'],   // Malware protection -> Anti-malware maintained

    // -- Secure development --
    ['source' => 'ISO27001:A.8.25', 'target' => 'PCI-DSS:6.2',   'strength' => 'strong'],   // Secure dev lifecycle -> Secure development
    ['source' => 'ISO27001:A.8.28', 'target' => 'PCI-DSS:6.2.4', 'strength' => 'strong'],   // Secure coding -> Prevent software attacks
    ['source' => 'ISO27001:A.8.29', 'target' => 'PCI-DSS:6.2.3', 'strength' => 'strong'],   // Security testing -> Code review
    ['source' => 'ISO27001:A.8.32', 'target' => 'PCI-DSS:6.5.1', 'strength' => 'strong'],   // Change management -> Change control procedures
    ['source' => 'ISO27001:A.8.31', 'target' => 'PCI-DSS:6.5.1', 'strength' => 'partial'],  // Separation of environments -> Change control

    // -- Logging and monitoring --
    ['source' => 'ISO27001:A.8.15', 'target' => 'PCI-DSS:10.2',  'strength' => 'strong'],   // Logging -> Audit logs
    ['source' => 'ISO27001:A.8.15', 'target' => 'PCI-DSS:10.2.1','strength' => 'strong'],   // Logging -> Active audit logs
    ['source' => 'ISO27001:A.8.16', 'target' => 'PCI-DSS:10.4',  'strength' => 'strong'],   // Monitoring -> Review audit logs
    ['source' => 'ISO27001:A.8.15', 'target' => 'PCI-DSS:10.3',  'strength' => 'partial'],  // Logging -> Protect audit logs
    ['source' => 'ISO27001:A.8.17', 'target' => 'PCI-DSS:10.6.1','strength' => 'strong'],   // Clock synchronization -> Time synchronization

    // -- Intrusion detection and testing --
    ['source' => 'ISO27001:A.8.16', 'target' => 'PCI-DSS:11.5.1','strength' => 'strong'],   // Monitoring -> IDS/IPS
    ['source' => 'ISO27001:A.8.16', 'target' => 'PCI-DSS:11.5.2','strength' => 'partial'],  // Monitoring -> File integrity monitoring
    ['source' => 'ISO27001:A.8.8',  'target' => 'PCI-DSS:11.4',  'strength' => 'partial'],  // Vulnerability mgmt -> Penetration testing

    // -- Physical security --
    ['source' => 'ISO27001:A.7.1',  'target' => 'PCI-DSS:9.2',   'strength' => 'strong'],   // Physical perimeters -> Physical access controls
    ['source' => 'ISO27001:A.7.2',  'target' => 'PCI-DSS:9.2.1', 'strength' => 'strong'],   // Physical entry -> Facility entry controls
    ['source' => 'ISO27001:A.7.4',  'target' => 'PCI-DSS:9.2',   'strength' => 'partial'],  // Physical monitoring -> Physical access
    ['source' => 'ISO27001:A.7.10', 'target' => 'PCI-DSS:9.4.1', 'strength' => 'strong'],   // Storage media -> Media physically secured

    // -- Incident response --
    ['source' => 'ISO27001:A.5.24', 'target' => 'PCI-DSS:12.10.1','strength' => 'strong'],  // Incident planning -> Incident response plan
    ['source' => 'ISO27001:A.5.26', 'target' => 'PCI-DSS:12.10',  'strength' => 'strong'],  // Response to incidents -> Incident response
    ['source' => 'ISO27001:A.5.27', 'target' => 'PCI-DSS:12.10.6','strength' => 'strong'],  // Learning from incidents -> Lessons learned

    // -- Policies and awareness --
    ['source' => 'ISO27001:A.5.1',  'target' => 'PCI-DSS:12.1',   'strength' => 'strong'],  // Policies -> Information security policy
    ['source' => 'ISO27001:A.5.1',  'target' => 'PCI-DSS:12.1.1', 'strength' => 'strong'],  // Policies -> Policy established
    ['source' => 'ISO27001:A.6.3',  'target' => 'PCI-DSS:12.6',   'strength' => 'strong'],  // Awareness/training -> Security awareness
    ['source' => 'ISO27001:A.6.3',  'target' => 'PCI-DSS:12.6.3', 'strength' => 'strong'],  // Awareness/training -> Annual training
    ['source' => 'ISO27001:A.6.1',  'target' => 'PCI-DSS:12.7',   'strength' => 'strong'],  // Screening -> Personnel screening

    // -- Supplier/third-party --
    ['source' => 'ISO27001:A.5.19', 'target' => 'PCI-DSS:12.8',   'strength' => 'strong'],  // Supplier security -> TPSP management
    ['source' => 'ISO27001:A.5.20', 'target' => 'PCI-DSS:12.8.2', 'strength' => 'strong'],  // Supplier agreements -> Written agreements
    ['source' => 'ISO27001:A.5.22', 'target' => 'PCI-DSS:12.8.4', 'strength' => 'strong'],  // Supplier monitoring -> Monitor TPSP compliance

    // -- Privileged access and MFA --
    ['source' => 'ISO27001:A.8.2',  'target' => 'PCI-DSS:8.4',   'strength' => 'strong'],   // Privileged access -> MFA
    ['source' => 'ISO27001:A.8.5',  'target' => 'PCI-DSS:8.3.1', 'strength' => 'strong'],   // Secure auth -> Authentication factor required
    ['source' => 'ISO27001:A.8.5',  'target' => 'PCI-DSS:8.3.2', 'strength' => 'strong'],   // Secure auth -> Crypto for auth factors

    // -- Continuity and risk assessment --
    ['source' => 'ISO27001:A.5.29', 'target' => 'PCI-DSS:12.10.1','strength' => 'partial'],  // BC planning -> Incident response plan
    ['source' => 'ISO27001:A.5.7',  'target' => 'PCI-DSS:12.3',   'strength' => 'partial'],  // Threat intel -> Risk management
    ['source' => 'ISO27001:A.5.2',  'target' => 'PCI-DSS:12.1.3', 'strength' => 'strong'],   // Roles -> Roles defined in policy

    // -- Web application protection --
    ['source' => 'ISO27001:A.8.26', 'target' => 'PCI-DSS:6.4',   'strength' => 'partial'],  // App security requirements -> Protect web apps
    ['source' => 'ISO27001:A.8.23', 'target' => 'PCI-DSS:6.4.2', 'strength' => 'partial'],  // Web filtering -> WAF

    // -- Wireless --
    ['source' => 'ISO27001:A.8.20', 'target' => 'PCI-DSS:1.3.3', 'strength' => 'partial'],  // Network security -> NSCs for wireless
    ['source' => 'ISO27001:A.8.20', 'target' => 'PCI-DSS:11.2.1','strength' => 'partial'],  // Network security -> Wireless AP testing

    // -- Default passwords --
    ['source' => 'ISO27001:A.8.5',  'target' => 'PCI-DSS:2.2.2', 'strength' => 'partial'],  // Secure auth -> Vendor default accounts managed
];
