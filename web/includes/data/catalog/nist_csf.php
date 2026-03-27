<?php
/**
 * NIST Cybersecurity Framework (CSF) 2.0
 * Framework catalog template for GRC Compliance Engine
 *
 * All 6 functions: Govern (GV), Identify (ID), Protect (PR),
 * Detect (DE), Respond (RS), Recover (RC)
 */
return [
    'code' => 'NIST-CSF',
    'name' => 'NIST Cybersecurity Framework 2.0',
    'version' => '2.0',
    'description' => 'Framework for improving critical infrastructure cybersecurity',
    'category' => 'security',
    'url' => 'https://www.nist.gov/cyberframework',
    'requirements' => [

        // =====================================================================
        // GOVERN (GV)
        // =====================================================================

        // GV.OC — Organizational Context
        ['ref' => 'GV.OC-01', 'title' => 'Organizational mission is understood', 'description' => 'The organizational mission is understood and informs cybersecurity risk management.', 'category' => 'Govern', 'sort_order' => 1],
        ['ref' => 'GV.OC-02', 'title' => 'Internal and external stakeholders are understood', 'description' => 'Internal and external stakeholders are understood, and their needs and expectations regarding cybersecurity risk management are understood and considered.', 'category' => 'Govern', 'sort_order' => 2],
        ['ref' => 'GV.OC-03', 'title' => 'Legal, regulatory, and contractual requirements are understood', 'description' => 'Legal, regulatory, and contractual requirements regarding cybersecurity — including privacy and civil liberties obligations — are understood and managed.', 'category' => 'Govern', 'sort_order' => 3],
        ['ref' => 'GV.OC-04', 'title' => 'Critical objectives and dependencies are understood', 'description' => 'Critical objectives, capabilities, and services that external stakeholders depend on or expect from the organization are understood and communicated.', 'category' => 'Govern', 'sort_order' => 4],
        ['ref' => 'GV.OC-05', 'title' => 'Outcomes and priorities are established', 'description' => 'Outcomes, capabilities, and services that the organization depends on are understood and communicated.', 'category' => 'Govern', 'sort_order' => 5],

        // GV.RM — Risk Management Strategy
        ['ref' => 'GV.RM-01', 'title' => 'Risk management objectives are established', 'description' => 'Risk management objectives are established and agreed to by organizational stakeholders.', 'category' => 'Govern', 'sort_order' => 6],
        ['ref' => 'GV.RM-02', 'title' => 'Risk appetite and tolerance are established', 'description' => 'Risk appetite and risk tolerance statements are established, communicated, and maintained.', 'category' => 'Govern', 'sort_order' => 7],
        ['ref' => 'GV.RM-03', 'title' => 'Risk management activities are integrated', 'description' => 'Cybersecurity risk management activities and outcomes are included in enterprise risk management processes.', 'category' => 'Govern', 'sort_order' => 8],
        ['ref' => 'GV.RM-04', 'title' => 'Strategic direction for risk management is established', 'description' => 'Strategic direction that describes appropriate risk response options is established and communicated.', 'category' => 'Govern', 'sort_order' => 9],
        ['ref' => 'GV.RM-05', 'title' => 'Lines of communication for risk are established', 'description' => 'Lines of communication across the organization are established for cybersecurity risks, including risks from suppliers and other third parties.', 'category' => 'Govern', 'sort_order' => 10],
        ['ref' => 'GV.RM-06', 'title' => 'Standardized method for risk calculation is established', 'description' => 'A standardized method for calculating, documenting, categorizing, and prioritizing cybersecurity risks is established and communicated.', 'category' => 'Govern', 'sort_order' => 11],
        ['ref' => 'GV.RM-07', 'title' => 'Strategic opportunities are characterized', 'description' => 'Strategic opportunities (i.e., positive risks) are characterized and are included in organizational cybersecurity risk discussions.', 'category' => 'Govern', 'sort_order' => 12],

        // GV.RR — Roles, Responsibilities, and Authorities
        ['ref' => 'GV.RR-01', 'title' => 'Organizational leadership is responsible for risk', 'description' => 'Organizational leadership is responsible and accountable for cybersecurity risk and fosters a culture that is risk-aware, ethical, and continually improving.', 'category' => 'Govern', 'sort_order' => 13],
        ['ref' => 'GV.RR-02', 'title' => 'Roles and responsibilities are established', 'description' => 'Roles, responsibilities, and authorities related to cybersecurity risk management are established, communicated, understood, and enforced.', 'category' => 'Govern', 'sort_order' => 14],
        ['ref' => 'GV.RR-03', 'title' => 'Adequate resources are allocated', 'description' => 'Adequate resources are allocated commensurate with the cybersecurity risk strategy, roles, responsibilities, and policies.', 'category' => 'Govern', 'sort_order' => 15],
        ['ref' => 'GV.RR-04', 'title' => 'Cybersecurity is included in HR practices', 'description' => 'Cybersecurity is included in human resources practices.', 'category' => 'Govern', 'sort_order' => 16],

        // GV.PO — Policy
        ['ref' => 'GV.PO-01', 'title' => 'Cybersecurity policy is established', 'description' => 'A policy for managing cybersecurity risks is established based on organizational context, cybersecurity strategy, and priorities and is communicated and enforced.', 'category' => 'Govern', 'sort_order' => 17],
        ['ref' => 'GV.PO-02', 'title' => 'Policy is reviewed and updated', 'description' => 'Policy for managing cybersecurity risks is reviewed, updated, communicated, and enforced to reflect changes in requirements, threats, technology, and organizational mission.', 'category' => 'Govern', 'sort_order' => 18],

        // GV.SC — Supply Chain Risk Management
        ['ref' => 'GV.SC-01', 'title' => 'Supply chain risk management program is established', 'description' => 'A cybersecurity supply chain risk management program, strategy, objectives, policies, and processes are established and agreed to by organizational stakeholders.', 'category' => 'Govern', 'sort_order' => 19],
        ['ref' => 'GV.SC-02', 'title' => 'Supplier risk roles are established', 'description' => 'Cybersecurity roles and responsibilities for suppliers, customers, and partners are established, communicated, and coordinated internally and externally.', 'category' => 'Govern', 'sort_order' => 20],
        ['ref' => 'GV.SC-03', 'title' => 'Supply chain risk management is integrated', 'description' => 'Cybersecurity supply chain risk management is integrated into cybersecurity and enterprise risk management, risk assessment, and improvement processes.', 'category' => 'Govern', 'sort_order' => 21],
        ['ref' => 'GV.SC-04', 'title' => 'Suppliers are known and prioritized', 'description' => 'Suppliers are known and prioritized by criticality.', 'category' => 'Govern', 'sort_order' => 22],
        ['ref' => 'GV.SC-05', 'title' => 'Supply chain requirements are established', 'description' => 'Requirements to address cybersecurity risks in supply chains are established, prioritized, and integrated into contracts and other types of agreements with suppliers and other relevant third parties.', 'category' => 'Govern', 'sort_order' => 23],
        ['ref' => 'GV.SC-06', 'title' => 'Due diligence is performed on suppliers', 'description' => 'Planning and due diligence are conducted to reduce risks before entering into formal supplier or other third-party relationships.', 'category' => 'Govern', 'sort_order' => 24],
        ['ref' => 'GV.SC-07', 'title' => 'Supply chain risk is managed throughout lifecycle', 'description' => 'The risks posed by a supplier, their products and services, and other third parties are understood, recorded, prioritized, assessed, responded to, and monitored over the course of the relationship.', 'category' => 'Govern', 'sort_order' => 25],
        ['ref' => 'GV.SC-08', 'title' => 'Relevant suppliers are included in incident planning', 'description' => 'Relevant suppliers and other third parties are included in incident planning, response, and recovery activities.', 'category' => 'Govern', 'sort_order' => 26],
        ['ref' => 'GV.SC-09', 'title' => 'Supply chain security practices are integrated', 'description' => 'Supply chain security practices are integrated into cybersecurity and enterprise risk management programs, and their performance is monitored throughout the technology product and service life cycle.', 'category' => 'Govern', 'sort_order' => 27],
        ['ref' => 'GV.SC-10', 'title' => 'Supply chain risk management plans include provisions', 'description' => 'Cybersecurity supply chain risk management plans include provisions for activities that occur after the conclusion of a partnership or service agreement.', 'category' => 'Govern', 'sort_order' => 28],

        // =====================================================================
        // IDENTIFY (ID)
        // =====================================================================

        // ID.AM — Asset Management
        ['ref' => 'ID.AM-01', 'title' => 'Hardware inventories are maintained', 'description' => 'Inventories of hardware managed by the organization are maintained.', 'category' => 'Identify', 'sort_order' => 29],
        ['ref' => 'ID.AM-02', 'title' => 'Software inventories are maintained', 'description' => 'Inventories of software, services, and systems managed by the organization are maintained.', 'category' => 'Identify', 'sort_order' => 30],
        ['ref' => 'ID.AM-03', 'title' => 'Representations of network communication are maintained', 'description' => 'Representations of the organization\'s authorized network communication and internal and external network data flows are maintained.', 'category' => 'Identify', 'sort_order' => 31],
        ['ref' => 'ID.AM-04', 'title' => 'Inventories of services are maintained', 'description' => 'Inventories of services provided by suppliers are maintained.', 'category' => 'Identify', 'sort_order' => 32],
        ['ref' => 'ID.AM-05', 'title' => 'Assets are prioritized', 'description' => 'Assets are prioritized based on classification, criticality, resources, and impact on the mission.', 'category' => 'Identify', 'sort_order' => 33],
        ['ref' => 'ID.AM-07', 'title' => 'Data inventories are maintained', 'description' => 'Inventories of data and corresponding metadata for designated data types are maintained.', 'category' => 'Identify', 'sort_order' => 34],
        ['ref' => 'ID.AM-08', 'title' => 'Systems and assets are managed throughout lifecycle', 'description' => 'Systems, hardware, software, services, and data are managed throughout their life cycles.', 'category' => 'Identify', 'sort_order' => 35],

        // ID.RA — Risk Assessment
        ['ref' => 'ID.RA-01', 'title' => 'Vulnerabilities are identified and documented', 'description' => 'Vulnerabilities in assets are identified, validated, and recorded.', 'category' => 'Identify', 'sort_order' => 36],
        ['ref' => 'ID.RA-02', 'title' => 'Threat intelligence is received', 'description' => 'Cyber threat intelligence is received from information sharing forums and sources.', 'category' => 'Identify', 'sort_order' => 37],
        ['ref' => 'ID.RA-03', 'title' => 'Internal and external threats are identified', 'description' => 'Internal and external threats to the organization are identified and recorded.', 'category' => 'Identify', 'sort_order' => 38],
        ['ref' => 'ID.RA-04', 'title' => 'Potential impacts are identified', 'description' => 'Potential impacts and likelihoods of threats exploiting vulnerabilities are identified and recorded.', 'category' => 'Identify', 'sort_order' => 39],
        ['ref' => 'ID.RA-05', 'title' => 'Threats and risks are used to assess risk', 'description' => 'Threats, vulnerabilities, likelihoods, and impacts are used to understand inherent risk and inform risk response prioritization.', 'category' => 'Identify', 'sort_order' => 40],
        ['ref' => 'ID.RA-06', 'title' => 'Risk responses are chosen and prioritized', 'description' => 'Risk responses are chosen, prioritized, planned, tracked, and communicated.', 'category' => 'Identify', 'sort_order' => 41],
        ['ref' => 'ID.RA-07', 'title' => 'Changes and exceptions are managed', 'description' => 'Changes and exceptions are managed, assessed for risk impact, recorded, and tracked.', 'category' => 'Identify', 'sort_order' => 42],
        ['ref' => 'ID.RA-08', 'title' => 'Vulnerability disclosure processes are established', 'description' => 'Processes for receiving, analyzing, and responding to vulnerability disclosures are established.', 'category' => 'Identify', 'sort_order' => 43],
        ['ref' => 'ID.RA-09', 'title' => 'Integrity of hardware and software is assessed', 'description' => 'The authenticity and integrity of hardware and software are assessed prior to acquisition and use.', 'category' => 'Identify', 'sort_order' => 44],
        ['ref' => 'ID.RA-10', 'title' => 'Critical suppliers are assessed prior to acquisition', 'description' => 'Critical suppliers are assessed prior to acquisition.', 'category' => 'Identify', 'sort_order' => 45],

        // ID.IM — Improvement
        ['ref' => 'ID.IM-01', 'title' => 'Improvements are identified from evaluations', 'description' => 'Improvements are identified from evaluations of security tests, exercises, and assessments.', 'category' => 'Identify', 'sort_order' => 46],
        ['ref' => 'ID.IM-02', 'title' => 'Improvements are identified from security incidents', 'description' => 'Improvements are identified from security tests and exercises, including those done in coordination with suppliers and relevant third parties.', 'category' => 'Identify', 'sort_order' => 47],
        ['ref' => 'ID.IM-03', 'title' => 'Improvements are identified from execution of processes', 'description' => 'Improvements are identified from execution of operational processes, procedures, and activities.', 'category' => 'Identify', 'sort_order' => 48],
        ['ref' => 'ID.IM-04', 'title' => 'Incident response plans are improved', 'description' => 'Cybersecurity plans that affect operations are communicated, maintained, and improved.', 'category' => 'Identify', 'sort_order' => 49],

        // =====================================================================
        // PROTECT (PR)
        // =====================================================================

        // PR.AA — Identity Management, Authentication, and Access Control
        ['ref' => 'PR.AA-01', 'title' => 'Identities and credentials are managed', 'description' => 'Identities and credentials for authorized users, services, and hardware are managed by the organization.', 'category' => 'Protect', 'sort_order' => 50],
        ['ref' => 'PR.AA-02', 'title' => 'Identities are proofed and bound', 'description' => 'Identities are proofed and bound to credentials based on the context of interactions.', 'category' => 'Protect', 'sort_order' => 51],
        ['ref' => 'PR.AA-03', 'title' => 'Users and services are authenticated', 'description' => 'Users, services, and hardware are authenticated.', 'category' => 'Protect', 'sort_order' => 52],
        ['ref' => 'PR.AA-04', 'title' => 'Identity assertions are protected', 'description' => 'Identity assertions are protected, conveyed, and verified.', 'category' => 'Protect', 'sort_order' => 53],
        ['ref' => 'PR.AA-05', 'title' => 'Access permissions are managed', 'description' => 'Access permissions, entitlements, and authorizations are defined in a policy, managed, enforced, and reviewed, and incorporate the principles of least privilege and separation of duties.', 'category' => 'Protect', 'sort_order' => 54],
        ['ref' => 'PR.AA-06', 'title' => 'Physical access is managed', 'description' => 'Physical access to assets is managed, monitored, and enforced commensurate with risk.', 'category' => 'Protect', 'sort_order' => 55],

        // PR.AT — Awareness and Training
        ['ref' => 'PR.AT-01', 'title' => 'Personnel are provided awareness and training', 'description' => 'Personnel are provided with awareness and training so that they possess the knowledge and skills to perform general tasks with cybersecurity risks in mind.', 'category' => 'Protect', 'sort_order' => 56],
        ['ref' => 'PR.AT-02', 'title' => 'Privileged users understand responsibilities', 'description' => 'Individuals in specialized roles are provided with awareness and training so that they possess the knowledge and skills to perform relevant tasks with cybersecurity risks in mind.', 'category' => 'Protect', 'sort_order' => 57],

        // PR.DS — Data Security
        ['ref' => 'PR.DS-01', 'title' => 'Data-at-rest is protected', 'description' => 'The confidentiality, integrity, and availability of data-at-rest are protected.', 'category' => 'Protect', 'sort_order' => 58],
        ['ref' => 'PR.DS-02', 'title' => 'Data-in-transit is protected', 'description' => 'The confidentiality, integrity, and availability of data-in-transit are protected.', 'category' => 'Protect', 'sort_order' => 59],
        ['ref' => 'PR.DS-10', 'title' => 'Data-in-use is protected', 'description' => 'The confidentiality, integrity, and availability of data-in-use are protected.', 'category' => 'Protect', 'sort_order' => 60],
        ['ref' => 'PR.DS-11', 'title' => 'Backups are created and protected', 'description' => 'Backups of data are created, protected, maintained, and tested.', 'category' => 'Protect', 'sort_order' => 61],

        // PR.PS — Platform Security
        ['ref' => 'PR.PS-01', 'title' => 'Configuration management practices are established', 'description' => 'Configuration management practices are established and applied.', 'category' => 'Protect', 'sort_order' => 62],
        ['ref' => 'PR.PS-02', 'title' => 'Software is maintained and replaced', 'description' => 'Software is maintained, replaced, and removed commensurate with risk.', 'category' => 'Protect', 'sort_order' => 63],
        ['ref' => 'PR.PS-03', 'title' => 'Hardware is maintained and replaced', 'description' => 'Hardware is maintained, replaced, and removed commensurate with risk.', 'category' => 'Protect', 'sort_order' => 64],
        ['ref' => 'PR.PS-04', 'title' => 'Log records are generated', 'description' => 'Log records are generated and made available for continuous monitoring.', 'category' => 'Protect', 'sort_order' => 65],
        ['ref' => 'PR.PS-05', 'title' => 'Installation and execution of unauthorized software is prevented', 'description' => 'Installation and execution of unauthorized software is prevented.', 'category' => 'Protect', 'sort_order' => 66],
        ['ref' => 'PR.PS-06', 'title' => 'Secure software development practices are integrated', 'description' => 'Secure software development practices are integrated, and their performance is monitored throughout the software development life cycle.', 'category' => 'Protect', 'sort_order' => 67],

        // PR.IR — Technology Infrastructure Resilience
        ['ref' => 'PR.IR-01', 'title' => 'Networks and environments are protected', 'description' => 'Networks and environments are protected from unauthorized logical access and usage.', 'category' => 'Protect', 'sort_order' => 68],
        ['ref' => 'PR.IR-02', 'title' => 'Technology assets are protected from environmental threats', 'description' => 'The organization\'s technology assets are protected from environmental threats.', 'category' => 'Protect', 'sort_order' => 69],
        ['ref' => 'PR.IR-03', 'title' => 'Mechanisms for resilience are implemented', 'description' => 'Mechanisms are implemented to achieve resilience requirements in normal and adverse situations.', 'category' => 'Protect', 'sort_order' => 70],
        ['ref' => 'PR.IR-04', 'title' => 'Adequate resource capacity is maintained', 'description' => 'Adequate resource capacity to ensure availability is maintained.', 'category' => 'Protect', 'sort_order' => 71],

        // =====================================================================
        // DETECT (DE)
        // =====================================================================

        // DE.CM — Continuous Monitoring
        ['ref' => 'DE.CM-01', 'title' => 'Networks are monitored', 'description' => 'Networks and network services are monitored to find potentially adverse events.', 'category' => 'Detect', 'sort_order' => 72],
        ['ref' => 'DE.CM-02', 'title' => 'Physical environment is monitored', 'description' => 'The physical environment is monitored to find potentially adverse events.', 'category' => 'Detect', 'sort_order' => 73],
        ['ref' => 'DE.CM-03', 'title' => 'Personnel activity is monitored', 'description' => 'Personnel activity and technology usage are monitored to find potentially adverse events.', 'category' => 'Detect', 'sort_order' => 74],
        ['ref' => 'DE.CM-06', 'title' => 'External service provider activity is monitored', 'description' => 'External service provider activities and services are monitored to find potentially adverse events.', 'category' => 'Detect', 'sort_order' => 75],
        ['ref' => 'DE.CM-09', 'title' => 'Computing hardware and software are monitored', 'description' => 'Computing hardware and software, runtime environments, and their data are monitored to find potentially adverse events.', 'category' => 'Detect', 'sort_order' => 76],

        // DE.AE — Adverse Event Analysis
        ['ref' => 'DE.AE-02', 'title' => 'Potentially adverse events are analyzed', 'description' => 'Potentially adverse events are analyzed to better understand associated activities.', 'category' => 'Detect', 'sort_order' => 77],
        ['ref' => 'DE.AE-03', 'title' => 'Event information is correlated', 'description' => 'Information is correlated from multiple sources.', 'category' => 'Detect', 'sort_order' => 78],
        ['ref' => 'DE.AE-04', 'title' => 'Estimated impact of events is understood', 'description' => 'The estimated impact and scope of adverse events are understood.', 'category' => 'Detect', 'sort_order' => 79],
        ['ref' => 'DE.AE-06', 'title' => 'Information on adverse events is provided', 'description' => 'Information on adverse events is provided to authorized staff and tools.', 'category' => 'Detect', 'sort_order' => 80],
        ['ref' => 'DE.AE-07', 'title' => 'Cyber threat intelligence is integrated', 'description' => 'Cyber threat intelligence and other contextual information are integrated into the analysis.', 'category' => 'Detect', 'sort_order' => 81],
        ['ref' => 'DE.AE-08', 'title' => 'Incidents are declared', 'description' => 'Incidents are declared when adverse events meet the defined incident criteria.', 'category' => 'Detect', 'sort_order' => 82],

        // =====================================================================
        // RESPOND (RS)
        // =====================================================================

        // RS.MA — Incident Management
        ['ref' => 'RS.MA-01', 'title' => 'Incident response plan is executed', 'description' => 'The incident response plan is executed in coordination with relevant third parties once an incident is declared.', 'category' => 'Respond', 'sort_order' => 83],
        ['ref' => 'RS.MA-02', 'title' => 'Incident reports are triaged', 'description' => 'Incident reports are triaged and validated.', 'category' => 'Respond', 'sort_order' => 84],
        ['ref' => 'RS.MA-03', 'title' => 'Incidents are categorized and prioritized', 'description' => 'Incidents are categorized and prioritized.', 'category' => 'Respond', 'sort_order' => 85],
        ['ref' => 'RS.MA-04', 'title' => 'Incidents are escalated or elevated', 'description' => 'Incidents are escalated or elevated as needed.', 'category' => 'Respond', 'sort_order' => 86],
        ['ref' => 'RS.MA-05', 'title' => 'Criteria for initiating incident recovery are applied', 'description' => 'The criteria for initiating incident recovery are applied.', 'category' => 'Respond', 'sort_order' => 87],

        // RS.AN — Incident Analysis
        ['ref' => 'RS.AN-03', 'title' => 'Analysis is performed to establish cause and scope', 'description' => 'Analysis is performed to establish what has taken place during an incident and the root cause of the incident.', 'category' => 'Respond', 'sort_order' => 88],
        ['ref' => 'RS.AN-06', 'title' => 'Actions performed during investigation are recorded', 'description' => 'Actions performed during an investigation are recorded, and the records\' integrity and provenance are preserved.', 'category' => 'Respond', 'sort_order' => 89],
        ['ref' => 'RS.AN-07', 'title' => 'Incident data and metadata are collected', 'description' => 'Incident data and metadata are collected, and their integrity and provenance are preserved.', 'category' => 'Respond', 'sort_order' => 90],
        ['ref' => 'RS.AN-08', 'title' => 'Incident scope is estimated', 'description' => 'An incident\'s magnitude is estimated and validated.', 'category' => 'Respond', 'sort_order' => 91],

        // RS.CO — Incident Response Reporting and Communication
        ['ref' => 'RS.CO-02', 'title' => 'Internal and external stakeholders are notified', 'description' => 'Internal and external stakeholders are notified of incidents.', 'category' => 'Respond', 'sort_order' => 92],
        ['ref' => 'RS.CO-03', 'title' => 'Information is shared with designated parties', 'description' => 'Information is shared with designated internal and external stakeholders.', 'category' => 'Respond', 'sort_order' => 93],

        // RS.MI — Incident Mitigation
        ['ref' => 'RS.MI-01', 'title' => 'Incidents are contained', 'description' => 'Incidents are contained.', 'category' => 'Respond', 'sort_order' => 94],
        ['ref' => 'RS.MI-02', 'title' => 'Incidents are eradicated', 'description' => 'Incidents are eradicated.', 'category' => 'Respond', 'sort_order' => 95],

        // =====================================================================
        // RECOVER (RC)
        // =====================================================================

        // RC.RP — Incident Recovery Plan Execution
        ['ref' => 'RC.RP-01', 'title' => 'Recovery plan is executed', 'description' => 'The recovery portion of the incident response plan is executed once initiated from the incident response process.', 'category' => 'Recover', 'sort_order' => 96],
        ['ref' => 'RC.RP-02', 'title' => 'Recovery actions are selected and performed', 'description' => 'Recovery actions are selected, scoped, prioritized, and performed.', 'category' => 'Recover', 'sort_order' => 97],
        ['ref' => 'RC.RP-03', 'title' => 'Integrity of backups is verified', 'description' => 'The integrity of backups and other restoration assets is verified before using them for restoration.', 'category' => 'Recover', 'sort_order' => 98],
        ['ref' => 'RC.RP-04', 'title' => 'Critical functions and systems are restored', 'description' => 'Critical mission functions and cybersecurity risk management are considered to establish post-incident operational norms.', 'category' => 'Recover', 'sort_order' => 99],
        ['ref' => 'RC.RP-05', 'title' => 'Integrity of restored assets is verified', 'description' => 'The integrity of restored assets is verified, systems and services are restored, and normal operating status is confirmed.', 'category' => 'Recover', 'sort_order' => 100],
        ['ref' => 'RC.RP-06', 'title' => 'End of incident recovery is declared', 'description' => 'The end of incident recovery is declared based on criteria, and incident-related documentation is completed.', 'category' => 'Recover', 'sort_order' => 101],

        // RC.CO — Incident Recovery Communication
        ['ref' => 'RC.CO-03', 'title' => 'Recovery activities are communicated to stakeholders', 'description' => 'Recovery activities and progress in restoring operational capabilities are communicated to designated internal and external stakeholders.', 'category' => 'Recover', 'sort_order' => 102],
        ['ref' => 'RC.CO-04', 'title' => 'Public updates on recovery are shared', 'description' => 'Public updates on incident recovery are shared using approved methods and messaging.', 'category' => 'Recover', 'sort_order' => 103],
    ]
];
