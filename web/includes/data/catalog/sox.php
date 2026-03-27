<?php
/**
 * SOX (Sarbanes-Oxley) — COSO 2013 Internal Control Framework
 * Framework catalog template for GRC Compliance Engine
 *
 * The COSO 2013 Internal Control — Integrated Framework defines 17 principles
 * organized across 5 components. These principles form the basis for evaluating
 * the effectiveness of internal controls over financial reporting (ICFR) as
 * required by SOX Sections 302 and 404.
 */
return [
    'code' => 'SOX',
    'name' => 'SOX / COSO 2013',
    'version' => '2013',
    'description' => 'Sarbanes-Oxley Act compliance using COSO Internal Control — Integrated Framework',
    'category' => 'financial',
    'url' => 'https://www.coso.org/guidance-on-ic',
    'requirements' => [

        // ── Component 1: Control Environment (Principles 1–5) ──

        [
            'ref' => 'COSO-1',
            'title' => 'Demonstrates commitment to integrity and ethical values',
            'description' => 'The organization demonstrates a commitment to integrity and ethical values. The board of directors and management set the tone at the top by establishing standards of conduct, evaluating adherence to those standards, and addressing deviations in a timely manner. Ethical values are embedded into policies, job descriptions, and performance evaluations to reinforce expected behaviors throughout the entity.',
            'category' => 'Control Environment',
            'sort_order' => 1,
        ],
        [
            'ref' => 'COSO-2',
            'title' => 'Exercises oversight responsibility',
            'description' => 'The board of directors demonstrates independence from management and exercises oversight of the development and performance of internal control. The board retains oversight responsibility for management\'s design, implementation, and conduct of internal control across all five components. This includes establishing expectations for competence, evaluating performance, and holding individuals accountable for their internal control responsibilities.',
            'category' => 'Control Environment',
            'sort_order' => 2,
        ],
        [
            'ref' => 'COSO-3',
            'title' => 'Establishes structure, authority, and responsibility',
            'description' => 'Management establishes, with board oversight, structures, reporting lines, and appropriate authorities and responsibilities in the pursuit of objectives. The organizational structure supports effective internal control by defining roles, establishing reporting relationships, and delegating authority at appropriate levels. Management considers the entity\'s legal structure, business units, geographic locations, and outsourced service providers when designing internal control structures.',
            'category' => 'Control Environment',
            'sort_order' => 3,
        ],
        [
            'ref' => 'COSO-4',
            'title' => 'Demonstrates commitment to competence',
            'description' => 'The organization demonstrates a commitment to attract, develop, and retain competent individuals in alignment with objectives. Policies and practices reflect expectations of competence needed to support internal control. The organization evaluates competence across the entity and outsourced service providers, identifies gaps, and provides training, mentoring, and other development activities to address shortfalls in knowledge and skills.',
            'category' => 'Control Environment',
            'sort_order' => 4,
        ],
        [
            'ref' => 'COSO-5',
            'title' => 'Enforces accountability',
            'description' => 'The organization holds individuals accountable for their internal control responsibilities in the pursuit of objectives. Management and the board establish mechanisms to communicate and hold individuals accountable for the performance of internal control responsibilities. Accountability is reinforced through performance measures, incentives, rewards, and disciplinary actions applied consistently and fairly across the organization.',
            'category' => 'Control Environment',
            'sort_order' => 5,
        ],

        // ── Component 2: Risk Assessment (Principles 6–9) ──

        [
            'ref' => 'COSO-6',
            'title' => 'Specifies suitable objectives',
            'description' => 'The organization specifies objectives with sufficient clarity to enable the identification and assessment of risks relating to objectives. Management defines operational, reporting, and compliance objectives clearly enough to identify risks. For financial reporting, objectives reflect applicable accounting standards, materiality considerations, and the activities of the entity. Objectives are set at entity and transaction levels.',
            'category' => 'Risk Assessment',
            'sort_order' => 6,
        ],
        [
            'ref' => 'COSO-7',
            'title' => 'Identifies and analyzes risk',
            'description' => 'The organization identifies risks to the achievement of its objectives across the entity and analyzes risks as a basis for determining how the risks should be managed. Risk identification considers internal and external factors, including changes in the regulatory environment, economic conditions, business model, and IT infrastructure. Risk analysis involves evaluating the significance of identified risks, including assessing the likelihood and potential impact of each risk.',
            'category' => 'Risk Assessment',
            'sort_order' => 7,
        ],
        [
            'ref' => 'COSO-8',
            'title' => 'Assesses fraud risk',
            'description' => 'The organization considers the potential for fraud in assessing risks to the achievement of objectives. Management considers the various types of fraud that can occur, including fraudulent financial reporting, misappropriation of assets, and corrupt activities. The assessment considers incentives and pressures, opportunities, and attitudes and rationalizations. Management evaluates fraud risk related to management override of controls.',
            'category' => 'Risk Assessment',
            'sort_order' => 8,
        ],
        [
            'ref' => 'COSO-9',
            'title' => 'Identifies and analyzes significant change',
            'description' => 'The organization identifies and assesses changes that could significantly impact the system of internal control. The entity has processes to identify changes in the external environment (regulatory, economic, physical) and within the business (new technology, rapid growth, new business models, acquisitions, restructurings, and changes in key personnel) that may necessitate changes to the internal control system.',
            'category' => 'Risk Assessment',
            'sort_order' => 9,
        ],

        // ── Component 3: Control Activities (Principles 10–12) ──

        [
            'ref' => 'COSO-10',
            'title' => 'Selects and develops control activities',
            'description' => 'The organization selects and develops control activities that contribute to the mitigation of risks to the achievement of objectives to acceptable levels. Control activities are actions established through policies and procedures that help ensure management\'s directives to mitigate risks are carried out. Control activities include approvals, authorizations, verifications, reconciliations, reviews of performance, security of assets, and segregation of duties. Activities are performed at all levels and at various stages within business processes.',
            'category' => 'Control Activities',
            'sort_order' => 10,
        ],
        [
            'ref' => 'COSO-11',
            'title' => 'Selects and develops general controls over technology',
            'description' => 'The organization selects and develops general control activities over technology to support the achievement of objectives. Technology general controls include controls over the technology infrastructure, security management, and technology acquisition, development, and maintenance. These controls support the continued proper operation of technology and the functioning of automated controls such as system-enforced segregation of duties, automated transaction processing, and programmed edit checks.',
            'category' => 'Control Activities',
            'sort_order' => 11,
        ],
        [
            'ref' => 'COSO-12',
            'title' => 'Deploys through policies and procedures',
            'description' => 'The organization deploys control activities through policies that establish what is expected and procedures that put policies into action. Policies reflect management or board statements about what should be done to effect control. Procedures are the specific actions that personnel perform to implement policies. Policies and procedures are established at the relevant level of the entity, across business processes, and over technology. They are implemented thoughtfully, conscientiously, and consistently.',
            'category' => 'Control Activities',
            'sort_order' => 12,
        ],

        // ── Component 4: Information and Communication (Principles 13–15) ──

        [
            'ref' => 'COSO-13',
            'title' => 'Uses relevant information',
            'description' => 'The organization obtains or generates and uses relevant, quality information to support the functioning of internal control. Management identifies information requirements to support the functioning of the other components of internal control. Information systems capture and process both internal and external data from multiple sources and convert it into actionable information. The quality of information — its timeliness, accuracy, completeness, accessibility, and protection — is assessed and maintained.',
            'category' => 'Information and Communication',
            'sort_order' => 13,
        ],
        [
            'ref' => 'COSO-14',
            'title' => 'Communicates internally',
            'description' => 'The organization internally communicates information, including objectives and responsibilities for internal control, necessary to support the functioning of internal control. Communication occurs in all directions: down, across, and up the organization. Management provides specific and directed communication that addresses expectations for behavior, control responsibilities, and any changes to the internal control system. Communication channels enable personnel to report suspected problems and provide input to management.',
            'category' => 'Information and Communication',
            'sort_order' => 14,
        ],
        [
            'ref' => 'COSO-15',
            'title' => 'Communicates externally',
            'description' => 'The organization communicates with external parties regarding matters affecting the functioning of internal control. Communication with external stakeholders — regulators, financial analysts, external auditors, suppliers, customers, and business partners — provides information necessary for them to understand events and conditions that may affect their interaction with the entity. External communication channels allow inbound communication including whistleblower hotlines and regulatory inquiries.',
            'category' => 'Information and Communication',
            'sort_order' => 15,
        ],

        // ── Component 5: Monitoring Activities (Principles 16–17) ──

        [
            'ref' => 'COSO-16',
            'title' => 'Conducts ongoing and/or separate evaluations',
            'description' => 'The organization selects, develops, and performs ongoing and/or separate evaluations to ascertain whether the components of internal control are present and functioning. Ongoing evaluations are built into business processes at different levels of the entity and provide timely information. Separate evaluations — including internal audits, self-assessments, and peer reviews — are conducted periodically, with scope and frequency varying based on risk assessment, effectiveness of ongoing evaluations, and management judgment.',
            'category' => 'Monitoring Activities',
            'sort_order' => 16,
        ],
        [
            'ref' => 'COSO-17',
            'title' => 'Evaluates and communicates deficiencies',
            'description' => 'The organization evaluates and communicates internal control deficiencies in a timely manner to those parties responsible for taking corrective action, including senior management and the board of directors, as appropriate. Deficiencies identified through monitoring activities or other sources are assessed for severity and reported to appropriate levels. Management tracks whether deficiencies are remediated on a timely basis and escalates significant deficiencies and material weaknesses to senior management and the audit committee.',
            'category' => 'Monitoring Activities',
            'sort_order' => 17,
        ],
    ],
];
