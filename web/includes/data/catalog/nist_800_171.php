<?php
/**
 * NIST SP 800-171 Rev 2 — Protecting Controlled Unclassified Information
 * Framework catalog template for GRC Compliance Engine
 *
 * All 110 security requirements across 14 families
 */
return [
    'code' => 'NIST-171',
    'name' => 'NIST SP 800-171 Rev 2',
    'version' => 'Rev 2',
    'description' => 'Protecting Controlled Unclassified Information in Nonfederal Systems and Organizations',
    'category' => 'government',
    'url' => 'https://csrc.nist.gov/publications/detail/sp/800-171/rev-2/final',
    'requirements' => [

        // =====================================================================
        // 3.1 ACCESS CONTROL (22 requirements)
        // =====================================================================
        ['ref' => '3.1.1', 'title' => 'Limit system access to authorized users', 'description' => 'Limit system access to authorized users, processes acting on behalf of authorized users, and devices (including other systems).', 'category' => 'Access Control', 'sort_order' => 1],
        ['ref' => '3.1.2', 'title' => 'Limit system access to authorized functions', 'description' => 'Limit system access to the types of transactions and functions that authorized users are permitted to execute.', 'category' => 'Access Control', 'sort_order' => 2],
        ['ref' => '3.1.3', 'title' => 'Control CUI flow', 'description' => 'Control the flow of CUI in accordance with approved authorizations.', 'category' => 'Access Control', 'sort_order' => 3],
        ['ref' => '3.1.4', 'title' => 'Separate duties of individuals', 'description' => 'Separate the duties of individuals to reduce the risk of malevolent activity without collusion.', 'category' => 'Access Control', 'sort_order' => 4],
        ['ref' => '3.1.5', 'title' => 'Employ least privilege', 'description' => 'Employ the principle of least privilege, including for specific security functions and privileged accounts.', 'category' => 'Access Control', 'sort_order' => 5],
        ['ref' => '3.1.6', 'title' => 'Use non-privileged accounts', 'description' => 'Use non-privileged accounts or roles when accessing nonsecurity functions.', 'category' => 'Access Control', 'sort_order' => 6],
        ['ref' => '3.1.7', 'title' => 'Prevent non-privileged users from executing privileged functions', 'description' => 'Prevent non-privileged users from executing privileged functions and capture the execution of such functions in audit logs.', 'category' => 'Access Control', 'sort_order' => 7],
        ['ref' => '3.1.8', 'title' => 'Limit unsuccessful logon attempts', 'description' => 'Limit unsuccessful logon attempts.', 'category' => 'Access Control', 'sort_order' => 8],
        ['ref' => '3.1.9', 'title' => 'Provide privacy and security notices', 'description' => 'Provide privacy and security notices consistent with applicable CUI rules.', 'category' => 'Access Control', 'sort_order' => 9],
        ['ref' => '3.1.10', 'title' => 'Use session lock', 'description' => 'Use session lock with pattern-hiding displays to prevent access and viewing of data after a period of inactivity.', 'category' => 'Access Control', 'sort_order' => 10],
        ['ref' => '3.1.11', 'title' => 'Terminate sessions', 'description' => 'Terminate (automatically) a user session after a defined condition.', 'category' => 'Access Control', 'sort_order' => 11],
        ['ref' => '3.1.12', 'title' => 'Control remote access', 'description' => 'Monitor and control remote access sessions.', 'category' => 'Access Control', 'sort_order' => 12],
        ['ref' => '3.1.13', 'title' => 'Employ cryptographic mechanisms for remote access', 'description' => 'Employ cryptographic mechanisms to protect the confidentiality of remote access sessions.', 'category' => 'Access Control', 'sort_order' => 13],
        ['ref' => '3.1.14', 'title' => 'Route remote access via managed access control points', 'description' => 'Route remote access via managed access control points.', 'category' => 'Access Control', 'sort_order' => 14],
        ['ref' => '3.1.15', 'title' => 'Authorize remote execution', 'description' => 'Authorize remote execution of privileged commands and remote access to security-relevant information.', 'category' => 'Access Control', 'sort_order' => 15],
        ['ref' => '3.1.16', 'title' => 'Authorize wireless access', 'description' => 'Authorize wireless access prior to allowing such connections.', 'category' => 'Access Control', 'sort_order' => 16],
        ['ref' => '3.1.17', 'title' => 'Protect wireless access using authentication and encryption', 'description' => 'Protect wireless access using authentication and encryption.', 'category' => 'Access Control', 'sort_order' => 17],
        ['ref' => '3.1.18', 'title' => 'Control connection of mobile devices', 'description' => 'Control connection of mobile devices.', 'category' => 'Access Control', 'sort_order' => 18],
        ['ref' => '3.1.19', 'title' => 'Encrypt CUI on mobile devices', 'description' => 'Encrypt CUI on mobile devices and mobile computing platforms.', 'category' => 'Access Control', 'sort_order' => 19],
        ['ref' => '3.1.20', 'title' => 'Verify and control connections to external systems', 'description' => 'Verify and control/limit connections to and use of external systems.', 'category' => 'Access Control', 'sort_order' => 20],
        ['ref' => '3.1.21', 'title' => 'Limit use of portable storage devices', 'description' => 'Limit use of portable storage devices on external systems.', 'category' => 'Access Control', 'sort_order' => 21],
        ['ref' => '3.1.22', 'title' => 'Control CUI posted on publicly accessible systems', 'description' => 'Control CUI posted or processed on publicly accessible systems.', 'category' => 'Access Control', 'sort_order' => 22],

        // =====================================================================
        // 3.2 AWARENESS AND TRAINING (3 requirements)
        // =====================================================================
        ['ref' => '3.2.1', 'title' => 'Ensure security awareness', 'description' => 'Ensure that managers, systems administrators, and users of organizational systems are made aware of the security risks associated with their activities and of the applicable policies, standards, and procedures related to the security of those systems.', 'category' => 'Awareness and Training', 'sort_order' => 23],
        ['ref' => '3.2.2', 'title' => 'Ensure training on information security', 'description' => 'Ensure that personnel are trained to carry out their assigned information security-related duties and responsibilities.', 'category' => 'Awareness and Training', 'sort_order' => 24],
        ['ref' => '3.2.3', 'title' => 'Provide insider threat awareness', 'description' => 'Provide security awareness training on recognizing and reporting potential indicators of insider threat.', 'category' => 'Awareness and Training', 'sort_order' => 25],

        // =====================================================================
        // 3.3 AUDIT AND ACCOUNTABILITY (9 requirements)
        // =====================================================================
        ['ref' => '3.3.1', 'title' => 'Create and retain system audit logs', 'description' => 'Create and retain system audit logs and records to the extent needed to enable the monitoring, analysis, investigation, and reporting of unlawful or unauthorized system activity.', 'category' => 'Audit and Accountability', 'sort_order' => 26],
        ['ref' => '3.3.2', 'title' => 'Ensure actions are uniquely traced to users', 'description' => 'Ensure that the actions of individual system users can be uniquely traced to those users so they can be held accountable for their actions.', 'category' => 'Audit and Accountability', 'sort_order' => 27],
        ['ref' => '3.3.3', 'title' => 'Review and update logged events', 'description' => 'Review and update logged events.', 'category' => 'Audit and Accountability', 'sort_order' => 28],
        ['ref' => '3.3.4', 'title' => 'Alert on audit logging process failures', 'description' => 'Alert in the event of an audit logging process failure.', 'category' => 'Audit and Accountability', 'sort_order' => 29],
        ['ref' => '3.3.5', 'title' => 'Correlate audit record review and reporting', 'description' => 'Correlate audit record review, analysis, and reporting processes for investigation and response to indications of unlawful, unauthorized, suspicious, or unusual activity.', 'category' => 'Audit and Accountability', 'sort_order' => 30],
        ['ref' => '3.3.6', 'title' => 'Provide audit record reduction and report generation', 'description' => 'Provide audit record reduction and report generation to support on-demand analysis and reporting.', 'category' => 'Audit and Accountability', 'sort_order' => 31],
        ['ref' => '3.3.7', 'title' => 'Provide system capability to compare and synchronize clocks', 'description' => 'Provide a system capability that compares and synchronizes internal system clocks with an authoritative source to generate time stamps for audit records.', 'category' => 'Audit and Accountability', 'sort_order' => 32],
        ['ref' => '3.3.8', 'title' => 'Protect audit information', 'description' => 'Protect audit information and audit logging tools from unauthorized access, modification, and deletion.', 'category' => 'Audit and Accountability', 'sort_order' => 33],
        ['ref' => '3.3.9', 'title' => 'Limit management of audit logging functionality', 'description' => 'Limit management of audit logging functionality to a subset of privileged users.', 'category' => 'Audit and Accountability', 'sort_order' => 34],

        // =====================================================================
        // 3.4 CONFIGURATION MANAGEMENT (9 requirements)
        // =====================================================================
        ['ref' => '3.4.1', 'title' => 'Establish and maintain baseline configurations', 'description' => 'Establish and maintain baseline configurations and inventories of organizational systems (including hardware, software, firmware, and documentation) throughout the respective system development life cycles.', 'category' => 'Configuration Management', 'sort_order' => 35],
        ['ref' => '3.4.2', 'title' => 'Establish and enforce security configuration settings', 'description' => 'Establish and enforce security configuration settings for information technology products employed in organizational systems.', 'category' => 'Configuration Management', 'sort_order' => 36],
        ['ref' => '3.4.3', 'title' => 'Track, review, and control changes', 'description' => 'Track, review, approve or disapprove, and log changes to organizational systems.', 'category' => 'Configuration Management', 'sort_order' => 37],
        ['ref' => '3.4.4', 'title' => 'Analyze security impact of changes', 'description' => 'Analyze the security impact of changes prior to implementation.', 'category' => 'Configuration Management', 'sort_order' => 38],
        ['ref' => '3.4.5', 'title' => 'Define and enforce physical and logical access restrictions', 'description' => 'Define, document, approve, and enforce physical and logical access restrictions associated with changes to organizational systems.', 'category' => 'Configuration Management', 'sort_order' => 39],
        ['ref' => '3.4.6', 'title' => 'Employ least functionality', 'description' => 'Employ the principle of least functionality by configuring organizational systems to provide only essential capabilities.', 'category' => 'Configuration Management', 'sort_order' => 40],
        ['ref' => '3.4.7', 'title' => 'Restrict, disable, or prevent nonessential programs', 'description' => 'Restrict, disable, or prevent the use of nonessential programs, functions, ports, protocols, and services.', 'category' => 'Configuration Management', 'sort_order' => 41],
        ['ref' => '3.4.8', 'title' => 'Apply deny-by-exception policy', 'description' => 'Apply deny-by-exception (blacklisting) policy to prevent the use of unauthorized software or deny-all, permit-by-exception (whitelisting) policy to allow the execution of authorized software.', 'category' => 'Configuration Management', 'sort_order' => 42],
        ['ref' => '3.4.9', 'title' => 'Control and monitor user-installed software', 'description' => 'Control and monitor user-installed software.', 'category' => 'Configuration Management', 'sort_order' => 43],

        // =====================================================================
        // 3.5 IDENTIFICATION AND AUTHENTICATION (11 requirements)
        // =====================================================================
        ['ref' => '3.5.1', 'title' => 'Identify system users and processes', 'description' => 'Identify system users, processes acting on behalf of users, and devices.', 'category' => 'Identification and Authentication', 'sort_order' => 44],
        ['ref' => '3.5.2', 'title' => 'Authenticate users, processes, and devices', 'description' => 'Authenticate (or verify) the identities of users, processes, or devices, as a prerequisite to allowing access to organizational systems.', 'category' => 'Identification and Authentication', 'sort_order' => 45],
        ['ref' => '3.5.3', 'title' => 'Use multifactor authentication', 'description' => 'Use multifactor authentication for local and network access to privileged accounts and for network access to non-privileged accounts.', 'category' => 'Identification and Authentication', 'sort_order' => 46],
        ['ref' => '3.5.4', 'title' => 'Employ replay-resistant authentication', 'description' => 'Employ replay-resistant authentication mechanisms for network access to privileged and non-privileged accounts.', 'category' => 'Identification and Authentication', 'sort_order' => 47],
        ['ref' => '3.5.5', 'title' => 'Prevent reuse of identifiers', 'description' => 'Prevent reuse of identifiers for a defined period.', 'category' => 'Identification and Authentication', 'sort_order' => 48],
        ['ref' => '3.5.6', 'title' => 'Disable identifiers after inactivity', 'description' => 'Disable identifiers after a defined period of inactivity.', 'category' => 'Identification and Authentication', 'sort_order' => 49],
        ['ref' => '3.5.7', 'title' => 'Enforce minimum password complexity', 'description' => 'Enforce a minimum password complexity and change of characters when new passwords are created.', 'category' => 'Identification and Authentication', 'sort_order' => 50],
        ['ref' => '3.5.8', 'title' => 'Prohibit password reuse', 'description' => 'Prohibit password reuse for a specified number of generations.', 'category' => 'Identification and Authentication', 'sort_order' => 51],
        ['ref' => '3.5.9', 'title' => 'Allow temporary password use for logons', 'description' => 'Allow temporary password use for system logons with an immediate change to a permanent password.', 'category' => 'Identification and Authentication', 'sort_order' => 52],
        ['ref' => '3.5.10', 'title' => 'Store and transmit only cryptographically-protected passwords', 'description' => 'Store and transmit only cryptographically-protected passwords.', 'category' => 'Identification and Authentication', 'sort_order' => 53],
        ['ref' => '3.5.11', 'title' => 'Obscure feedback of authentication information', 'description' => 'Obscure feedback of authentication information.', 'category' => 'Identification and Authentication', 'sort_order' => 54],

        // =====================================================================
        // 3.6 INCIDENT RESPONSE (3 requirements)
        // =====================================================================
        ['ref' => '3.6.1', 'title' => 'Establish incident handling capability', 'description' => 'Establish an operational incident-handling capability for organizational systems that includes preparation, detection, analysis, containment, recovery, and user response activities.', 'category' => 'Incident Response', 'sort_order' => 55],
        ['ref' => '3.6.2', 'title' => 'Track, document, and report incidents', 'description' => 'Track, document, and report incidents to designated officials and/or authorities both internal and external to the organization.', 'category' => 'Incident Response', 'sort_order' => 56],
        ['ref' => '3.6.3', 'title' => 'Test incident response capability', 'description' => 'Test the organizational incident response capability.', 'category' => 'Incident Response', 'sort_order' => 57],

        // =====================================================================
        // 3.7 MAINTENANCE (6 requirements)
        // =====================================================================
        ['ref' => '3.7.1', 'title' => 'Perform maintenance on organizational systems', 'description' => 'Perform maintenance on organizational systems.', 'category' => 'Maintenance', 'sort_order' => 58],
        ['ref' => '3.7.2', 'title' => 'Provide controls on maintenance tools', 'description' => 'Provide controls on the tools, techniques, mechanisms, and personnel used to conduct system maintenance.', 'category' => 'Maintenance', 'sort_order' => 59],
        ['ref' => '3.7.3', 'title' => 'Ensure equipment removed for maintenance is sanitized', 'description' => 'Ensure equipment removed for off-site maintenance is sanitized of any CUI.', 'category' => 'Maintenance', 'sort_order' => 60],
        ['ref' => '3.7.4', 'title' => 'Check media containing diagnostic programs', 'description' => 'Check media containing diagnostic and test programs for malicious code before the media are used in organizational systems.', 'category' => 'Maintenance', 'sort_order' => 61],
        ['ref' => '3.7.5', 'title' => 'Require multifactor authentication for nonlocal maintenance', 'description' => 'Require multifactor authentication to establish nonlocal maintenance sessions via external network connections and terminate such connections when nonlocal maintenance is complete.', 'category' => 'Maintenance', 'sort_order' => 62],
        ['ref' => '3.7.6', 'title' => 'Supervise maintenance activities', 'description' => 'Supervise the maintenance activities of maintenance personnel without required access authorization.', 'category' => 'Maintenance', 'sort_order' => 63],

        // =====================================================================
        // 3.8 MEDIA PROTECTION (9 requirements)
        // =====================================================================
        ['ref' => '3.8.1', 'title' => 'Protect system media containing CUI', 'description' => 'Protect (i.e., physically control and securely store) system media containing CUI, both paper and digital.', 'category' => 'Media Protection', 'sort_order' => 64],
        ['ref' => '3.8.2', 'title' => 'Limit access to CUI on system media', 'description' => 'Limit access to CUI on system media to authorized users.', 'category' => 'Media Protection', 'sort_order' => 65],
        ['ref' => '3.8.3', 'title' => 'Sanitize or destroy system media before disposal', 'description' => 'Sanitize or destroy system media containing CUI before disposal or release for reuse.', 'category' => 'Media Protection', 'sort_order' => 66],
        ['ref' => '3.8.4', 'title' => 'Mark media with CUI markings', 'description' => 'Mark media with necessary CUI markings and distribution limitations.', 'category' => 'Media Protection', 'sort_order' => 67],
        ['ref' => '3.8.5', 'title' => 'Control access to media containing CUI', 'description' => 'Control access to media containing CUI and maintain accountability for media during transport outside of controlled areas.', 'category' => 'Media Protection', 'sort_order' => 68],
        ['ref' => '3.8.6', 'title' => 'Implement cryptographic mechanisms during transport', 'description' => 'Implement cryptographic mechanisms to protect the confidentiality of CUI stored on digital media during transport unless otherwise protected by alternative physical safeguards.', 'category' => 'Media Protection', 'sort_order' => 69],
        ['ref' => '3.8.7', 'title' => 'Control use of removable media', 'description' => 'Control the use of removable media on system components.', 'category' => 'Media Protection', 'sort_order' => 70],
        ['ref' => '3.8.8', 'title' => 'Prohibit use of portable storage without owner', 'description' => 'Prohibit the use of portable storage devices when such devices have no identifiable owner.', 'category' => 'Media Protection', 'sort_order' => 71],
        ['ref' => '3.8.9', 'title' => 'Protect backup CUI at storage locations', 'description' => 'Protect the confidentiality of backup CUI at storage locations.', 'category' => 'Media Protection', 'sort_order' => 72],

        // =====================================================================
        // 3.9 PERSONNEL SECURITY (2 requirements)
        // =====================================================================
        ['ref' => '3.9.1', 'title' => 'Screen individuals prior to access', 'description' => 'Screen individuals prior to authorizing access to organizational systems containing CUI.', 'category' => 'Personnel Security', 'sort_order' => 73],
        ['ref' => '3.9.2', 'title' => 'Protect CUI during personnel actions', 'description' => 'Ensure that organizational systems containing CUI are protected during and after personnel actions such as terminations and transfers.', 'category' => 'Personnel Security', 'sort_order' => 74],

        // =====================================================================
        // 3.10 PHYSICAL PROTECTION (6 requirements)
        // =====================================================================
        ['ref' => '3.10.1', 'title' => 'Limit physical access to organizational systems', 'description' => 'Limit physical access to organizational systems, equipment, and the respective operating environments to authorized individuals.', 'category' => 'Physical Protection', 'sort_order' => 75],
        ['ref' => '3.10.2', 'title' => 'Protect and monitor the physical facility', 'description' => 'Protect and monitor the physical facility and support infrastructure for organizational systems.', 'category' => 'Physical Protection', 'sort_order' => 76],
        ['ref' => '3.10.3', 'title' => 'Escort visitors', 'description' => 'Escort visitors and monitor visitor activity.', 'category' => 'Physical Protection', 'sort_order' => 77],
        ['ref' => '3.10.4', 'title' => 'Maintain audit logs of physical access', 'description' => 'Maintain audit logs of physical access.', 'category' => 'Physical Protection', 'sort_order' => 78],
        ['ref' => '3.10.5', 'title' => 'Control and manage physical access devices', 'description' => 'Control and manage physical access devices.', 'category' => 'Physical Protection', 'sort_order' => 79],
        ['ref' => '3.10.6', 'title' => 'Enforce safeguarding measures at alternate work sites', 'description' => 'Enforce safeguarding measures for CUI at alternate work sites.', 'category' => 'Physical Protection', 'sort_order' => 80],

        // =====================================================================
        // 3.11 RISK ASSESSMENT (3 requirements)
        // =====================================================================
        ['ref' => '3.11.1', 'title' => 'Periodically assess risk', 'description' => 'Periodically assess the risk to organizational operations (including mission, functions, image, or reputation), organizational assets, and individuals, resulting from the operation of organizational systems and the associated processing, storage, or transmission of CUI.', 'category' => 'Risk Assessment', 'sort_order' => 81],
        ['ref' => '3.11.2', 'title' => 'Scan for vulnerabilities', 'description' => 'Scan for vulnerabilities in organizational systems and applications periodically and when new vulnerabilities affecting those systems and applications are identified.', 'category' => 'Risk Assessment', 'sort_order' => 82],
        ['ref' => '3.11.3', 'title' => 'Remediate vulnerabilities', 'description' => 'Remediate vulnerabilities in accordance with risk assessments.', 'category' => 'Risk Assessment', 'sort_order' => 83],

        // =====================================================================
        // 3.12 SECURITY ASSESSMENT (4 requirements)
        // =====================================================================
        ['ref' => '3.12.1', 'title' => 'Periodically assess security controls', 'description' => 'Periodically assess the security controls in organizational systems to determine if the controls are effective in their application.', 'category' => 'Security Assessment', 'sort_order' => 84],
        ['ref' => '3.12.2', 'title' => 'Develop and implement plans of action', 'description' => 'Develop and implement plans of action designed to correct deficiencies and reduce or eliminate vulnerabilities in organizational systems.', 'category' => 'Security Assessment', 'sort_order' => 85],
        ['ref' => '3.12.3', 'title' => 'Monitor security controls on an ongoing basis', 'description' => 'Monitor security controls on an ongoing basis to ensure the continued effectiveness of the controls.', 'category' => 'Security Assessment', 'sort_order' => 86],
        ['ref' => '3.12.4', 'title' => 'Develop and update system security plans', 'description' => 'Develop, document, and periodically update system security plans that describe system boundaries, system environments of operation, how security requirements are implemented, and the relationships with or connections to other systems.', 'category' => 'Security Assessment', 'sort_order' => 87],

        // =====================================================================
        // 3.13 SYSTEM AND COMMUNICATIONS PROTECTION (16 requirements)
        // =====================================================================
        ['ref' => '3.13.1', 'title' => 'Monitor communications at external boundaries', 'description' => 'Monitor, control, and protect communications (i.e., information transmitted or received by organizational systems) at the external boundaries and key internal boundaries of organizational systems.', 'category' => 'System and Communications Protection', 'sort_order' => 88],
        ['ref' => '3.13.2', 'title' => 'Employ architectural designs to promote security', 'description' => 'Employ architectural designs, software development techniques, and systems engineering principles that promote effective information security within organizational systems.', 'category' => 'System and Communications Protection', 'sort_order' => 89],
        ['ref' => '3.13.3', 'title' => 'Separate user functionality from management functionality', 'description' => 'Separate user functionality from system management functionality.', 'category' => 'System and Communications Protection', 'sort_order' => 90],
        ['ref' => '3.13.4', 'title' => 'Prevent unauthorized and unintended information transfer', 'description' => 'Prevent unauthorized and unintended information transfer via shared system resources.', 'category' => 'System and Communications Protection', 'sort_order' => 91],
        ['ref' => '3.13.5', 'title' => 'Implement subnetworks for publicly accessible components', 'description' => 'Implement subnetworks for publicly accessible system components that are physically or logically separated from internal networks.', 'category' => 'System and Communications Protection', 'sort_order' => 92],
        ['ref' => '3.13.6', 'title' => 'Deny network communications by default', 'description' => 'Deny network communications traffic by default and allow network communications traffic by exception (i.e., deny all, permit by exception).', 'category' => 'System and Communications Protection', 'sort_order' => 93],
        ['ref' => '3.13.7', 'title' => 'Prevent remote activation of collaborative computing devices', 'description' => 'Prevent remote devices from simultaneously establishing non-remote connections with organizational systems and communicating via some other connection to resources in external networks (i.e., split tunneling).', 'category' => 'System and Communications Protection', 'sort_order' => 94],
        ['ref' => '3.13.8', 'title' => 'Implement cryptographic mechanisms to prevent unauthorized disclosure', 'description' => 'Implement cryptographic mechanisms to prevent unauthorized disclosure of CUI during transmission unless otherwise protected by alternative physical safeguards.', 'category' => 'System and Communications Protection', 'sort_order' => 95],
        ['ref' => '3.13.9', 'title' => 'Terminate network connections', 'description' => 'Terminate network connections associated with communications sessions at the end of the sessions or after a defined period of inactivity.', 'category' => 'System and Communications Protection', 'sort_order' => 96],
        ['ref' => '3.13.10', 'title' => 'Establish and manage cryptographic keys', 'description' => 'Establish and manage cryptographic keys for cryptography employed in organizational systems.', 'category' => 'System and Communications Protection', 'sort_order' => 97],
        ['ref' => '3.13.11', 'title' => 'Employ FIPS-validated cryptography', 'description' => 'Employ FIPS-validated cryptography when used to protect the confidentiality of CUI.', 'category' => 'System and Communications Protection', 'sort_order' => 98],
        ['ref' => '3.13.12', 'title' => 'Prohibit remote activation of collaborative computing devices', 'description' => 'Prohibit remote activation of collaborative computing devices and provide indication of devices in use to users present at the device.', 'category' => 'System and Communications Protection', 'sort_order' => 99],
        ['ref' => '3.13.13', 'title' => 'Control and monitor the use of mobile code', 'description' => 'Control and monitor the use of mobile code.', 'category' => 'System and Communications Protection', 'sort_order' => 100],
        ['ref' => '3.13.14', 'title' => 'Control and monitor the use of VoIP', 'description' => 'Control and monitor the use of Voice over Internet Protocol (VoIP) technologies.', 'category' => 'System and Communications Protection', 'sort_order' => 101],
        ['ref' => '3.13.15', 'title' => 'Protect authenticity of communications sessions', 'description' => 'Protect the authenticity of communications sessions.', 'category' => 'System and Communications Protection', 'sort_order' => 102],
        ['ref' => '3.13.16', 'title' => 'Protect CUI at rest', 'description' => 'Protect the confidentiality of CUI at rest.', 'category' => 'System and Communications Protection', 'sort_order' => 103],

        // =====================================================================
        // 3.14 SYSTEM AND INFORMATION INTEGRITY (7 requirements)
        // =====================================================================
        ['ref' => '3.14.1', 'title' => 'Identify, report, and correct flaws in a timely manner', 'description' => 'Identify, report, and correct information and system flaws in a timely manner.', 'category' => 'System and Information Integrity', 'sort_order' => 104],
        ['ref' => '3.14.2', 'title' => 'Provide protection from malicious code', 'description' => 'Provide protection from malicious code at appropriate locations within organizational systems.', 'category' => 'System and Information Integrity', 'sort_order' => 105],
        ['ref' => '3.14.3', 'title' => 'Monitor security alerts and advisories', 'description' => 'Monitor system security alerts and advisories and take action in response.', 'category' => 'System and Information Integrity', 'sort_order' => 106],
        ['ref' => '3.14.4', 'title' => 'Update malicious code protection mechanisms', 'description' => 'Update malicious code protection mechanisms when new releases are available.', 'category' => 'System and Information Integrity', 'sort_order' => 107],
        ['ref' => '3.14.5', 'title' => 'Perform periodic scans and real-time scans', 'description' => 'Perform periodic scans of organizational systems and real-time scans of files from external sources as files are downloaded, opened, or executed.', 'category' => 'System and Information Integrity', 'sort_order' => 108],
        ['ref' => '3.14.6', 'title' => 'Monitor organizational systems', 'description' => 'Monitor organizational systems, including inbound and outbound communications traffic, to detect attacks and indicators of potential attacks.', 'category' => 'System and Information Integrity', 'sort_order' => 109],
        ['ref' => '3.14.7', 'title' => 'Identify unauthorized use', 'description' => 'Identify unauthorized use of organizational systems.', 'category' => 'System and Information Integrity', 'sort_order' => 110],
    ]
];
