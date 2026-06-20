-- Vendor Assessments Schema (MySQL)
-- Run this migration to add vendor assessment tables

-- Assessment templates (ISO 27001:2022, Tier 2, etc.)
CREATE TABLE IF NOT EXISTS assessment_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    category VARCHAR(50) DEFAULT 'vendor_assessment',
    allow_certificate_upload TINYINT(1) DEFAULT 0,
    certificate_upload_prompt TEXT,
    certificate_upload_mode VARCHAR(20) DEFAULT 'none',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Assessment sections/steps
CREATE TABLE IF NOT EXISTS assessment_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES assessment_templates(id) ON DELETE CASCADE,
    INDEX idx_section_order (template_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Assessment questions
CREATE TABLE IF NOT EXISTS assessment_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type VARCHAR(50) NOT NULL DEFAULT 'text',
    options TEXT,
    is_required TINYINT(1) DEFAULT 1,
    help_text TEXT,
    sort_order INT DEFAULT 0,
    depends_on_question_id INT DEFAULT NULL,
    depends_on_value VARCHAR(255) DEFAULT NULL,
    triggers_assessment_template_id INT DEFAULT NULL,
    triggers_on_value VARCHAR(255) DEFAULT NULL,
    include_in_minimal TINYINT(1) DEFAULT 0,
    FOREIGN KEY (section_id) REFERENCES assessment_sections(id) ON DELETE CASCADE,
    INDEX idx_question_order (section_id, sort_order),
    INDEX idx_depends_on (depends_on_question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vendor assessment instances (sent to vendors)
CREATE TABLE IF NOT EXISTS vendor_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    template_id INT NOT NULL,
    vendor_request_id INT,
    vendor_name VARCHAR(255) NOT NULL,
    vendor_email VARCHAR(255) NOT NULL,
    vendor_contact_name VARCHAR(255),
    vendor_contact_email VARCHAR(255),
    status VARCHAR(50) DEFAULT 'pending',
    current_section_id INT,
    certificate_uploaded TINYINT(1) DEFAULT 0,
    certificate_path VARCHAR(500),
    certificate_expiry DATE,
    started_at DATETIME,
    completed_at DATETIME,
    expires_at DATETIME,
    triggered_by_assessment_id INT DEFAULT NULL,
    triggered_by_question_id INT DEFAULT NULL,
    triggered_by_rule_id INT DEFAULT NULL,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES assessment_templates(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vendor assessment responses
CREATE TABLE IF NOT EXISTS vendor_assessment_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    question_id INT NOT NULL,
    response_value TEXT,
    file_path VARCHAR(500),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assessment_id) REFERENCES vendor_assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES assessment_questions(id),
    UNIQUE KEY unique_response (assessment_id, question_id),
    INDEX idx_assessment (assessment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Assessment files (encrypted file storage in database)
CREATE TABLE IF NOT EXISTS assessment_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_uuid VARCHAR(36) NOT NULL,
    assessment_id INT NOT NULL,
    file_type VARCHAR(50) NOT NULL DEFAULT 'attachment',
    question_id INT,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL,
    encrypted_data LONGBLOB NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assessment_id) REFERENCES vendor_assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES assessment_questions(id) ON DELETE SET NULL,
    UNIQUE KEY uk_file_uuid (file_uuid),
    INDEX idx_assessment (assessment_id),
    INDEX idx_file_type (file_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS assessment_workflow_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    source_template_id INT NOT NULL,
    question_id INT NOT NULL,
    condition_operator VARCHAR(20) DEFAULT 'equals',
    condition_value VARCHAR(255) NOT NULL,
    target_template_id INT NOT NULL,
    assign_to VARCHAR(20) DEFAULT 'vendor',
    is_active TINYINT(1) DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (source_template_id) REFERENCES assessment_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES assessment_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (target_template_id) REFERENCES assessment_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert ISO 27001:2022 template
INSERT IGNORE INTO assessment_templates (name, slug, description, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('ISO 27001:2022 Assessment', 'iso-27001-2022', 'Comprehensive information security management system assessment based on ISO 27001:2022 standard.', 1, 'If your organization holds a valid ISO 27001:2022 certification, you may upload it here to skip the detailed assessment. Please ensure the certificate is current and includes your organization name.', 1);

-- Insert Tier 2 Vendor Assessment template
INSERT IGNORE INTO assessment_templates (name, slug, description, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('Tier 2 Vendor Assessment', 'tier-2-vendor', 'Streamlined security assessment for lower-risk vendor relationships.', 0, NULL, 1);

-- ISO 27001:2022 Sections
INSERT IGNORE INTO assessment_sections (template_id, name, description, sort_order) VALUES
((SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022'), 'Organization Information', 'Basic information about your organization and security program.', 1),
((SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022'), 'Information Security Policies', 'Policies and governance for information security.', 2),
((SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022'), 'Asset Management', 'How assets are identified, classified, and protected.', 3),
((SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022'), 'Access Control', 'User access management and authentication controls.', 4),
((SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022'), 'Cryptography', 'Encryption and key management practices.', 5),
((SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022'), 'Physical Security', 'Physical and environmental security controls.', 6),
((SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022'), 'Operations Security', 'Operational procedures and responsibilities.', 7),
((SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022'), 'Incident Management', 'Security incident response and management.', 8),
((SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022'), 'Business Continuity', 'Business continuity and disaster recovery.', 9),
((SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022'), 'Compliance', 'Legal, regulatory, and contractual compliance.', 10);

-- Tier 2 Sections (smaller assessment)
INSERT IGNORE INTO assessment_sections (template_id, name, description, sort_order) VALUES
((SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor'), 'Company Information', 'Basic company and contact details.', 1),
((SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor'), 'Security Basics', 'Fundamental security controls and practices.', 2),
((SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor'), 'Data Handling', 'How you handle and protect data.', 3);

-- ISO 27001:2022 Questions - Section 1: Organization Information
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 1),
'What is your organization''s legal name?', 'text', NULL, 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 1),
'How many employees does your organization have?', 'select', '["1-50", "51-200", "201-500", "501-1000", "1001-5000", "5000+"]', 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 1),
'Does your organization have a dedicated information security team?', 'radio', '["Yes", "No", "Partially (shared responsibilities)"]', 1, NULL, 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 1),
'Who is responsible for information security in your organization?', 'text', NULL, 1, 'Name and title of the person or team responsible.', 4);

-- ISO 27001:2022 Questions - Section 2: Information Security Policies
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 2),
'Does your organization have a documented information security policy?', 'radio', '["Yes", "No", "In Development"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 2),
'How often are security policies reviewed and updated?', 'select', '["Annually", "Bi-annually", "Quarterly", "As needed", "Never"]', 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 2),
'Are employees required to acknowledge security policies?', 'radio', '["Yes, upon hiring", "Yes, annually", "Yes, upon hiring and annually", "No"]', 1, NULL, 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 2),
'Please describe your security awareness training program.', 'textarea', NULL, 1, 'Include frequency, topics covered, and how completion is tracked.', 4);

-- ISO 27001:2022 Questions - Section 3: Asset Management
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 3),
'Do you maintain an inventory of information assets?', 'radio', '["Yes", "No", "Partially"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 3),
'How are assets classified based on sensitivity?', 'textarea', NULL, 1, 'Describe your data classification scheme.', 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 3),
'What controls are in place for removable media?', 'textarea', NULL, 1, NULL, 3);

-- ISO 27001:2022 Questions - Section 4: Access Control
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 4),
'Is access to systems granted based on the principle of least privilege?', 'radio', '["Yes", "No", "Partially"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 4),
'Do you require multi-factor authentication (MFA)?', 'select', '["Yes, for all systems", "Yes, for critical systems only", "No", "In implementation"]', 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 4),
'How often are user access rights reviewed?', 'select', '["Monthly", "Quarterly", "Semi-annually", "Annually", "Never"]', 1, NULL, 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 4),
'Describe your password policy requirements.', 'textarea', NULL, 1, 'Include minimum length, complexity, expiration, and history requirements.', 4);

-- ISO 27001:2022 Questions - Section 5: Cryptography
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 5),
'Is data encrypted at rest?', 'radio', '["Yes, all data", "Yes, sensitive data only", "No"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 5),
'Is data encrypted in transit?', 'radio', '["Yes, all data", "Yes, sensitive data only", "No"]', 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 5),
'What encryption standards do you use?', 'textarea', NULL, 1, 'e.g., AES-256, TLS 1.2+, etc.', 3);

-- ISO 27001:2022 Questions - Section 6: Physical Security
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 6),
'Are facilities protected by physical access controls?', 'radio', '["Yes", "No", "Partially"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 6),
'Do you use a third-party data center or cloud provider?', 'radio', '["Yes", "No", "Both on-premise and cloud"]', 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 6),
'If using third-party facilities, what certifications do they hold?', 'checkbox', '["SOC 2 Type II", "ISO 27001", "PCI DSS", "HIPAA", "FedRAMP", "Other", "Unknown"]', 0, NULL, 3);

-- ISO 27001:2022 Questions - Section 7: Operations Security
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 7),
'Do you have documented change management procedures?', 'radio', '["Yes", "No", "In Development"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 7),
'Are development, testing, and production environments separated?', 'radio', '["Yes", "No", "Partially"]', 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 7),
'Do you perform regular vulnerability assessments?', 'select', '["Yes, continuously", "Yes, quarterly", "Yes, annually", "No"]', 1, NULL, 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 7),
'Do you perform penetration testing?', 'select', '["Yes, annually", "Yes, more frequently", "No", "Planned"]', 1, NULL, 4);

-- ISO 27001:2022 Questions - Section 8: Incident Management
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 8),
'Do you have a documented incident response plan?', 'radio', '["Yes", "No", "In Development"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 8),
'How quickly can you notify affected parties of a data breach?', 'select', '["Within 24 hours", "Within 48 hours", "Within 72 hours", "Within 1 week", "No defined timeline"]', 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 8),
'Have you experienced any security incidents in the past 12 months?', 'radio', '["Yes", "No"]', 1, NULL, 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 8),
'If yes, please describe the incident(s) and remediation steps taken.', 'textarea', NULL, 0, NULL, 4);

-- ISO 27001:2022 Questions - Section 9: Business Continuity
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 9),
'Do you have a business continuity plan (BCP)?', 'radio', '["Yes", "No", "In Development"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 9),
'Do you have a disaster recovery plan (DRP)?', 'radio', '["Yes", "No", "In Development"]', 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 9),
'How often are BCP/DRP plans tested?', 'select', '["Annually", "Semi-annually", "Quarterly", "Never", "N/A"]', 1, NULL, 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 9),
'What is your Recovery Time Objective (RTO)?', 'select', '["< 1 hour", "1-4 hours", "4-24 hours", "1-3 days", "> 3 days", "Not defined"]', 1, NULL, 4);

-- ISO 27001:2022 Questions - Section 10: Compliance
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 10),
'What compliance frameworks does your organization adhere to?', 'checkbox', '["SOC 2", "ISO 27001", "PCI DSS", "HIPAA", "GDPR", "CCPA", "NIST", "Other", "None"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 10),
'Do you conduct regular compliance audits?', 'radio', '["Yes, internal", "Yes, external", "Yes, both", "No"]', 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 10),
'Please upload any relevant compliance certifications or audit reports.', 'file', NULL, 0, 'SOC 2 reports, ISO certificates, etc.', 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'iso-27001-2022') AND sort_order = 10),
'Is there anything else you would like to share about your security program?', 'textarea', NULL, 0, NULL, 4);

-- Tier 2 Questions - Section 1: Company Information
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 1),
'Company legal name', 'text', NULL, 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 1),
'Primary contact name', 'text', NULL, 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 1),
'Primary contact email', 'text', NULL, 1, NULL, 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 1),
'Company website', 'text', NULL, 0, NULL, 4),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 1),
'Brief description of services provided', 'textarea', NULL, 1, NULL, 5);

-- Tier 2 Questions - Section 2: Security Basics
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 2),
'Does your organization have an information security policy?', 'radio', '["Yes", "No", "In Development"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 2),
'Do you require employees to complete security awareness training?', 'radio', '["Yes", "No"]', 1, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 2),
'Do you use antivirus/anti-malware software?', 'radio', '["Yes", "No"]', 1, NULL, 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 2),
'Do you use a firewall?', 'radio', '["Yes", "No"]', 1, NULL, 4),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 2),
'Do you have a process for applying security patches?', 'radio', '["Yes, automated", "Yes, manual", "No"]', 1, NULL, 5);

-- Tier 2 Questions - Section 3: Data Handling
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 3),
'Will you store or process any of our data?', 'radio', '["Yes", "No"]', 1, NULL, 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 3),
'If yes, where will the data be stored?', 'select', '["On-premise", "Cloud (US)", "Cloud (International)", "Not Applicable"]', 0, NULL, 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 3),
'Do you encrypt data at rest and in transit?', 'radio', '["Yes, both", "Yes, in transit only", "Yes, at rest only", "No"]', 1, NULL, 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 3),
'Do you have a data retention and disposal policy?', 'radio', '["Yes", "No"]', 1, NULL, 4),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor') AND sort_order = 3),
'Do you have cyber liability insurance?', 'radio', '["Yes", "No", "Unknown"]', 1, NULL, 5);

-- =============================================================================
-- VENDOR ONBOARDING REQUEST TEMPLATE
-- =============================================================================

-- Template
INSERT IGNORE INTO assessment_templates (name, slug, description, category, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('Vendor Onboarding Request', 'vendor-onboarding', 'Standard vendor onboarding intake form for collecting vendor information, data handling practices, and risk assessment details.', 'onboarding', 0, NULL, 1);

-- Sections
INSERT IGNORE INTO assessment_sections (template_id, name, description, sort_order) VALUES
((SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding'), 'Vendor Information', 'Basic vendor details, contacts, and relationship information.', 1),
((SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding'), 'Data & Risk Assessment', 'Data handling practices, security controls, and impact assessment.', 2),
((SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding'), 'Additional Information', 'Any other relevant details about the vendor engagement.', 3);

-- Questions - Section 1: Vendor Information (16 questions)
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order, field_name) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the vendor''s legal name?', 'text', NULL, 1, NULL, 1, 'vendor_name'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the vendor''s primary domain?', 'text', NULL, 0, 'e.g., vendor.com', 2, 'vendor_domain'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What other Domain Names are associated with this vendor (sister domains)?', 'textarea', NULL, 0, 'Comma-separated list of related domains for Shodan scanning.', 3, 'vendor_sisterdomains'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'Has this vendor completed Procurement Onboarding?', 'button_group', '["Yes", "No"]', 1, NULL, 4, 'vsu_onboarded'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the Vendor ID (VID)?', 'number', NULL, 0, '4-8 digit vendor ID from VSU procurement system.', 5, 'vendor_id'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the vendor type?', 'select', '["General Operations", "Technology", "Professional Services", "Financial Services", "Marketing", "HR/Benefits", "Facilities", "Legal", "Other"]', 1, NULL, 6, 'vendor_type'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'Who is the relationship manager for this vendor?', 'text', NULL, 1, 'Contact person responsible for the vendor relationship.', 7, 'relationship_manager'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the expected procurement date?', 'date', NULL, 1, NULL, 8, 'expected_procurement_date'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'Describe the product or service being provided.', 'textarea', NULL, 1, NULL, 9, 'product_service_description'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'How many users will use this product/service?', 'text', NULL, 1, 'e.g., 50, 100-200', 10, 'target_user_count'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the primary contact email?', 'text', NULL, 1, NULL, 11, 'primary_contact_email'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the primary contact''s full name?', 'text', NULL, 1, NULL, 12, 'primary_contact_details'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the primary contact''s title?', 'text', NULL, 0, 'e.g., Account Manager, Sales Director', 13, 'primary_contact_title'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the primary contact''s direct phone number?', 'phone', NULL, 0, NULL, 14, 'primary_contact_phone'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'Is an NDA in place with this vendor?', 'button_group', '["Yes", "No"]', 1, NULL, 15, 'nda_in_place'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'List any known vendor competitors.', 'textarea', NULL, 1, 'Include companies offering similar products or services.', 16, 'vendor_competitors');

-- Questions - Section 2: Data & Risk Assessment (23 questions)
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order, field_name) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'How many PII records will this vendor handle?', 'number', NULL, 0, 'Approximate count. Estimated breach cost: ~$160 per record.', 1, 'pii_record_count'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'How many SPII (Sensitive PII) records will this vendor handle?', 'number', NULL, 0, 'Approximate count. Estimated breach cost: ~$200 per record.', 2, 'spii_record_count'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'How many SOX-regulated records will this vendor handle?', 'number', NULL, 0, 'Approximate count. Estimated breach penalty: ~$5M flat fine.', 3, 'sox_record_count'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'What is the estimated business impact (USD) if this vendor is compromised?', 'number', NULL, 0, 'Dollar amount of potential financial impact.', 4, 'business_impact'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Will confidential information be shared with this vendor?', 'button_group', '["Yes", "No"]', 1, NULL, 5, 'confidential_info_shared'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the confidential information to be shared.', 'textarea', NULL, 0, 'Required if confidential information will be shared.', 6, 'confidential_info_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Will data be transferred across international borders?', 'button_group', '["Yes", "No"]', 1, NULL, 7, 'cross_border_transfer'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the cross-border data transfer details.', 'textarea', NULL, 0, 'Required if cross-border transfer applies.', 8, 'cross_border_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Will data be hosted off-site by this vendor?', 'button_group', '["Yes", "No"]', 1, NULL, 9, 'offsite_data_hosting'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the off-site data hosting arrangements.', 'textarea', NULL, 0, 'Required if off-site hosting applies.', 10, 'offsite_data_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Will this vendor have remote access to your network?', 'button_group', '["Yes", "No"]', 1, NULL, 11, 'remote_network_access'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the remote network access requirements.', 'textarea', NULL, 0, 'Required if remote access will be granted.', 12, 'remote_access_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Will this vendor have access to source code or repositories?', 'button_group', '["Yes", "No"]', 1, NULL, 13, 'source_code_access'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the source code access requirements.', 'textarea', NULL, 0, 'Required if source code access will be granted.', 14, 'source_code_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Does this vendor support a critical business function?', 'button_group', '["Yes", "No"]', 1, NULL, 15, 'critical_business_function'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the critical business function.', 'textarea', NULL, 0, 'Required if vendor supports a critical function.', 16, 'critical_function_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'What would be the impact of unauthorized disclosure of data by this vendor?', 'button_group', '["Low", "Moderate", "High", "Severe"]', 1, NULL, 17, 'unauthorized_disclosure_impact'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the potential impact of unauthorized disclosure.', 'textarea', NULL, 0, NULL, 18, 'unauthorized_disclosure_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'What would be the impact of unauthorized modification of data by this vendor?', 'button_group', '["Low", "Moderate", "High", "Severe"]', 1, NULL, 19, 'unauthorized_modification_impact'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'What would be the impact of disruption to this vendor''s service?', 'button_group', '["Low", "Moderate", "High", "Severe"]', 1, NULL, 20, 'disruption_impact'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Does this vendor support SAML/SSO authentication?', 'button_group', '["Yes", "No", "Unknown"]', 1, NULL, 21, 'saml_sso_support'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Is this a SaaS (Software as a Service) product?', 'button_group', '["Yes", "No"]', 1, NULL, 22, 'is_saas'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Does this vendor use AI in their product or service?', 'button_group', '["Yes", "No", "Unknown"]', 1, NULL, 23, 'vendor_use_ai');

-- Questions - Section 3: Additional Information
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order, field_name) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 3),
'Is there any additional information you would like to share about this vendor engagement?', 'textarea', NULL, 0, NULL, 1, 'additional_information');
