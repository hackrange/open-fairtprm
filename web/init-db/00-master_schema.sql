-- =============================================================================
-- Open TPRM & GRC - MySQL/MariaDB Database Schema
-- =============================================================================
-- Author: Tim Rice - Hack Range
-- Version: 2.5.7
-- Last Updated: 2026-03-07
--
-- DISCLAIMER: Use this software at your own risk. No warranty provided.
--
-- The complete database schema for this Third-Party Risk Management app.
-- Everything from user accounts and permissions to vendor assessments and
-- annual reviews lives here. If you're reading this, you're probably
-- either setting up a fresh install or debugging something at 2am.
-- Either way, godspeed.
--
-- Includes: Users, ACL/Permissions, Sessions, SAML SSO, TPRM Results,
--           Vendor Onboarding, SRS Scoring, Vendor Assessments, Annual Reviews,
--           Audit Logging, and Cron Execution History
--
-- Tables (37 total) + 4 Views:
--   1.  users                          - User accounts
--   2.  acl_groups                     - Role definitions
--   3.  user_acl_groups                - User-to-group assignments
--   4.  acl_permissions                - Granular permissions
--   5.  acl_group_permissions          - Group-to-permission mappings
--   6.  saml_group_mappings            - SAML-to-ACL group mappings
--   7.  sessions                       - Secure session storage
--   8.  saml_config                    - SAML 2.0 IdP configuration
--   9.  app_config                     - Application key-value config
--   10. tprm_results                   - FAIR analysis results
--   11. vendor_onboarding_requests     - Vendor intake forms
--   12. vendor_onboarding_stakeholders - Stakeholder assignments
--   13. vendor_annual_reviews          - Annual review history
--   14. vendor_review_reminders        - Email reminder tracking
--   15. vendor_srs_scores              - UpGuard security scores
--   16. vendor_srs_risks               - Detailed risk findings
--   17. vendor_subdomain_scores        - UpGuard subdomain scores
--   18. vendor_shodan_scores           - Shodan security scores
--   19. vendor_shodan_findings         - Shodan finding details
--   20. vendor_shodan_waivers          - Shodan risk waivers
--   21. vendor_shodan_cve_waivers      - CVE-specific waivers
--   22. vendor_technologies            - Technology inventory (4th party risk)
--   23. assessment_templates           - Assessment templates
--   24. assessment_sections            - Template sections
--   25. assessment_questions           - Template questions
--   26. vendor_assessments             - Assessment instances
--   27. vendor_assessment_responses    - Vendor answers
--   28. assessment_files               - Encrypted file storage
--   29. vendor_documents               - Vendor document storage
--   30. vendor_contract_reminders      - Contract expiry reminder tracking
--   30b. vendor_assessment_reminders   - Assessment reminder tracking
--   31. email_templates                - Email template management
--   32. audit_log                      - Action audit trail
--   33. cron_execution_history         - Cron job tracking
--   34. cyber_todo_activities          - Todo activity tracking
--   35. score                          - Legacy SRS score history
--   36. tier                           - Legacy vendor tier/scan config
--   37. domainexclusions               - Legacy subdomain exclusions
--   V1. recent_scores                  - View: most recent scores per vendor/source
--   V2. view_mostrecent                - View: most recent total average per vendor
--   V3. view_next_scan                 - View: next scheduled scan per vendor
--   V4. view_cve_search                - View: CVE search across Shodan findings
-- =============================================================================

-- Create database
CREATE DATABASE IF NOT EXISTS tprm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tprm;

-- =============================================================================
-- SECTION 1: USER MANAGEMENT
-- =============================================================================

-- Users table - Core user accounts with encrypted passwords
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL COMMENT 'Bcrypt/Argon2 hashed password with automatic salt',
    email VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    is_admin TINYINT(1) DEFAULT 0,
    is_super_admin TINYINT(1) DEFAULT 0 COMMENT 'Super admin with full system access',
    department VARCHAR(100) DEFAULT NULL COMMENT 'User department',
    job_title VARCHAR(100) DEFAULT NULL COMMENT 'User job title',
    totp_enabled TINYINT(1) DEFAULT 0,
    totp_secret VARCHAR(255) DEFAULT NULL COMMENT 'Encrypted TOTP secret',
    failed_login_attempts INT DEFAULT 0,
    last_failed_login DATETIME DEFAULT NULL,
    account_locked_until DATETIME DEFAULT NULL,
    last_login DATETIME DEFAULT NULL,
    -- Theme customization per user
    theme_logo_url VARCHAR(500) DEFAULT NULL COMMENT 'User-specific header logo URL',
    theme_footer_logo_url VARCHAR(500) DEFAULT NULL COMMENT 'User-specific footer logo URL',
    theme_header_color VARCHAR(20) DEFAULT NULL COMMENT 'User-specific header color',
    theme_footer_color VARCHAR(20) DEFAULT NULL COMMENT 'User-specific footer color',
    theme_button_color VARCHAR(20) DEFAULT NULL COMMENT 'User-specific button color',
    theme_nav_fill_color VARCHAR(20) DEFAULT NULL COMMENT 'User-specific navigation fill color',
    theme_nav_font_color VARCHAR(20) DEFAULT NULL COMMENT 'User-specific navigation font color',
    theme_nav_width VARCHAR(10) DEFAULT NULL COMMENT 'User-specific navigation width in pixels',
    dashboard_modules TEXT DEFAULT NULL COMMENT 'JSON array of selected dashboard module keys (max 9)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 2: ACCESS CONTROL LIST (ACL)
-- =============================================================================

-- ACL Groups/Roles table
CREATE TABLE IF NOT EXISTS acl_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(255) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    -- 1 = shipped default group (non-deletable, name-locked, frozen permissions);
    -- 0 = admin-created custom group (editable + deletable).
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_group_name (group_name),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Group Assignments (Many-to-Many)
CREATE TABLE IF NOT EXISTS user_acl_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT UNSIGNED,
    UNIQUE KEY unique_user_group (user_id, group_id),
    INDEX idx_user_id (user_id),
    INDEX idx_group_id (group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES acl_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Granular Permissions
CREATE TABLE IF NOT EXISTS acl_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    module VARCHAR(50),
    resource VARCHAR(50),
    action VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module),
    INDEX idx_resource_action (resource, action),
    INDEX idx_permission_code (permission_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group-Permission Mapping
CREATE TABLE IF NOT EXISTS acl_group_permissions (
    group_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by INT UNSIGNED,
    PRIMARY KEY (group_id, permission_id),
    FOREIGN KEY (group_id) REFERENCES acl_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES acl_permissions(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 3: SESSIONS AND AUTHENTICATION
-- =============================================================================

-- Sessions table - Secure session management
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    payload LONGTEXT NOT NULL,
    last_activity INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SAML configuration table
CREATE TABLE IF NOT EXISTS saml_config (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    is_enabled TINYINT(1) DEFAULT 0,
    idp_entity_id VARCHAR(255) NOT NULL,
    idp_sso_url VARCHAR(500) NOT NULL,
    idp_slo_url VARCHAR(500),
    idp_certificate TEXT NOT NULL COMMENT 'X.509 certificate',
    sp_entity_id VARCHAR(255) NOT NULL,
    sp_acs_url VARCHAR(500) NOT NULL,
    sp_slo_url VARCHAR(500),
    sp_certificate TEXT,
    sp_private_key TEXT COMMENT 'Encrypted private key',
    attribute_mapping JSON COMMENT 'Map SAML attributes to user fields',
    group_attribute VARCHAR(255) DEFAULT 'groups' COMMENT 'SAML assertion attribute name containing group claims',
    auto_activate TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Auto-activate new SSO-provisioned accounts (1=active, 0=pending)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SAML Group to ACL Group Mapping (for auto-provisioning)
CREATE TABLE IF NOT EXISTS saml_group_mappings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    saml_group_name VARCHAR(255) NOT NULL UNIQUE COMMENT 'SAML group/role claim value',
    acl_group_id INT UNSIGNED NOT NULL,
    auto_assign TINYINT(1) DEFAULT 1 COMMENT 'Automatically assign on SAML login',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (acl_group_id) REFERENCES acl_groups(id) ON DELETE CASCADE,
    INDEX idx_saml_group_name (saml_group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 4: APPLICATION CONFIGURATION
-- =============================================================================

-- Application configuration table (key-value store, some values encrypted)
CREATE TABLE IF NOT EXISTS app_config (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL COMMENT 'Encrypted sensitive values',
    is_encrypted TINYINT(1) DEFAULT 0,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 5: FAIR ANALYSIS / TPRM RESULTS
-- =============================================================================

-- TPRM Results table - All sensitive data encrypted at rest via AES-256-CBC
CREATE TABLE IF NOT EXISTS tprm_results (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    vendor_name VARCHAR(255) NOT NULL,
    vendor_domain VARCHAR(255) DEFAULT NULL COMMENT 'Vendor domain name (e.g., example.com)',

    -- Cyber Security Section - Encrypted
    msa TEXT COMMENT 'Encrypted: Master Services Agreement',
    scope_of_work TEXT COMMENT 'Encrypted: Description of work',
    medium_of_data TEXT COMMENT 'Encrypted: Data transfer method',
    certifications TEXT COMMENT 'Encrypted: Security certifications',
    compliance TEXT COMMENT 'Encrypted: Compliance info',
    security_governance TEXT COMMENT 'Encrypted: Security governance',
    incident_response_plan TEXT COMMENT 'Encrypted: Incident response plan',
    continuous_monitoring TEXT COMMENT 'Encrypted: Continuous monitoring',
    supply_chain_risk_mgmt TEXT COMMENT 'Encrypted: Supply chain risk management',
    security_awareness_training TEXT COMMENT 'Encrypted: Security awareness training',
    vulnerability_management TEXT COMMENT 'Encrypted: Vulnerability management',
    patch_management TEXT COMMENT 'Encrypted: Patch management',
    access_controls TEXT COMMENT 'Encrypted: Access controls',
    data_encryption TEXT COMMENT 'Encrypted: Data encryption',
    network_security TEXT COMMENT 'Encrypted: Network security',
    daily_impact TEXT COMMENT 'Encrypted: Daily financial impact if vendor services are disrupted',

    -- Vendor Risk Section - Encrypted
    security_score VARCHAR(1) COMMENT 'Security grade: A, B, C, D, or F',
    vulnerability_data TEXT COMMENT 'Encrypted: Vulnerability data',
    configuration_data TEXT COMMENT 'Encrypted: Configuration data',
    compliance_data TEXT COMMENT 'Encrypted: Compliance data',
    risk_assessment TEXT COMMENT 'Encrypted: Risk assessment',
    threat_intelligence TEXT COMMENT 'Encrypted: Threat intelligence',
    iso_27001_certified TINYINT(1) DEFAULT 0 COMMENT 'ISO 27001 certification status (0=No, 1=Yes)',
    securityscorecard_rating VARCHAR(1) DEFAULT NULL COMMENT 'SecurityScorecard rating: A, B, C, D, or F',
    pii_record_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of PII records - $160 per record breach cost',
    spii_record_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of SPII records - $200 per record breach cost',
    sox_record_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of SOX records - $5M flat fine if breached',

    -- SRS Input Section - Encrypted
    vendor_risk_assessment TEXT COMMENT 'Encrypted: Vendor risk assessment',
    security_questionnaire TEXT COMMENT 'Encrypted: Security questionnaire',
    compliance_questionnaire TEXT COMMENT 'Encrypted: Compliance questionnaire',
    data_classification TEXT COMMENT 'Encrypted: Data classification',
    data_sharing TEXT COMMENT 'Encrypted: Data sharing',
    business_impact TEXT COMMENT 'Encrypted: Business impact',
    vendor_performance TEXT COMMENT 'Encrypted: Vendor performance',

    -- Vendor Third-Party Vendors Section - Encrypted
    third_party_vendor_list TEXT COMMENT 'Encrypted: Third-party vendor list',
    third_party_risk_assessment TEXT COMMENT 'Encrypted: Third-party risk assessment',
    third_party_security_questionnaire TEXT COMMENT 'Encrypted: Third-party security questionnaire',
    third_party_compliance_questionnaire TEXT COMMENT 'Encrypted: Third-party compliance questionnaire',
    vendor_cyber_insurance_coverage TEXT COMMENT 'Encrypted: Vendor cyber insurance coverage amount',

    -- Cost and Financial Information Section - Encrypted
    cost_of_breach TEXT COMMENT 'Encrypted: Cost of breach',
    cost_of_outage TEXT COMMENT 'Encrypted: Cost of outage',
    sec_fines TEXT COMMENT 'Encrypted: SEC fines',
    compliance_fines TEXT COMMENT 'Encrypted: Compliance fines',
    total_cost_of_breach TEXT COMMENT 'Encrypted: Total cost of breach calculation',
    pii_breach_cost TEXT COMMENT 'Encrypted: PII breach cost calculation',
    spii_breach_cost TEXT COMMENT 'Encrypted: SPII breach cost calculation',
    sox_breach_cost TEXT COMMENT 'Encrypted: SOX breach cost calculation',
    insurance_premiums TEXT COMMENT 'Encrypted: Insurance premiums',

    -- FAIR Model Output Section - Encrypted
    loss_event_frequency TEXT COMMENT 'Encrypted: Loss Event Frequency',
    loss_magnitude TEXT COMMENT 'Encrypted: Loss Magnitude',
    primary_loss_magnitude TEXT COMMENT 'Encrypted: Primary Loss Magnitude',
    secondary_loss_magnitude TEXT COMMENT 'Encrypted: Secondary Loss Magnitude',
    risk_output TEXT COMMENT 'Encrypted: Risk output',
    ale TEXT COMMENT 'Encrypted: Annualized Loss Expectancy',
    recommended_liability TEXT COMMENT 'Encrypted: Recommended Liability',

    -- Executive Summary (AI-generated, stored once)
    executive_summary TEXT DEFAULT NULL COMMENT 'AI-generated executive summary for PDF reports',

    -- Metadata
    status ENUM('draft', 'completed', 'archived') DEFAULT 'draft',
    completed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user_id (user_id),
    INDEX idx_vendor_name (vendor_name),
    INDEX idx_vendor_domain (vendor_domain),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 6: VENDOR ONBOARDING
-- =============================================================================

-- Vendor Onboarding Requests table
CREATE TABLE IF NOT EXISTS vendor_onboarding_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Stakeholder/Owner Information
    created_by INT UNSIGNED NOT NULL COMMENT 'User who created this request',

    -- Section 1: Vendor Information
    vendor_name VARCHAR(500) DEFAULT NULL COMMENT '1.1 Vendor Name',
    vendor_domain VARCHAR(255) DEFAULT NULL COMMENT 'Vendor domain name for SRS scoring',
    vendor_id VARCHAR(20) DEFAULT NULL COMMENT 'VSU Vendor ID (VID)',
    vendor_type VARCHAR(255) DEFAULT NULL COMMENT '1.2 Vendor Type',
    vendor_tier ENUM('1', '2', '3') DEFAULT NULL COMMENT 'Vendor tier for SRS rescoring: 1=Monthly, 2=90-days, 3=Annual',
    relationship_manager VARCHAR(255) DEFAULT NULL COMMENT '1.3 Business relationship manager',
    expected_procurement_date DATE DEFAULT NULL COMMENT '1.4 Expected procurement date',
    product_service_description TEXT COMMENT '1.5 Product/service description',
    target_user_count VARCHAR(100) DEFAULT NULL COMMENT '1.6 Target user count',
    primary_contact_email VARCHAR(255) DEFAULT NULL COMMENT '1.7 Primary contact email',
    primary_contact_details TEXT COMMENT '1.10 Primary contact full name',
    primary_contact_title VARCHAR(255) DEFAULT NULL COMMENT '1.10a Primary contact job title',
    primary_contact_phone VARCHAR(50) DEFAULT NULL COMMENT '1.10b Primary contact direct phone',
    nda_in_place ENUM('yes', 'no', '') DEFAULT '' COMMENT '1.9 Is NDA in place?',
    vendor_competitors TEXT COMMENT '1.10 Vendor competitors',
    vsu_onboarded ENUM('yes', 'no', '') DEFAULT '' COMMENT 'VSU onboarding status',

    -- Section 2: Data & Security Questions
    pii_phi_exchange ENUM('yes', 'no', '') DEFAULT '' COMMENT '2.1 PII/PHI exchange?',
    pii_phi_justification TEXT COMMENT '2.1 PII/PHI justification',
    confidential_info_shared ENUM('yes', 'no', '') DEFAULT '' COMMENT '2.2 Confidential info shared?',
    confidential_info_justification TEXT COMMENT '2.2 Confidential info justification',
    cross_border_transfer ENUM('yes', 'no', '') DEFAULT '' COMMENT '2.3 Cross-border data transfer?',
    cross_border_justification TEXT COMMENT '2.3 Cross-border justification',
    offsite_data_hosting ENUM('yes', 'no', '') DEFAULT '' COMMENT '2.4 Off-site data hosting?',
    offsite_data_justification TEXT COMMENT '2.4 Off-site data justification',
    remote_network_access ENUM('yes', 'no', '') DEFAULT '' COMMENT '2.5 Remote network access?',
    remote_access_justification TEXT COMMENT '2.5 Remote access justification',
    source_code_access ENUM('yes', 'no', '') DEFAULT '' COMMENT '2.6 Source code/repo access?',
    source_code_justification TEXT COMMENT '2.6 Source code justification',
    critical_business_function ENUM('yes', 'no', '') DEFAULT '' COMMENT '2.7 Critical business function?',
    critical_function_justification TEXT COMMENT '2.7 Critical function justification',
    unauthorized_disclosure_impact ENUM('low', 'moderate', 'high', 'severe', '') DEFAULT '' COMMENT '2.8 Impact of unauthorized disclosure',
    unauthorized_disclosure_justification TEXT COMMENT '2.8 Unauthorized disclosure justification',
    unauthorized_modification_impact ENUM('low', 'moderate', 'high', 'severe', '') DEFAULT '' COMMENT '2.9 Impact of unauthorized modification',
    disruption_impact ENUM('low', 'moderate', 'high', 'severe', '') DEFAULT '' COMMENT '2.10 Impact of disruption',
    saml_sso_support ENUM('yes', 'no', 'unknown', '') DEFAULT '' COMMENT '2.11 SAML/SSO support?',
    is_saas ENUM('yes', 'no', '') DEFAULT '' COMMENT '2.12 Is SaaS product?',
    vendor_use_ai ENUM('yes', 'no', '') DEFAULT '' COMMENT '2.13 Does solution use AI?',

    -- FAIR Analysis Fields
    pii_record_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of PII records',
    spii_record_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of SPII records',
    sox_record_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of SOX records',
    business_impact DECIMAL(15,2) DEFAULT NULL COMMENT 'Business impact value',

    -- Section 3: Additional Information
    additional_information TEXT COMMENT '3.1 Additional information',

    -- SRS Fields
    current_srs_score INT DEFAULT NULL COMMENT 'Current UpGuard SRS score (0-950)',
    last_srs_score_at DATETIME DEFAULT NULL COMMENT 'Last SRS score fetch time',

    -- Shodan Fields
    current_shodan_score INT DEFAULT NULL COMMENT 'Current Shodan security score (0-100)',
    last_shodan_score_at DATETIME DEFAULT NULL COMMENT 'Last Shodan score fetch time',

    -- Custom Score Fields
    custom_score INT DEFAULT NULL COMMENT 'Custom manual security score (1-100)',

    -- Background Rescore Status
    rescore_status VARCHAR(30) DEFAULT NULL COMMENT 'rescoring, rescoring_shodan, rescoring_upguard, or NULL when idle',
    rescore_started_at DATETIME DEFAULT NULL COMMENT 'When the background rescore started',
    rescore_result TEXT DEFAULT NULL COMMENT 'Result message from last background rescore',

    -- Vendor Favicon
    vendor_favicon MEDIUMBLOB DEFAULT NULL COMMENT 'Vendor favicon image data',
    vendor_favicon_mime VARCHAR(50) DEFAULT NULL COMMENT 'MIME type of stored favicon',

    -- Status and Workflow
    status ENUM('draft', 'submitted', 'in_review', 'ai_review', 'approved', 'rejected', 'inactive', 'evaluation') DEFAULT 'draft',
    status_notes TEXT COMMENT 'Notes about status changes',
    marked_inactive_at DATETIME DEFAULT NULL COMMENT 'When marked as inactive',
    marked_inactive_by INT UNSIGNED DEFAULT NULL COMMENT 'Who marked it inactive',

    -- Annual Review Tracking
    last_annual_review DATETIME DEFAULT NULL COMMENT 'Last annual review date with stakeholder',
    last_annual_review_due DATE DEFAULT NULL COMMENT 'Next annual review due date (calculated from last review or approval date)',

    -- Timestamps
    last_autosave DATETIME DEFAULT NULL COMMENT 'Last autosave timestamp',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_at DATETIME DEFAULT NULL COMMENT 'When form was submitted',

    -- Indexes
    INDEX idx_created_by (created_by),
    INDEX idx_status (status),
    INDEX idx_vendor_name (vendor_name(255)),
    INDEX idx_vendor_domain (vendor_domain),
    INDEX idx_vendor_tier (vendor_tier),
    INDEX idx_last_srs_score (last_srs_score_at),
    INDEX idx_created_at (created_at),
    INDEX idx_submitted_at (submitted_at),
    INDEX idx_last_annual_review (last_annual_review),
    INDEX idx_last_annual_review_due (last_annual_review_due),

    -- Foreign Keys
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_inactive_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stakeholder assignments table
CREATE TABLE IF NOT EXISTS vendor_onboarding_stakeholders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'stakeholder', 'reviewer') DEFAULT 'stakeholder',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT UNSIGNED,

    UNIQUE KEY unique_request_user (request_id, user_id),
    INDEX idx_request_id (request_id),
    INDEX idx_user_id (user_id),

    FOREIGN KEY (request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Annual review history table
CREATE TABLE IF NOT EXISTS vendor_annual_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_request_id INT UNSIGNED NOT NULL,
    review_date DATETIME NOT NULL,
    due_date DATE NOT NULL,
    reviewer_user_id INT UNSIGNED NOT NULL,

    -- Review responses
    is_still_stakeholder ENUM('yes', 'no') NOT NULL,
    new_stakeholder_id INT UNSIGNED DEFAULT NULL COMMENT 'If stakeholder changed, new stakeholder user ID',
    scope_changes TEXT COMMENT 'Description of any scope changes',
    contact_updates JSON DEFAULT NULL COMMENT 'JSON object with updated contact information',
    review_notes TEXT COMMENT 'Additional notes from reviewer',

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_vendor_request (vendor_request_id),
    INDEX idx_review_date (review_date),
    INDEX idx_due_date (due_date),
    INDEX idx_reviewer (reviewer_user_id),

    -- Foreign key
    FOREIGN KEY (vendor_request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reminder tracking table
CREATE TABLE IF NOT EXISTS vendor_review_reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_request_id INT UNSIGNED NOT NULL,
    stakeholder_user_id INT UNSIGNED NOT NULL,
    reminder_type ENUM('30_days_before', 'due_date', 'overdue') NOT NULL,
    due_date DATE NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    email_sent_to VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    error_message TEXT,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_vendor_request (vendor_request_id),
    INDEX idx_stakeholder (stakeholder_user_id),
    INDEX idx_status (status),
    INDEX idx_sent_at (sent_at),

    -- Unique constraint to prevent duplicate reminders
    UNIQUE KEY unique_reminder (vendor_request_id, stakeholder_user_id, reminder_type, due_date),

    -- Foreign key
    FOREIGN KEY (vendor_request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 7: SRS (SECURITY RATING SERVICE) SCORING
-- =============================================================================

-- SRS Score History table (for trending)
CREATE TABLE IF NOT EXISTS vendor_srs_scores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_onboarding_id INT UNSIGNED NOT NULL COMMENT 'Reference to vendor_onboarding_requests',
    vendor_domain VARCHAR(255) NOT NULL COMMENT 'Vendor domain that was scored',

    -- UpGuard Score Data
    score INT NOT NULL COMMENT 'UpGuard SRS score (0-950)',
    score_grade VARCHAR(10) DEFAULT NULL COMMENT 'Grade (A, B, C, D, F)',

    -- Category Scores (from UpGuard)
    category_scores JSON DEFAULT NULL COMMENT 'JSON object with category breakdowns',

    -- Risk Counts
    critical_risks INT DEFAULT 0 COMMENT 'Number of critical severity risks',
    high_risks INT DEFAULT 0 COMMENT 'Number of high severity risks',
    medium_risks INT DEFAULT 0 COMMENT 'Number of medium severity risks',
    low_risks INT DEFAULT 0 COMMENT 'Number of low severity risks',
    info_risks INT DEFAULT 0 COMMENT 'Number of informational risks',

    -- Was vendor already monitored in UpGuard?
    was_already_monitored TINYINT(1) DEFAULT 0 COMMENT 'Was vendor already being monitored',

    -- Timestamps
    scored_at DATETIME NOT NULL COMMENT 'When the score was fetched',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_vendor_onboarding (vendor_onboarding_id),
    INDEX idx_vendor_domain (vendor_domain),
    INDEX idx_scored_at (scored_at),
    INDEX idx_score (score),

    -- Foreign Key
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SRS Risk Details table
CREATE TABLE IF NOT EXISTS vendor_srs_risks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    srs_score_id INT UNSIGNED NOT NULL COMMENT 'Reference to vendor_srs_scores',

    -- Risk Details
    risk_id VARCHAR(255) NOT NULL COMMENT 'UpGuard risk identifier',
    risk_name VARCHAR(500) DEFAULT NULL COMMENT 'Human-readable risk name',
    risk_category VARCHAR(255) DEFAULT NULL COMMENT 'Risk category',
    severity VARCHAR(50) DEFAULT NULL COMMENT 'critical, high, medium, low, info',
    risk_host MEDIUMTEXT DEFAULT NULL COMMENT 'Hostname(s) or IP(s) where risk was detected',
    description TEXT DEFAULT NULL COMMENT 'Risk description',

    -- Timestamps
    first_seen DATETIME DEFAULT NULL COMMENT 'When risk was first detected',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_srs_score (srs_score_id),
    INDEX idx_risk_id (risk_id),
    INDEX idx_severity (severity),
    INDEX idx_risk_category (risk_category),

    -- Foreign Key
    FOREIGN KEY (srs_score_id) REFERENCES vendor_srs_scores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendor Subdomain Scores (UpGuard /vendor/domains)
CREATE TABLE IF NOT EXISTS vendor_subdomain_scores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_onboarding_id INT UNSIGNED NOT NULL COMMENT 'FK to vendor_onboarding_requests',
    vendor_domain VARCHAR(255) NOT NULL COMMENT 'Parent domain (e.g. hackrange.com)',
    subdomain VARCHAR(255) NOT NULL COMMENT 'Subdomain/domain found (e.g. mail.hackrange.com)',
    score INT DEFAULT NULL COMMENT 'UpGuard numeric score (0-950)',
    score_grade VARCHAR(10) DEFAULT NULL COMMENT 'Letter grade (A-F)',
    is_active TINYINT(1) DEFAULT 1 COMMENT 'Active/inactive flag',
    last_scanned DATETIME DEFAULT NULL COMMENT 'When UpGuard last scanned it',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vendor_subdomain (vendor_onboarding_id, subdomain),
    INDEX idx_vendor_onboarding (vendor_onboarding_id),
    INDEX idx_vendor_domain (vendor_domain),
    INDEX idx_subdomain (subdomain),
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 7B: SHODAN SRS SCORING
-- =============================================================================

-- Shodan Score History table
CREATE TABLE IF NOT EXISTS vendor_shodan_scores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_onboarding_id INT UNSIGNED NOT NULL,
    vendor_domain VARCHAR(255) NOT NULL,
    score INT NOT NULL COMMENT 'Shodan security score (0-100)',
    score_grade VARCHAR(10) DEFAULT NULL,
    open_ports_count INT DEFAULT 0,
    vuln_count INT DEFAULT 0,
    critical_vulns INT DEFAULT 0,
    high_vulns INT DEFAULT 0,
    medium_vulns INT DEFAULT 0,
    low_vulns INT DEFAULT 0,
    category_scores JSON DEFAULT NULL COMMENT 'Per-category scores: tls_crypto, network_security, app_hardening, vuln_exposure, email_security',
    traffic_light VARCHAR(10) DEFAULT NULL COMMENT 'Traffic light rating: green, yellow, red',
    positive_count INT DEFAULT 0 COMMENT 'Number of positive security signals detected',
    negative_count INT DEFAULT 0 COMMENT 'Number of negative security signals detected',
    subdomains_scanned JSON DEFAULT NULL COMMENT 'List of subdomains included in the scan',
    ip_addresses JSON DEFAULT NULL,
    scored_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vendor_onboarding (vendor_onboarding_id),
    INDEX idx_vendor_domain (vendor_domain),
    INDEX idx_scored_at (scored_at),
    INDEX idx_score (score),
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shodan Finding Details table
CREATE TABLE IF NOT EXISTS vendor_shodan_findings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shodan_score_id INT UNSIGNED NOT NULL,
    finding_type ENUM(
        'open_port', 'vulnerability', 'service',
        'tls_crypto', 'network_security', 'app_hardening',
        'email_security', 'positive_signal', 'negative_signal'
    ) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    port INT DEFAULT NULL,
    protocol VARCHAR(20) DEFAULT NULL,
    service_name VARCHAR(255) DEFAULT NULL,
    cve_id VARCHAR(50) DEFAULT NULL,
    cvss_score DECIMAL(3,1) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    severity VARCHAR(20) DEFAULT NULL COMMENT 'Finding severity level',
    signal_type VARCHAR(10) DEFAULT NULL COMMENT 'positive or negative',
    category VARCHAR(30) DEFAULT NULL COMMENT 'Scoring category: tls_crypto, network_security, etc.',
    points INT DEFAULT NULL COMMENT 'Points added or deducted for this finding',
    confidence VARCHAR(10) DEFAULT NULL COMMENT 'Signal confidence: high, medium, low',
    subdomain VARCHAR(255) DEFAULT NULL COMMENT 'Subdomain this finding was detected on',
    proof TEXT DEFAULT NULL COMMENT 'JSON proof/evidence data from Shodan',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_shodan_score (shodan_score_id),
    INDEX idx_finding_type (finding_type),
    INDEX idx_cve_id (cve_id),
    FOREIGN KEY (shodan_score_id) REFERENCES vendor_shodan_scores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shodan Risk Waivers (per-vendor, per-subdomain false positive management)
CREATE TABLE IF NOT EXISTS vendor_shodan_waivers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_onboarding_id INT UNSIGNED NOT NULL,
    signal_name VARCHAR(100) NOT NULL COMMENT 'Signal identifier e.g. tls_1_0_enabled, server_version_disclosed',
    category VARCHAR(30) NOT NULL COMMENT 'Scoring category: tls_crypto, network_security, etc.',
    subdomain VARCHAR(255) NOT NULL COMMENT 'Subdomain this waiver applies to (per-subdomain scope)',
    label VARCHAR(255) DEFAULT NULL COMMENT 'Human-readable signal label',
    reason TEXT DEFAULT NULL COMMENT 'User-provided justification for the waiver',
    waived_by VARCHAR(255) DEFAULT NULL COMMENT 'Username of who created the waiver',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vendor (vendor_onboarding_id),
    INDEX idx_signal_lookup (vendor_onboarding_id, signal_name, subdomain),
    UNIQUE KEY uk_waiver (vendor_onboarding_id, signal_name, subdomain),
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shodan CVE Waivers (vendor-wide CVE false positive management)
CREATE TABLE IF NOT EXISTS vendor_shodan_cve_waivers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_onboarding_id INT UNSIGNED NOT NULL,
    cve_id VARCHAR(50) NOT NULL,
    reason TEXT DEFAULT NULL,
    waived_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cve_waiver (vendor_onboarding_id, cve_id),
    INDEX idx_cve_waiver_vendor (vendor_onboarding_id),
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendor Technology Inventory (4th Party Risk - extracted from Shodan scan data)
CREATE TABLE IF NOT EXISTS vendor_technologies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_onboarding_id INT UNSIGNED NOT NULL,
    vendor_domain VARCHAR(255) NOT NULL,
    technology_name VARCHAR(255) NOT NULL,
    technology_category VARCHAR(50) NOT NULL COMMENT 'web_server, cdn_waf, cloud_platform, framework, js_library, database, email_gateway, dns_provider, programming_language, cms, ssl_ca, admin_panel, other',
    technology_version VARCHAR(100) DEFAULT NULL,
    detected_on VARCHAR(255) DEFAULT NULL COMMENT 'Hostname/subdomain where detected',
    detected_port INT DEFAULT NULL,
    detection_method VARCHAR(50) DEFAULT NULL COMMENT 'banner_product, http_server, http_component, http_header, asn_org, dns_mx, dns_ns, open_port, ssl_cert, cpe',
    detection_confidence VARCHAR(10) DEFAULT 'medium' COMMENT 'high, medium, low',
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    is_current TINYINT(1) DEFAULT 1,
    raw_evidence TEXT DEFAULT NULL COMMENT 'JSON evidence data',
    cves TEXT DEFAULT NULL COMMENT 'JSON array of CVE IDs associated with this detection',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vendor_tech (vendor_onboarding_id, technology_name, technology_category, detected_on),
    INDEX idx_vendor_onboarding (vendor_onboarding_id),
    INDEX idx_vendor_domain (vendor_domain),
    INDEX idx_technology_name (technology_name),
    INDEX idx_technology_category (technology_category),
    INDEX idx_is_current (is_current),
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendor Subprocessors (4th Party supply-chain entities, distinct from technology scanning)
CREATE TABLE IF NOT EXISTS vendor_subprocessors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subprocessor_name VARCHAR(500) NOT NULL,
    subprocessor_domain VARCHAR(255) DEFAULT NULL,
    country VARCHAR(100) DEFAULT NULL,
    linked_vendor_id INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_subprocessor_name (subprocessor_name(255)),
    INDEX idx_linked_vendor (linked_vendor_id),
    FOREIGN KEY (linked_vendor_id) REFERENCES vendor_onboarding_requests(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendor-to-Subprocessor mappings (junction table with per-vendor context)
CREATE TABLE IF NOT EXISTS vendor_subprocessor_mappings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_onboarding_id INT UNSIGNED NOT NULL,
    subprocessor_id INT UNSIGNED NOT NULL,
    service_description TEXT DEFAULT NULL,
    data_shared TEXT DEFAULT NULL,
    added_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vendor_subprocessor (vendor_onboarding_id, subprocessor_id),
    INDEX idx_subprocessor (subprocessor_id),
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (subprocessor_id) REFERENCES vendor_subprocessors(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 8: VENDOR ASSESSMENTS
-- =============================================================================

-- Assessment templates (ISO 27001:2022, Tier 2, etc.)
CREATE TABLE IF NOT EXISTS assessment_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    category VARCHAR(50) DEFAULT 'vendor_assessment' COMMENT 'vendor_assessment, procurement, onboarding',
    allow_certificate_upload TINYINT(1) DEFAULT 0,
    certificate_upload_prompt TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assessment sections/steps
CREATE TABLE IF NOT EXISTS assessment_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES assessment_templates(id) ON DELETE CASCADE,
    UNIQUE KEY uk_template_sort (template_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assessment questions
CREATE TABLE IF NOT EXISTS assessment_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type VARCHAR(50) NOT NULL DEFAULT 'text',
    options TEXT COMMENT 'JSON array of options for select/radio/checkbox',
    is_required TINYINT(1) DEFAULT 1,
    help_text TEXT,
    sort_order INT DEFAULT 0,
    depends_on_question_id INT DEFAULT NULL COMMENT 'Show only when referenced question has specific value',
    depends_on_value VARCHAR(255) DEFAULT NULL COMMENT 'Required value of parent question for this to show',
    field_name VARCHAR(100) DEFAULT NULL COMMENT 'Maps to vendor_onboarding_requests column for onboarding templates',
    triggers_assessment_template_id INT DEFAULT NULL COMMENT 'Auto-create assessment from this template when trigger value matches',
    triggers_on_value VARCHAR(255) DEFAULT NULL COMMENT 'Response value that triggers the linked assessment',
    FOREIGN KEY (section_id) REFERENCES assessment_sections(id) ON DELETE CASCADE,
    UNIQUE KEY uk_section_sort (section_id, sort_order),
    INDEX idx_depends_on (depends_on_question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendor assessment instances (sent to vendors)
CREATE TABLE IF NOT EXISTS vendor_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE COMMENT 'Public access token for vendor',
    template_id INT NOT NULL,
    vendor_request_id INT UNSIGNED COMMENT 'Link to vendor_onboarding_requests',
    vendor_name VARCHAR(255) NOT NULL,
    vendor_email VARCHAR(255) NOT NULL,
    vendor_contact_name VARCHAR(255) COMMENT 'Contact person name',
    vendor_contact_email VARCHAR(255) COMMENT 'Contact person email',
    status VARCHAR(50) DEFAULT 'pending' COMMENT 'pending, in_progress, completed',
    current_section_id INT COMMENT 'Current section for progress tracking',
    certificate_uploaded TINYINT(1) DEFAULT 0,
    certificate_path VARCHAR(500) COMMENT 'Path or db:ID reference for certificate',
    certificate_expiry DATE,
    started_at DATETIME,
    completed_at DATETIME,
    expires_at DATETIME,
    triggered_by_assessment_id INT DEFAULT NULL COMMENT 'Assessment that triggered this one',
    triggered_by_question_id INT DEFAULT NULL COMMENT 'Question whose answer triggered this assessment',
    triggered_by_rule_id INT DEFAULT NULL COMMENT 'Workflow rule that triggered this assessment',
    created_by INT UNSIGNED,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES assessment_templates(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (vendor_request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_uuid (uuid),
    INDEX idx_vendor_request (vendor_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendor assessment responses
CREATE TABLE IF NOT EXISTS vendor_assessment_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    question_id INT NOT NULL,
    response_value TEXT,
    file_path VARCHAR(500) COMMENT 'Path or db:ID reference for file uploads',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assessment_id) REFERENCES vendor_assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES assessment_questions(id),
    UNIQUE KEY unique_response (assessment_id, question_id),
    INDEX idx_assessment (assessment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assessment files (encrypted file storage in database)
CREATE TABLE IF NOT EXISTS assessment_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_uuid VARCHAR(36) NOT NULL COMMENT 'Public-facing UUID for file references',
    assessment_id INT NOT NULL,
    file_type VARCHAR(50) NOT NULL DEFAULT 'attachment' COMMENT 'attachment, certificate',
    question_id INT COMMENT 'NULL for certificate uploads',
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL COMMENT 'Size in bytes',
    encrypted_data LONGBLOB NOT NULL COMMENT 'AES-256 encrypted file data',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assessment_id) REFERENCES vendor_assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES assessment_questions(id) ON DELETE SET NULL,
    UNIQUE KEY uk_file_uuid (file_uuid),
    INDEX idx_assessment (assessment_id),
    INDEX idx_file_type (file_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assessment workflow rules (configurable triggers that auto-create follow-up assessments)
CREATE TABLE IF NOT EXISTS assessment_workflow_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Human-readable rule name',
    description TEXT DEFAULT NULL,
    source_template_id INT NOT NULL COMMENT 'Template that triggers this rule on completion',
    question_id INT NOT NULL COMMENT 'Question whose response is evaluated',
    condition_operator VARCHAR(20) DEFAULT 'equals' COMMENT 'equals, not_equals, contains',
    condition_value VARCHAR(255) NOT NULL COMMENT 'Value to match against',
    target_template_id INT NOT NULL COMMENT 'Template to auto-create when rule matches',
    assign_to VARCHAR(20) DEFAULT 'vendor' COMMENT 'Who receives the triggered assessment: vendor, stakeholder, creator',
    is_active TINYINT(1) DEFAULT 1,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (source_template_id) REFERENCES assessment_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES assessment_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (target_template_id) REFERENCES assessment_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendor documents (encrypted file storage for contracts, certifications, etc.)
CREATE TABLE IF NOT EXISTS vendor_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_uuid VARCHAR(36) NOT NULL COMMENT 'Public-facing UUID for download links',
    vendor_request_id INT UNSIGNED NOT NULL COMMENT 'FK to vendor_onboarding_requests',
    document_type VARCHAR(20) NOT NULL COMMENT 'contract, certification, or other',
    contract_name VARCHAR(255) DEFAULT NULL COMMENT 'Contract display name',
    contract_type VARCHAR(50) DEFAULT NULL COMMENT 'DPA, Master Service Agreement, Privacy, Order Form, PO',
    contract_creation_date DATE DEFAULT NULL,
    contract_expiration_date DATE DEFAULT NULL,
    contract_pricing JSON DEFAULT NULL COMMENT 'Pricing details for Order Form/PO contracts',
    certification_type VARCHAR(100) DEFAULT NULL COMMENT 'SOC 2 Type II, ISO 27001, etc.',
    certification_expiration_date DATE DEFAULT NULL COMMENT 'Expiration date for certifications',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Active, 0=Inactive',
    description TEXT DEFAULT NULL COMMENT 'Description for other document type',
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL COMMENT 'Size in bytes',
    encrypted_data LONGBLOB NOT NULL COMMENT 'AES-256 encrypted file data',
    uploaded_by INT UNSIGNED NOT NULL COMMENT 'FK to users',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_file_uuid (file_uuid),
    INDEX idx_vendor_request (vendor_request_id),
    INDEX idx_document_type (document_type),
    INDEX idx_contract_expiration (contract_expiration_date),
    INDEX idx_certification_type (certification_type),
    INDEX idx_cert_expiration (certification_expiration_date),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (vendor_request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contract expiry reminder tracking table
CREATE TABLE IF NOT EXISTS vendor_contract_reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_document_id INT UNSIGNED NOT NULL,
    recipient_user_id INT UNSIGNED NOT NULL,
    reminder_type VARCHAR(30) NOT NULL,
    expiration_date DATE NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    email_sent_to VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','sent','failed') DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_vcr_vendor_document (vendor_document_id),
    INDEX idx_vcr_recipient (recipient_user_id),
    INDEX idx_vcr_status (status),
    UNIQUE KEY unique_contract_reminder (vendor_document_id, recipient_user_id,
      reminder_type, expiration_date),
    FOREIGN KEY (vendor_document_id) REFERENCES vendor_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assessment reminder tracking table
CREATE TABLE IF NOT EXISTS vendor_assessment_reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    reminder_type ENUM('initial','7_days_before','3_days_before','expiry_day') NOT NULL,
    expires_at DATE NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    email_sent_to VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','sent','failed') DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_var_assessment (assessment_id),
    INDEX idx_var_status (status),
    UNIQUE KEY unique_assessment_reminder (assessment_id, reminder_type, expires_at),
    FOREIGN KEY (assessment_id) REFERENCES vendor_assessments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email templates (customizable email content for vendor/stakeholder communications)
CREATE TABLE IF NOT EXISTS email_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_category VARCHAR(30) NOT NULL COMMENT 'vendor, stakeholder, or procurement',
    template_key VARCHAR(100) NOT NULL COMMENT 'Machine identifier e.g. assessment_request, 30_days_before',
    assessment_template_id INT DEFAULT NULL COMMENT 'FK to assessment_templates.id (NULL = generic/default)',
    display_name VARCHAR(255) NOT NULL COMMENT 'Human-readable name shown in admin UI',
    email_subject VARCHAR(500) NOT NULL COMMENT 'Email subject line with {{placeholder}} support',
    email_body_html MEDIUMTEXT NOT NULL COMMENT 'HTML email body with {{placeholder}} support',
    email_body_text TEXT NOT NULL COMMENT 'Plain-text fallback body with {{placeholder}} support',
    available_variables TEXT DEFAULT NULL COMMENT 'JSON array of available placeholder variable names',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_template (template_category, template_key, assessment_template_id),
    INDEX idx_category (template_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 9: AUDIT LOGGING
-- =============================================================================

-- Audit log table for security tracking
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(100),
    record_id INT UNSIGNED,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_table_name (table_name),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 10: CRON JOB EXECUTION HISTORY
-- =============================================================================

-- Cron job execution history for monitoring and troubleshooting
CREATE TABLE IF NOT EXISTS cron_execution_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL COMMENT 'e.g., srs-rescore, assessment-reminder',
    started_at DATETIME NOT NULL,
    completed_at DATETIME,
    status ENUM('running', 'completed', 'failed') DEFAULT 'running',
    processed_count INT DEFAULT 0,
    success_count INT DEFAULT 0,
    failed_count INT DEFAULT 0,
    error_messages TEXT COMMENT 'JSON array of error messages',
    execution_time_seconds DECIMAL(10,3) COMMENT 'Total execution time',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_name (job_name),
    INDEX idx_started_at (started_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 10B: CYBER TODO ACTIVITIES
-- Tracks activities, notes, and assignments on todo items.
-- =============================================================================

CREATE TABLE IF NOT EXISTS cyber_todo_activities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Reference to the related entity
    todo_type ENUM('cert_expiry', 'srs_rescore', 'score_drop', 'annual_review', 'not_approved', 'not_tiered', 'custom', 'user_import', 'vendor_import', 'contract_expiry') NOT NULL,
    reference_type VARCHAR(50) NOT NULL COMMENT 'Table name: vendor_assessments, vendor_onboarding_requests, etc.',
    reference_id INT UNSIGNED NOT NULL COMMENT 'ID of the related record',

    -- Activity details
    activity_type ENUM('note', 'action', 'status_change', 'reminder', 'import', 'revert') DEFAULT 'note',
    title VARCHAR(255) DEFAULT NULL,
    description TEXT NOT NULL,
    metadata JSON DEFAULT NULL COMMENT 'Stores import data for revert capability',

    -- Status tracking
    status ENUM('open', 'in_progress', 'closed', 'deferred', 'reverted') DEFAULT 'open',
    due_date DATE DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,

    -- User tracking
    created_by INT UNSIGNED NOT NULL,
    closed_by INT UNSIGNED DEFAULT NULL,
    assigned_to INT UNSIGNED DEFAULT NULL COMMENT 'User this activity is assigned to',

    -- Threading
    parent_id INT UNSIGNED DEFAULT NULL COMMENT 'Parent case ID for threaded responses',

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_todo_type (todo_type),
    INDEX idx_reference (reference_type, reference_id),
    INDEX idx_status (status),
    INDEX idx_created_by (created_by),
    INDEX idx_due_date (due_date),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_parent_id (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 11: PERFORMANCE INDEXES
-- =============================================================================

-- Additional composite indexes for query performance
-- On fresh install these won't exist yet, so plain CREATE INDEX is safe.
CREATE INDEX idx_tprm_vendor_user ON tprm_results(vendor_name, user_id);
CREATE INDEX idx_tprm_status_created ON tprm_results(status, created_at);
CREATE INDEX idx_sessions_activity ON sessions(last_activity);

-- =============================================================================
-- SECTION 12: DEFAULT DATA
-- =============================================================================

-- Default admin user (password replaced by entrypoint.sh with generated password)
INSERT INTO users (username, password_hash, email, full_name, is_active, is_admin, is_super_admin, totp_enabled)
VALUES (
    'admin',
    'CHANGEME_ADMIN_HASH',
    'admin@example.com',
    'System Administrator',
    1,
    1,
    1,
    0
) ON DUPLICATE KEY UPDATE username = username;

-- Default ACL groups (must be seeded BEFORE user_acl_groups assignment below)
INSERT INTO acl_groups (group_name, display_name, description, is_active, is_system) VALUES
('administrator', 'Administrator', 'Full administrative access to all features', 1, 1),
('cyber_tprm', 'Cyber TPRM', 'Cyber Third Party Risk Management team - can manage vendor assessments and FAIR analysis', 1, 1),
('procurement', 'Procurement', 'Procurement team - can create and manage vendor onboarding requests', 1, 1),
('stakeholder', 'Stakeholder', 'Stakeholders - can view and update assigned vendor onboarding requests', 1, 1)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), is_system = VALUES(is_system);

-- Assign admin to administrator group (after both users and acl_groups are seeded)
INSERT IGNORE INTO user_acl_groups (user_id, group_id, assigned_by)
VALUES (1, 1, 1);

-- Default ACL permissions
INSERT INTO acl_permissions (permission_code, name, module, resource, action, description) VALUES
-- Onboarding permissions
('onboarding.create', 'Create Onboarding Request', 'onboarding', 'request', 'create', 'Create new vendor onboarding requests'),
('onboarding.read', 'View All Onboarding Requests', 'onboarding', 'request', 'read', 'View all vendor onboarding requests'),
('onboarding.read_own', 'View Own Onboarding Requests', 'onboarding', 'request', 'read_own', 'View own vendor onboarding requests'),
('onboarding.read_assigned', 'View Assigned Onboarding Requests', 'onboarding', 'request', 'read_assigned', 'View assigned vendor onboarding requests'),
('onboarding.update', 'Update All Onboarding Requests', 'onboarding', 'request', 'update', 'Update all vendor onboarding requests'),
('onboarding.update_own', 'Update Own Onboarding Requests', 'onboarding', 'request', 'update_own', 'Update own vendor onboarding requests'),
('onboarding.update_assigned', 'Update Assigned Onboarding Requests', 'onboarding', 'request', 'update_assigned', 'Update assigned vendor onboarding requests'),
('onboarding.deactivate', 'Deactivate Onboarding Requests', 'onboarding', 'request', 'deactivate', 'Deactivate vendor onboarding requests'),
('onboarding.delete', 'Delete Onboarding Requests', 'onboarding', 'request', 'delete', 'Delete vendor onboarding requests'),
('onboarding.assign_stakeholder', 'Assign Stakeholders', 'onboarding', 'request', 'assign_stakeholder', 'Assign stakeholders to vendor onboarding requests'),
-- Analysis permissions
('analysis.create', 'Create FAIR Analysis', 'analysis', 'fair', 'create', 'Create FAIR risk analyses'),
('analysis.read', 'View FAIR Analysis', 'analysis', 'fair', 'read', 'View FAIR risk analyses'),
-- Assessment permissions
('assessment.create', 'Create Assessments', 'assessment', 'vendor', 'create', 'Create vendor security assessments'),
('assessment.read', 'View Assessments', 'assessment', 'vendor', 'read', 'View vendor security assessments'),
('assessment.update', 'Update Assessments', 'assessment', 'vendor', 'update', 'Update vendor security assessments'),
('assessment.delete', 'Delete Assessments', 'assessment', 'vendor', 'delete', 'Delete vendor security assessments'),
-- SRS permissions
('srs.view', 'View SRS Scores', 'srs', 'score', 'read', 'View vendor SRS security scores'),
('srs.rescore', 'Trigger Rescore', 'srs', 'score', 'update', 'Manually trigger vendor rescoring'),
-- Annual Review permissions
('annual_review.read', 'View All Annual Reviews', 'annual_review', 'review', 'read', 'View all vendor annual reviews'),
('annual_review.create', 'Complete Annual Reviews', 'annual_review', 'review', 'create', 'Complete annual vendor reviews'),
('annual_review.read_assigned', 'View Assigned Annual Reviews', 'annual_review', 'review', 'read_assigned', 'View annual reviews for assigned vendors only')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Assign permissions to groups
-- Administrator gets ALL permissions
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'administrator'), id, 1 FROM acl_permissions;

-- Cyber TPRM gets all permissions
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'cyber_tprm'), id, 1 FROM acl_permissions;

-- Procurement gets create, read all, update own, assign stakeholder, view analysis, view assessments, view SRS, annual reviews
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'procurement'), id, 1
FROM acl_permissions WHERE permission_code IN (
    'onboarding.create', 'onboarding.read', 'onboarding.update_own',
    'onboarding.assign_stakeholder', 'analysis.read', 'assessment.read', 'srs.view',
    'annual_review.read', 'annual_review.create'
);

-- Stakeholder gets create, read own, read assigned, update own, update assigned, annual review assigned
INSERT IGNORE INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'stakeholder'), id, 1
FROM acl_permissions WHERE permission_code IN (
    'onboarding.create', 'onboarding.read_own', 'onboarding.read_assigned',
    'onboarding.update_own', 'onboarding.update_assigned',
    'annual_review.read_assigned', 'annual_review.create'
);

-- =============================================================================
-- SECTION 13: APPLICATION CONFIGURATION DEFAULTS
-- =============================================================================

INSERT INTO app_config (config_key, config_value, is_encrypted, description) VALUES
-- Authentication settings
('auth_type', 'local', 0, 'Authentication type: local or saml'),
('session_timeout', '3600', 0, 'Session timeout in seconds'),
('max_login_attempts', '5', 0, 'Maximum failed login attempts before lockout'),
('lockout_duration', '1800', 0, 'Account lockout duration in seconds'),
('password_min_length', '12', 0, 'Minimum password length'),
('require_password_complexity', '1', 0, 'Require complex passwords'),
('totp_issuer', 'TPRM FAIR Analysis', 0, 'TOTP issuer name'),
('app_timezone', 'America/New_York', 0, 'Application timezone for displaying dates and times'),

-- FAIR Analysis cost settings
('pii_breach_cost_per_record', '160', 0, 'Cost per PII record if breached'),
('spii_breach_cost_per_record', '200', 0, 'Cost per SPII record if breached'),
('sox_breach_penalty', '5000000', 0, 'SOX compliance penalty if breached'),

-- Theme/Branding settings
('logo_url', 'app/images/logo-default-418x78.png', 0, 'Application-wide header logo URL'),
('footer_logo_url', 'app/images/logo-inverse-416x78.png', 0, 'Application-wide footer logo URL'),
('header_color', '#35a0a3', 0, 'Application-wide header color'),
('footer_color', '#1a365d', 0, 'Application-wide footer color'),
('button_color', '#35a0a3', 0, 'Application-wide button color'),
('nav_fill_color', '#e9ecef', 0, 'Application-wide navigation fill color'),
('nav_font_color', '#1f1e1e', 0, 'Application-wide navigation font color'),
('nav_width', '220', 0, 'Application-wide navigation width in pixels'),

-- AI Integration settings
('openwebui_enabled', '0', 0, 'Enable/disable OpenWebUI AI integration'),
('openwebui_api_url', '', 0, 'OpenWebUI API endpoint URL'),
('openwebui_jwt_token', '', 0, 'OpenWebUI JWT authentication token (encrypted)'),
('openwebui_model', '', 0, 'OpenWebUI model for generation'),
('openwebui_temperature', '0.3', 0, 'Model temperature (0.0-1.0)'),
('openwebui_max_tokens', '4096', 0, 'Maximum tokens for response generation'),
('fair_ai_enabled', '1', 0, 'Enable AI-Assisted FAIR Analysis button'),

-- Vendor Revalidation settings
('revalidation_tier1_frequency_days', '30', 0, 'Tier 1 vendor revalidation frequency in days'),
('revalidation_tier2_frequency_days', '90', 0, 'Tier 2 vendor revalidation frequency in days'),
('revalidation_tier3_frequency_days', '365', 0, 'Tier 3 vendor revalidation frequency in days'),
('revalidation_reminder_days', '7', 0, 'Send reminder email X days before revalidation due'),
('revalidation_escalation_days', '14', 0, 'Escalate if overdue by X days'),

-- UpGuard SRS Integration settings
('upguard_enabled', '0', 0, 'Whether UpGuard SRS integration is enabled'),
('upguard_api_key', '', 1, 'UpGuard API key (encrypted)'),
('upguard_scoring_method', 'range', 0, 'Scoring method: range or percentage'),
('upguard_max_score', '950', 0, 'Maximum possible score'),
('upguard_grade_a_min', '850', 0, 'Minimum score for grade A'),
('upguard_grade_b_min', '700', 0, 'Minimum score for grade B'),
('upguard_grade_c_min', '500', 0, 'Minimum score for grade C'),
('upguard_grade_d_min', '300', 0, 'Minimum score for grade D'),
('upguard_tier1_days', '30', 0, 'Tier 1 rescore interval in days'),
('upguard_tier2_days', '90', 0, 'Tier 2 rescore interval in days'),
('upguard_tier3_days', '365', 0, 'Tier 3 rescore interval in days'),
('upguard_trending_days', '90', 0, 'Number of days for trend charts'),
('upguard_vendor_domains', '0', 0, 'Enable vendor subdomain scoring via UpGuard'),
('upguard_org_domain', '', 0, 'Organization primary domain for BreachSight API routing'),

-- Shodan SRS Integration settings
('shodan_enabled', '0', 0, 'Whether Shodan SRS integration is enabled'),
('shodan_api_key', '', 1, 'Shodan API key (encrypted)'),
('shodan_scoring_method', 'range', 0, 'Scoring method: range or percentage'),
('shodan_max_score', '100', 0, 'Maximum possible Shodan score'),
('shodan_grade_a_min', '90', 0, 'Minimum score for grade A'),
('shodan_grade_b_min', '75', 0, 'Minimum score for grade B'),
('shodan_grade_c_min', '60', 0, 'Minimum score for grade C'),
('shodan_grade_d_min', '40', 0, 'Minimum score for grade D'),
('shodan_on_demand_scan', '0', 0, 'Enable on-demand Shodan scanning for IPs with no existing data'),

-- Custom Scoring settings
('custom_scoring_enabled', '0', 0, 'Whether custom manual scoring is enabled'),

-- Email/SMTP settings for annual review reminders
('email_enabled', '0', 0, 'Enable/disable email notifications (0=disabled, 1=enabled)'),
('smtp_host', 'localhost', 0, 'SMTP server hostname'),
('smtp_port', '25', 0, 'SMTP server port (25, 587, 465)'),
('smtp_username', '', 0, 'SMTP authentication username'),
('smtp_password', '', 1, 'SMTP authentication password (encrypted)'),
('smtp_encryption', 'none', 0, 'SMTP encryption type (none, tls, ssl)'),
('email_from_email', 'noreply@example.com', 0, 'From email address for system notifications'),
('email_from_name', 'TPRM System', 0, 'From name for system notifications'),

-- General application settings
('app_url', '', 0, 'Public application URL for email links and cron jobs (e.g. https://demo.fairtprm.com)'),
('company_name', '', 0, 'Company name displayed in emails and footers'),
('log_retention_days', '90', 0, 'Number of days to retain audit log entries (0 = indefinite)'),

-- Procurement notification settings
('procurement_notifications_enabled', '0', 0, 'Enable/disable procurement contract expiry email notifications'),
('procurement_notification_recipients', 'all', 0, 'Procurement notification recipients: all, selected, or none'),
('procurement_notification_warning_days', '30', 0, 'Days before contract expiry to start sending warnings'),
('procurement_notification_selected_users', '[]', 0, 'JSON array of selected user IDs for procurement notifications')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- =============================================================================
-- SECTION 14: ASSESSMENT TEMPLATES DATA
-- =============================================================================

INSERT INTO assessment_templates (name, slug, description, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('ISO 27001:2022 Assessment', 'iso-27001-2022', 'Comprehensive information security management system assessment based on ISO 27001:2022 standard.', 1, 'If your organization holds a valid ISO 27001:2022 certification, you may upload it here to skip the detailed assessment. Please ensure the certificate is current and includes your organization name.', 1),
('Tier 2 Vendor Assessment', 'tier-2-vendor', 'Streamlined security assessment for lower-risk vendor relationships.', 0, NULL, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Vendor Onboarding Request template (category = onboarding)
INSERT IGNORE INTO assessment_templates (name, slug, description, category, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('Vendor Onboarding Request', 'vendor-onboarding', 'Standard vendor onboarding intake form for collecting vendor information, data handling practices, and risk assessment details.', 'onboarding', 0, NULL, 1);

-- Vendor Onboarding Sections
INSERT IGNORE INTO assessment_sections (template_id, name, description, sort_order) VALUES
((SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding'), 'Vendor Information', 'Basic vendor details, contacts, and relationship information.', 1),
((SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding'), 'Data & Risk Assessment', 'Data handling practices, security controls, and impact assessment.', 2),
((SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding'), 'Additional Information', 'Any other relevant details about the vendor engagement.', 3);

-- Vendor Onboarding Questions - Section 1: Vendor Information (16 questions)
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order, field_name) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the vendor''s legal name?', 'text', NULL, 1, NULL, 1, 'vendor_name'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the vendor''s primary domain?', 'text', NULL, 0, 'e.g., vendor.com', 2, 'vendor_domain'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What other Domain Names are associated with this vendor?', 'textarea', NULL, 0, 'Enter one domain per line. These sister domains will be included in Shodan security scanning.', 3, 'vendor_sisterdomains'),
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
'How many users will use this product/service?', 'number', NULL, 1, 'e.g., 50, 100-200', 10, 'target_user_count'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the primary contact email?', 'email', NULL, 1, NULL, 11, 'primary_contact_email'),
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

-- Vendor Onboarding Questions - Section 2: Data & Risk Assessment (23 questions)
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
'Does this solution use Artificial Intelligence?', 'button_group', '["Yes", "No"]', 1, NULL, 23, 'vendor_use_ai');

-- Vendor Onboarding Questions - Section 3: Additional Information
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order, field_name) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 3),
'Is there any additional information you would like to share about this vendor engagement?', 'textarea', NULL, 0, NULL, 1, 'additional_information');

-- AI Usage Assessment template (category = vendor_assessment)
INSERT IGNORE INTO assessment_templates (name, slug, description, category, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('AI Usage', 'ai-usage', 'Assessment for evaluating vendor AI usage, data handling practices, and third-party AI provider risks.', 'vendor_assessment', 0, NULL, 1);

-- AI Usage Sections
INSERT IGNORE INTO assessment_sections (template_id, name, description, sort_order) VALUES
((SELECT id FROM assessment_templates WHERE slug = 'ai-usage'), 'AI Usage Overview', '', 1),
((SELECT id FROM assessment_templates WHERE slug = 'ai-usage'), 'Data Handling & Privacy', '', 2),
((SELECT id FROM assessment_templates WHERE slug = 'ai-usage'), 'Third-Party & Subprocessor Risk', '', 3);

-- AI Usage Questions - Section 1: AI Usage Overview
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1),
'Do you use Artificial Intelligence (AI) as part of your service offering?', 'button_group', '["Yes","No"]', 1, 'Explain whether AI is used in your product or internal operations. If yes, describe what it does (e.g., data analysis, chatbots, document processing, automation, etc.).', 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1),
'What type of AI technologies are used?', 'textarea', NULL, 1, 'Identify the type of AI used (e.g., Large Language Models (LLMs), machine learning, generative AI, computer vision, predictive analytics). List any platforms such as AWS Bedrock, Azure OpenAI, Google Vertex AI, OpenAI API, Anthropic, etc.', 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1),
'Is AI functionality customer-facing or internal-only?', 'button_group', '["Yes","No"]', 1, 'Clarify whether customers directly interact with the AI (e.g., chatbot), or if it is used only internally by your staff.', 3);

-- AI Usage Questions - Section 2: Data Handling & Privacy
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2),
'What types of data are processed by AI systems?', 'textarea', NULL, 1, 'Specify whether AI processes personal data, confidential data, financial data, regulated data (HIPAA, PCI, etc.), or customer proprietary information.', 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2),
'Is customer data used to train or improve AI models?', 'button_group', '["Yes","No"]', 1, 'Clearly state whether customer data is used for model training, fine-tuning, reinforcement learning, or analytics. If yes, explain how consent is obtained and how data is protected.', 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2),
'How is AI training data stored and protected?', 'textarea', NULL, 1, 'Describe where training data is stored (cloud/on-prem), how it is encrypted (at rest and in transit), and who has access.', 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2),
'Do you use Retrieval-Augmented Generation (RAG) or data embeddings?', 'button_group', '["Yes","No"]', 1, NULL, 4),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2),
'Explain how vector databases or RAG collections are stored, secured, and isolated between customers. Confirm whether customer data is segregated.', 'textarea', NULL, 1, NULL, 5),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2),
'Provide data retention periods for prompts, outputs, logs, embeddings, and training datasets.', 'textarea', NULL, 1, NULL, 6);

-- AI Usage Questions - Section 3: Third-Party & Subprocessor Risk
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3),
'What third-party AI providers are used?', 'button_group_multi', '["AWS Bedrock","Azure AI Foundary","Google Vertex AI","OpenAI","Anthropic","Novita AI","Others"]', 1, 'Select all third-party AI services used (e.g., AWS Bedrock, Azure AI Foundry, Google Vertex AI, OpenAI, Anthropic). Confirm whether customer data is shared with them.', 1),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3),
'Other AI Providers', 'textarea', NULL, 1, 'List out other Third-Party AI Providers not listed above that you use', 2),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3),
'Where is AI data processed geographically?', 'textarea', NULL, 1, 'Specify the countries/regions where AI data is processed or stored.', 3),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3),
'Do subprocessors have the right to use data for model improvement?', 'button_group', '["Yes","No"]', 1, 'Confirm whether your AI providers use submitted data to improve their global models.', 4),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3),
'Do you rely on third-party AI providers?', 'button_group', '["Yes","No"]', 1, NULL, 5);

-- AI Usage conditional dependencies (questions shown based on prior answers)
-- Section 1: "What type of AI?" and "Customer-facing?" depend on "Do you use AI?" = Yes
UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1) AND sort_order = 1),
    depends_on_value = 'Yes'
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1)
  AND sort_order IN (2, 3)
  AND depends_on_question_id IS NULL;

-- Section 2: Most questions depend on "Do you use AI?" = Yes
UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1) AND sort_order = 1),
    depends_on_value = 'Yes'
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2)
  AND sort_order IN (1, 4)
  AND depends_on_question_id IS NULL;

-- Section 2: "Is customer data used to train?" and "How is training data stored?" depend on AI=Yes (no specific value filter)
UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1) AND sort_order = 1)
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2)
  AND sort_order IN (2, 3)
  AND depends_on_question_id IS NULL;

-- Section 2: RAG sub-questions depend on "Do you use RAG?" = Yes
UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2) AND sort_order = 4),
    depends_on_value = 'Yes'
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2)
  AND sort_order IN (5, 6)
  AND depends_on_question_id IS NULL;

-- Section 3: "Third-party providers" and "Geographic processing" depend on AI=Yes (no specific value)
UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1) AND sort_order = 1)
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3)
  AND sort_order IN (1, 3)
  AND depends_on_question_id IS NULL;

-- Section 3: "Other AI Providers" depends on "Third-party providers" = Others
UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3) AND sort_order = 1),
    depends_on_value = 'Others'
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3)
  AND sort_order = 2
  AND depends_on_question_id IS NULL;

-- Section 3: "Do you rely on third-party AI?" depends on AI=Yes
UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1) AND sort_order = 1),
    depends_on_value = 'Yes'
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3)
  AND sort_order = 5
  AND depends_on_question_id IS NULL;

-- Field references table for assessment template field name management
CREATE TABLE IF NOT EXISTS field_references (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    field_name VARCHAR(100) NOT NULL,
    column_type VARCHAR(100) NOT NULL DEFAULT 'VARCHAR(500)',
    section VARCHAR(100) NOT NULL DEFAULT '',
    category VARCHAR(50) NOT NULL DEFAULT 'all',
    description TEXT,
    is_builtin TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_field_name (field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed built-in field references
INSERT IGNORE INTO field_references (field_name, column_type, section, category, description, is_builtin) VALUES
('vendor_name',                          'VARCHAR(500)',                              'Vendor Information',     'onboarding', 'Vendor legal name', 1),
('vendor_domain',                        'VARCHAR(255)',                              'Vendor Information',     'onboarding', 'Vendor domain name for SRS scoring', 1),
('vendor_sisterdomains',                 'TEXT',                                      'Vendor Information',     'onboarding', 'Related/sister domains for Shodan scanning (one per line)', 1),
('vendor_id',                            'VARCHAR(20)',                               'Vendor Information',     'onboarding', 'VSU Vendor ID (VID)', 1),
('vendor_type',                          'VARCHAR(255)',                              'Vendor Information',     'onboarding', 'Vendor category/type', 1),
('relationship_manager',                 'VARCHAR(255)',                              'Vendor Information',     'onboarding', 'Business relationship manager name', 1),
('expected_procurement_date',            'DATE',                                      'Vendor Information',     'onboarding', 'Expected procurement date', 1),
('product_service_description',          'TEXT',                                      'Vendor Information',     'onboarding', 'Product/service description (used in FAIR analysis)', 1),
('target_user_count',                    'VARCHAR(100)',                              'Vendor Information',     'onboarding', 'Target user count (e.g., 50, 100-200)', 1),
('primary_contact_email',                'VARCHAR(255)',                              'Vendor Information',     'onboarding', 'Primary contact email address', 1),
('primary_contact_details',              'TEXT',                                      'Vendor Information',     'onboarding', 'Primary contact full name', 1),
('primary_contact_title',                'VARCHAR(255)',                              'Vendor Information',     'onboarding', 'Primary contact job title', 1),
('primary_contact_phone',                'VARCHAR(50)',                               'Vendor Information',     'onboarding', 'Primary contact direct phone', 1),
('nda_in_place',                         "ENUM('yes','no','')",                       'Vendor Information',     'onboarding', 'Is NDA in place?', 1),
('vendor_competitors',                   'TEXT',                                      'Vendor Information',     'onboarding', 'Known vendor competitors', 1),
('vsu_onboarded',                        "ENUM('yes','no','')",                       'Vendor Information',     'onboarding', 'VSU procurement onboarding status', 1),
('pii_record_count',                     'INT UNSIGNED',                              'Data & Risk Assessment', 'onboarding', 'Number of PII records', 1),
('spii_record_count',                    'INT UNSIGNED',                              'Data & Risk Assessment', 'onboarding', 'Number of Sensitive PII records', 1),
('sox_record_count',                     'INT UNSIGNED',                              'Data & Risk Assessment', 'onboarding', 'Number of SOX-regulated records', 1),
('business_impact',                      'DECIMAL(15,2)',                             'Data & Risk Assessment', 'onboarding', 'Business impact value in USD (used in FAIR analysis)', 1),
('confidential_info_shared',             "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Will confidential info be shared?', 1),
('confidential_info_justification',      'TEXT',                                      'Data & Risk Assessment', 'onboarding', 'Confidential info justification', 1),
('cross_border_transfer',                "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Cross-border data transfer?', 1),
('cross_border_justification',           'TEXT',                                      'Data & Risk Assessment', 'onboarding', 'Cross-border transfer details', 1),
('offsite_data_hosting',                 "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Off-site data hosting?', 1),
('offsite_data_justification',           'TEXT',                                      'Data & Risk Assessment', 'onboarding', 'Off-site hosting details', 1),
('remote_network_access',                "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Remote network access?', 1),
('remote_access_justification',          'TEXT',                                      'Data & Risk Assessment', 'onboarding', 'Remote access details', 1),
('source_code_access',                   "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Source code/repo access?', 1),
('source_code_justification',            'TEXT',                                      'Data & Risk Assessment', 'onboarding', 'Source code access details', 1),
('critical_business_function',           "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Supports critical business function?', 1),
('critical_function_justification',      'TEXT',                                      'Data & Risk Assessment', 'onboarding', 'Critical function details', 1),
('unauthorized_disclosure_impact',       "ENUM('low','moderate','high','severe','')", 'Data & Risk Assessment', 'onboarding', 'Impact of unauthorized disclosure', 1),
('unauthorized_disclosure_justification','TEXT',                                      'Data & Risk Assessment', 'onboarding', 'Disclosure impact justification', 1),
('unauthorized_modification_impact',     "ENUM('low','moderate','high','severe','')", 'Data & Risk Assessment', 'onboarding', 'Impact of unauthorized modification', 1),
('disruption_impact',                    "ENUM('low','moderate','high','severe','')", 'Data & Risk Assessment', 'onboarding', 'Impact of service disruption', 1),
('saml_sso_support',                     "ENUM('yes','no','unknown','')",             'Data & Risk Assessment', 'onboarding', 'SAML/SSO support?', 1),
('is_saas',                              "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Is SaaS product?', 1),
('vendor_use_ai',                        "ENUM('yes','no','')",                       'Data & Risk Assessment', 'onboarding', 'Does vendor solution use AI?', 1),
('additional_information',               'TEXT',                                      'Additional Information', 'onboarding', 'Free-text additional information', 1);

-- =============================================================================
-- Seed AI Usage workflow rule
-- Triggers AI Usage assessment when vendor onboarding indicates AI usage.
-- =============================================================================
INSERT INTO assessment_workflow_rules (name, description, source_template_id, question_id, condition_operator, condition_value, target_template_id, assign_to, is_active)
SELECT
    'AI Usage',
    'Automatically trigger AI Usage assessment when vendor indicates AI usage in onboarding.',
    t_source.id,
    q.id,
    'equals',
    'Yes',
    t_target.id,
    'vendor',
    1
FROM assessment_questions q
INNER JOIN assessment_sections s ON q.section_id = s.id
INNER JOIN assessment_templates t_source ON s.template_id = t_source.id
CROSS JOIN assessment_templates t_target
WHERE t_source.slug = 'vendor-onboarding'
  AND q.field_name = 'vendor_use_ai'
  AND t_target.slug = 'ai-usage'
  AND NOT EXISTS (
    SELECT 1 FROM assessment_workflow_rules r
    WHERE r.source_template_id = t_source.id
      AND r.target_template_id = t_target.id
      AND r.condition_value = 'Yes'
  )
LIMIT 1;

-- =============================================================================
-- SECTION 15: LEGACY SRS SCORING TABLES
-- =============================================================================
-- These tables are from the original standalone SRS scoring system that
-- predates the full TPRM application. They contain historical vendor
-- security score data collected via automated scanning. The newer
-- vendor_srs_scores/vendor_srs_risks tables (Section 7) handle UpGuard
-- integration, while these legacy tables store scores from the original
-- scanning infrastructure.
-- =============================================================================

-- Legacy score history - raw security scores per vendor per scan source
CREATE TABLE IF NOT EXISTS score (
    id INT NOT NULL AUTO_INCREMENT,
    Company VARCHAR(255) DEFAULT NULL COMMENT 'Vendor domain name',
    Average_Score DECIMAL(5,2) DEFAULT NULL COMMENT 'Average security score for this scan',
    Letter_Grade CHAR(1) DEFAULT NULL COMMENT 'Letter grade (A-F)',
    Date_Ran DATE DEFAULT NULL COMMENT 'Date the scan was executed',
    Source VARCHAR(255) DEFAULT NULL COMMENT 'Score source/category (e.g., total average)',
    PRIMARY KEY (id),
    INDEX idx_company (Company),
    INDEX idx_date_ran (Date_Ran),
    INDEX idx_source (Source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy vendor tier and scan configuration
CREATE TABLE IF NOT EXISTS tier (
    id INT NOT NULL AUTO_INCREMENT,
    domain VARCHAR(64) NOT NULL COMMENT 'Vendor domain name',
    tier INT NOT NULL COMMENT 'Vendor tier: 1=daily, 2=90-day, 3=annual',
    scanoption VARCHAR(32) NOT NULL COMMENT 'Scan configuration option',
    PRIMARY KEY (id),
    INDEX idx_domain (domain),
    INDEX idx_tier (tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy subdomain exclusions for scanning
CREATE TABLE IF NOT EXISTS domainexclusions (
    id INT NOT NULL AUTO_INCREMENT,
    subdomain VARCHAR(64) NOT NULL COMMENT 'Subdomain to exclude from scanning',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 16: LEGACY SRS SCORING VIEWS
-- =============================================================================

-- View: Most recent scores per vendor per source (joined with tier data)
CREATE OR REPLACE VIEW recent_scores AS
SELECT
    s.id AS id,
    s.Company AS domain,
    s.Date_Ran AS Date_Ran,
    s.Source AS Source,
    s.Average_Score AS Average_Score,
    t.tier AS tier,
    t.scanoption AS scanoption,
    s.Letter_Grade AS Letter_Grade
FROM score s
JOIN tier t ON s.Company = t.domain
JOIN (
    SELECT Company, Source, MAX(Date_Ran) AS max_date
    FROM score
    GROUP BY Company, Source
) recent ON s.Company = recent.Company
    AND s.Source = recent.Source
    AND s.Date_Ran = recent.max_date;

-- View: Most recent total average score per vendor (excludes zero scores)
-- Uses JOIN approach for MySQL 5.7+ / MariaDB 10.2+ compatibility
CREATE OR REPLACE VIEW view_mostrecent AS
SELECT s.id, s.Company, s.Average_Score, s.Letter_Grade, s.Date_Ran
FROM score s
INNER JOIN (
    SELECT Company, MAX(Date_Ran) AS max_date
    FROM score
    WHERE Average_Score <> 0.00 AND Source = 'total average'
    GROUP BY Company
) latest ON s.Company = latest.Company AND s.Date_Ran = latest.max_date
WHERE s.Source = 'total average' AND s.Average_Score <> 0.00;

-- View: Next scheduled scan date per vendor based on tier
CREATE OR REPLACE VIEW view_next_scan AS
SELECT
    s.id AS id,
    s.Company AS domain,
    s.Date_Ran AS last_run,
    s.Source AS Source,
    s.Average_Score AS last_score,
    t.tier AS tier,
    t.scanoption AS scanoption,
    s.Letter_Grade AS Letter_Grade,
    CASE
        WHEN t.tier = 1 THEN s.Date_Ran + INTERVAL 1 DAY
        WHEN t.tier = 2 THEN s.Date_Ran + INTERVAL 90 DAY
        WHEN t.tier = 3 THEN s.Date_Ran + INTERVAL 365 DAY
        ELSE NULL
    END AS next_scan
FROM score s
JOIN tier t ON s.Company = t.domain
JOIN (
    SELECT Company, Source, MAX(Date_Ran) AS max_date
    FROM score
    GROUP BY Company, Source
) recent ON s.Company = recent.Company
    AND s.Source = recent.Source
    AND s.Date_Ran = recent.max_date
WHERE s.Source = 'total average';

-- =============================================================================
-- SECTION 17: CVE SEARCH VIEW
-- =============================================================================
-- Unified view for CVE search across Shodan findings with UpGuard cross-reference.
-- Shows CVEs from the most recent Shodan score per vendor, and checks if UpGuard
-- also detected risks on the same host (by subdomain or IP match in risk_host).
-- =============================================================================

CREATE OR REPLACE VIEW view_cve_search AS
SELECT
    vsf.cve_id,
    vsf.cvss_score,
    vsf.severity,
    vsf.subdomain,
    vsf.ip_address,
    vsf.port,
    vsf.description AS cve_description,
    vss.vendor_onboarding_id,
    vor.vendor_name,
    vor.vendor_domain,
    1 AS shodan_detected,
    CASE WHEN EXISTS (
        SELECT 1 FROM vendor_srs_risks vsr
        JOIN vendor_srs_scores vsrs ON vsrs.id = vsr.srs_score_id
        WHERE vsrs.vendor_onboarding_id = vss.vendor_onboarding_id
        AND vsr.risk_host IS NOT NULL
        AND vsr.risk_host != ''
        AND (
            (vsf.subdomain IS NOT NULL AND vsr.risk_host LIKE CONCAT('%', vsf.subdomain, '%'))
            OR (vsf.ip_address IS NOT NULL AND vsr.risk_host LIKE CONCAT('%', vsf.ip_address, '%'))
        )
    ) THEN 1 ELSE 0 END AS upguard_detected,
    CASE WHEN vcw.id IS NOT NULL THEN 1 ELSE 0 END AS waived,
    vcw.reason AS waived_reason,
    vcw.waived_by
FROM vendor_shodan_findings vsf
INNER JOIN vendor_shodan_scores vss ON vss.id = vsf.shodan_score_id
INNER JOIN (
    SELECT vendor_onboarding_id, MAX(id) AS latest_score_id
    FROM vendor_shodan_scores
    GROUP BY vendor_onboarding_id
) latest ON latest.latest_score_id = vss.id
INNER JOIN vendor_onboarding_requests vor ON vor.id = vss.vendor_onboarding_id
LEFT JOIN vendor_shodan_cve_waivers vcw ON vcw.vendor_onboarding_id = vss.vendor_onboarding_id AND vcw.cve_id = vsf.cve_id
WHERE vsf.cve_id IS NOT NULL AND vsf.cve_id != '';

-- =============================================================================
-- 38. shadow_saas - Unmanaged SaaS application tracking
-- =============================================================================
CREATE TABLE IF NOT EXISTS shadow_saas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_name VARCHAR(500) NOT NULL,
    vendor_domain VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    risk_score INT UNSIGNED DEFAULT NULL,
    risk_type TEXT DEFAULT NULL,
    relationship_manager VARCHAR(255) DEFAULT NULL,
    number_of_users INT UNSIGNED DEFAULT NULL,
    application_category VARCHAR(255) DEFAULT NULL,
    breaches_in_three_years INT UNSIGNED DEFAULT NULL,
    downloadbytes BIGINT UNSIGNED DEFAULT NULL,
    uploadbytes BIGINT UNSIGNED DEFAULT NULL,
    filesharing VARCHAR(10) DEFAULT NULL,
    mfasupport VARCHAR(10) DEFAULT NULL,
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
    INDEX idx_created_by (created_by),
    INDEX idx_rescore_status (rescore_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- POST-INSTALL SECURITY NOTES
-- =============================================================================
-- 1. CHANGE the default admin password immediately
-- 2. Set file permissions: config/ (700), config.php (600)
-- 3. Generate a new encryption key: openssl rand -base64 32
-- 4. Consider creating a dedicated database user:
--    CREATE USER IF NOT EXISTS 'tprm_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
--    GRANT SELECT, INSERT, UPDATE, DELETE ON tprm.* TO 'tprm_user'@'localhost';
--    FLUSH PRIVILEGES;
-- =============================================================================


-- v2.5.6 Seed data: email templates, ACL (incl. auditor), app_config
-- FairTPRM v2.5.6 - Seed data: email templates, ACL groups/permissions, app_config
/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: tprm
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0+deb12u2

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `email_templates`
--

LOCK TABLES `email_templates` WRITE;
/*!40000 ALTER TABLE `email_templates` DISABLE KEYS */;
INSERT IGNORE INTO `email_templates` (`id`, `template_category`, `template_key`, `assessment_template_id`, `display_name`, `email_subject`, `email_body_html`, `email_body_text`, `available_variables`, `is_active`, `created_at`, `updated_at`) VALUES (11,'vendor','assessment_request',NULL,'Assessment Request (Default)','Security Assessment Request{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr>\n            <td align=\"center\">\n                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                    <tr>\n                        <td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}\n                            <h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1>\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"background-color: #D1ECF1; border-left: 4px solid #17A2B8; padding: 15px 30px;\">\n                            <p style=\"margin: 0; color: #0C5460; font-size: 16px; font-weight: bold;\">Security Assessment Request</p>\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding: 30px;\">\n                            <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">{{greeting}}</p>\n                            <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">As part of our vendor risk management process, we require <strong>{{vendor_name}}</strong> to complete a security assessment.</p>\n                            <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Please click the button below to access and complete the assessment:</p>\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{assessment_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Assessment</a>\n                                    </td>\n                                </tr>\n                            </table>\n                            <p style=\"margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;\">This link will expire on {{expires_at}}. If you have any questions, please contact us.</p>\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"background-color: {{footer_color}}; padding: 20px 30px;\">\n                            <p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>','{{greeting}}\n\nAs part of our vendor risk management process, we require {{vendor_name}} to complete a security assessment.\n\nPlease click the link below to access and complete the assessment:\n\n{{assessment_url}}\n\nThis link will expire on {{expires_at}}. If you have any questions, please contact us.\n\nThank you,\nThird Party Risk Management Team','[\"vendor_name\",\"assessment_name\",\"assessment_url\",\"greeting\",\"contact_name\",\"full_name\",\"from_name\",\"company_name\",\"expires_at\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-05 17:51:05'),
(12,'vendor','assessment_7_days_before',NULL,'Assessment Reminder - 7 Days','Reminder: Assessment Due in 7 Days{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}','<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\r\n<tbody>\r\n<tr>\r\n<td align=\"center\">\r\n<table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\r\n<tbody>\r\n<tr>\r\n<td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}\r\n<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"background-color: #fff3cd; border-left: 4px solid #FFC107; padding: 15px 30px;\">\r\n<p style=\"margin: 0; color: #856404; font-size: 16px; font-weight: bold;\">Assessment Due in 7 Days</p>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"padding: 30px;\">\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">{{greeting}}</p>\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">This is a reminder that the security assessment for <strong>{{vendor_name}}</strong> is due in <strong>{{days_left}} days</strong> on <strong>{{expires_at}}</strong>.</p>\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Please click the button below to complete the assessment before it expires:</p>\r\n<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\">\r\n<tbody>\r\n<tr>\r\n<td align=\"center\"><a href=\"{{assessment_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Assessment</a></td>\r\n</tr>\r\n</tbody>\r\n</table>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"background-color: {{footer_color}}; padding: 20px 30px;\">\r\n<p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated reminder from {{system_title}}. Please do not reply to this email.</p>\r\n</td>\r\n</tr>\r\n</tbody>\r\n</table>\r\n</td>\r\n</tr>\r\n</tbody>\r\n</table>','Assessment Due in 7 Days\r\n==========================================\r\n\r\n{{greeting}}\r\n\r\nThis is a reminder that the security assessment for {{vendor_name}} is due in {{days_left}} days on {{expires_at}}.\r\n\r\nPlease click the link below to complete the assessment before it expires:\r\n\r\n{{assessment_url}}\r\n\r\n==========================================\r\nThis is an automated reminder from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"assessment_name\",\"assessment_url\",\"greeting\",\"contact_name\",\"expires_at\",\"days_left\",\"from_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-05 18:37:45'),
(13,'vendor','assessment_3_days_before',NULL,'Assessment Reminder - 3 Days','Urgent: Assessment Due in 3 Days{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}','<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\r\n<tbody>\r\n<tr>\r\n<td align=\"center\">\r\n<table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\r\n<tbody>\r\n<tr>\r\n<td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}\r\n<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"background-color: #fff3cd; border-left: 4px solid #FF9800; padding: 15px 30px;\">\r\n<p style=\"margin: 0; color: #856404; font-size: 16px; font-weight: bold;\">Assessment Due in 3 Days</p>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"padding: 30px;\">\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">{{greeting}}</p>\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">This is an urgent reminder that the security assessment for <strong>{{vendor_name}}</strong> is due in <strong>{{days_left}} days</strong> on <strong>{{expires_at}}</strong>.</p>\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Please complete it as soon as possible.:</p>\r\n<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\">\r\n<tbody>\r\n<tr>\r\n<td align=\"center\"><a href=\"{{assessment_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Assessment</a></td>\r\n</tr>\r\n</tbody>\r\n</table>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"background-color: {{footer_color}}; padding: 20px 30px;\">\r\n<p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated reminder from {{system_title}}. Please do not reply to this email.</p>\r\n</td>\r\n</tr>\r\n</tbody>\r\n</table>\r\n</td>\r\n</tr>\r\n</tbody>\r\n</table>','Assessment Due in 3 Days\r\n==========================================\r\n\r\n{{greeting}}\r\n\r\nThis is an urgent reminder that the security assessment for {{vendor_name}} is due in {{days_left}} days on {{expires_at}}.\r\n\r\nPlease complete it as soon as possible.\r\n\r\n{{assessment_url}}\r\n\r\n==========================================\r\nThis is an automated reminder from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"assessment_name\",\"assessment_url\",\"greeting\",\"contact_name\",\"expires_at\",\"days_left\",\"from_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-05 18:37:45'),
(14,'vendor','assessment_expiry_day',NULL,'Assessment Reminder - Expiry Day','FINAL NOTICE: Assessment Expires Today{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #F8D7DA; border-left: 4px solid #DC3545; padding: 15px 30px;\"><p style=\"margin: 0; color: #721C24; font-size: 16px; font-weight: bold;\">Assessment Expires Today</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">{{greeting}}</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The security assessment for <strong>{{vendor_name}}</strong> expires <strong>today, {{expires_at}}</strong>. After this date, the assessment link will no longer be accessible.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Please complete it immediately:</p>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{assessment_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Assessment Now</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated reminder from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Assessment Expires Today\n==========================================\n\n{{greeting}}\n\nThe security assessment for {{vendor_name}} expires today, {{expires_at}}. After this date, the assessment link will no longer be accessible.\n\nPlease complete it immediately:\n\n{{assessment_url}}\n\n==========================================\nThis is an automated reminder from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"assessment_name\",\"assessment_url\",\"greeting\",\"contact_name\",\"expires_at\",\"days_left\",\"from_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-05 17:51:05'),
(15,'stakeholder','30_days_before',NULL,'Annual Review - 30 Day Reminder','Upcoming Vendor Review: {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #FFF3CD; border-left: 4px solid #FFC107; padding: 15px 30px;\"><p style=\"margin: 0; color: #856404; font-size: 16px; font-weight: bold;\">Annual Vendor Review Due Soon</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The annual review for <strong>{{vendor_name}}</strong> is due in 30 days on <strong>{{due_date}}</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">As the assigned stakeholder, you are required to complete the annual review, which includes:</p>\n                    <ul style=\"color: #333333; font-size: 16px; line-height: 1.8; margin: 0 0 20px 0;\"><li>Confirming your role as the current stakeholder</li><li>Reviewing and updating the scope of services</li><li>Verifying vendor contact information</li></ul>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{review_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Review Now</a></td></tr></table>\n                    <p style=\"margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;\">If you are no longer the stakeholder for this vendor, you can reassign it during the review process.</p>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p><p style=\"margin: 10px 0 0 0; color: #ffffff; font-size: 12px;\"><strong>Vendor:</strong> {{vendor_name}}</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Annual Vendor Review Due Soon\n==========================================\n\nThe annual review for {{vendor_name}} is due in 30 days on {{due_date}}.\n\nAs the assigned stakeholder, you are required to complete the annual review, which includes:\n- Confirming your role as the current stakeholder\n- Reviewing and updating the scope of services\n- Verifying vendor contact information\n\nComplete your review here:\n{{review_url}}\n\nIf you are no longer the stakeholder for this vendor, you can reassign it during the review process.\n\n==========================================\nVendor: {{vendor_name}}\n\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"review_url\",\"due_date\",\"from_name\",\"company_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-05 17:51:05'),
(16,'stakeholder','due_date',NULL,'Annual Review - Due Today','Action Required: Vendor Review Due Today - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #FFF3CD; border-left: 4px solid #FF9800; padding: 15px 30px;\"><p style=\"margin: 0; color: #856404; font-size: 16px; font-weight: bold;\">Annual Vendor Review Due Today</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The annual review for <strong>{{vendor_name}}</strong> is due today, <strong>{{due_date}}</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">As the assigned stakeholder, you are required to complete the annual review, which includes:</p>\n                    <ul style=\"color: #333333; font-size: 16px; line-height: 1.8; margin: 0 0 20px 0;\"><li>Confirming your role as the current stakeholder</li><li>Reviewing and updating the scope of services</li><li>Verifying vendor contact information</li></ul>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{review_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Review Now</a></td></tr></table>\n                    <p style=\"margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;\">If you are no longer the stakeholder for this vendor, you can reassign it during the review process.</p>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p><p style=\"margin: 10px 0 0 0; color: #ffffff; font-size: 12px;\"><strong>Vendor:</strong> {{vendor_name}}</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Annual Vendor Review Due Today\n==========================================\n\nThe annual review for {{vendor_name}} is due today, {{due_date}}.\n\nAs the assigned stakeholder, you are required to complete the annual review, which includes:\n- Confirming your role as the current stakeholder\n- Reviewing and updating the scope of services\n- Verifying vendor contact information\n\nComplete your review here:\n{{review_url}}\n\nIf you are no longer the stakeholder for this vendor, you can reassign it during the review process.\n\n==========================================\nVendor: {{vendor_name}}\n\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"review_url\",\"due_date\",\"from_name\",\"company_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-05 17:51:05'),
(17,'stakeholder','overdue',NULL,'Annual Review - Overdue Notice','OVERDUE: Vendor Review Required - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #F8D7DA; border-left: 4px solid #DC3545; padding: 15px 30px;\"><p style=\"margin: 0; color: #721C24; font-size: 16px; font-weight: bold;\">Annual Vendor Review Overdue</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The annual review for <strong>{{vendor_name}}</strong> was due on <strong>{{due_date}}</strong> and is now <strong>{{days_overdue}} days overdue</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">As the assigned stakeholder, you are required to complete the annual review, which includes:</p>\n                    <ul style=\"color: #333333; font-size: 16px; line-height: 1.8; margin: 0 0 20px 0;\"><li>Confirming your role as the current stakeholder</li><li>Reviewing and updating the scope of services</li><li>Verifying vendor contact information</li></ul>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{review_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Review Now</a></td></tr></table>\n                    <p style=\"margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;\">If you are no longer the stakeholder for this vendor, you can reassign it during the review process.</p>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p><p style=\"margin: 10px 0 0 0; color: #ffffff; font-size: 12px;\"><strong>Vendor:</strong> {{vendor_name}}</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Annual Vendor Review Overdue\n==========================================\n\nThe annual review for {{vendor_name}} was due on {{due_date}} and is now {{days_overdue}} days overdue.\n\nAs the assigned stakeholder, you are required to complete the annual review, which includes:\n- Confirming your role as the current stakeholder\n- Reviewing and updating the scope of services\n- Verifying vendor contact information\n\nComplete your review here:\n{{review_url}}\n\nIf you are no longer the stakeholder for this vendor, you can reassign it during the review process.\n\n==========================================\nVendor: {{vendor_name}}\n\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"review_url\",\"due_date\",\"days_overdue\",\"from_name\",\"company_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-05 17:51:05'),
(18,'procurement','contract_expiring',NULL,'Contract Expiring Soon','Contract Expiring Soon: {{contract_name}} - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #FFF3CD; border-left: 4px solid #FFC107; padding: 15px 30px;\"><p style=\"margin: 0; color: #856404; font-size: 16px; font-weight: bold;\">Contract Expiring Soon</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The contract <strong>{{contract_name}}</strong> for vendor <strong>{{vendor_name}}</strong> is expiring in <strong>{{days_until_expiry}} days</strong> on <strong>{{expiration_date}}</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Contract type: <strong>{{contract_type}}</strong></p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Please review this contract and take appropriate action before the expiration date.</p>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{contract_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">View Contract</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Contract Expiring Soon\n==========================================\n\nThe contract {{contract_name}} for vendor {{vendor_name}} is expiring in {{days_until_expiry}} days on {{expiration_date}}.\n\nContract type: {{contract_type}}\n\nPlease review this contract and take appropriate action before the expiration date.\n\nView contract: {{contract_url}}\n\n==========================================\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"contract_name\",\"vendor_name\",\"expiration_date\",\"days_until_expiry\",\"contract_type\",\"contract_url\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-05 17:51:05'),
(19,'procurement','contract_expired',NULL,'Contract Expired','Contract Expired: {{contract_name}} - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #F8D7DA; border-left: 4px solid #DC3545; padding: 15px 30px;\"><p style=\"margin: 0; color: #721C24; font-size: 16px; font-weight: bold;\">Contract Expired</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The contract <strong>{{contract_name}}</strong> for vendor <strong>{{vendor_name}}</strong> expired on <strong>{{expiration_date}}</strong> and is now <strong>{{days_overdue}} days overdue</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Contract type: <strong>{{contract_type}}</strong></p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Immediate action is required to renew or address this expired contract.</p>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{contract_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">View Contract</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Contract Expired\n==========================================\n\nThe contract {{contract_name}} for vendor {{vendor_name}} expired on {{expiration_date}} and is now {{days_overdue}} days overdue.\n\nContract type: {{contract_type}}\n\nImmediate action is required to renew or address this expired contract.\n\nView contract: {{contract_url}}\n\n==========================================\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"contract_name\",\"vendor_name\",\"expiration_date\",\"days_overdue\",\"contract_type\",\"contract_url\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-05 17:51:05'),
(20,'procurement','case_assigned',NULL,'Contract Case Assigned','Contract Case Assigned: {{case_title}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #D1ECF1; border-left: 4px solid #17A2B8; padding: 15px 30px;\"><p style=\"margin: 0; color: #0C5460; font-size: 16px; font-weight: bold;\">Contract Case Assigned to You</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">A contract expiration case has been assigned to you by <strong>{{assigned_by}}</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Case: <strong>{{case_title}}</strong></p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Vendor: <strong>{{vendor_name}}</strong></p>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{case_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">View Case</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Contract Case Assigned to You\n==========================================\n\nA contract expiration case has been assigned to you by {{assigned_by}}.\n\nCase: {{case_title}}\nVendor: {{vendor_name}}\n\nView case: {{case_url}}\n\n==========================================\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"case_title\",\"vendor_name\",\"assigned_by\",\"case_url\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-05 17:51:05');
/*!40000 ALTER TABLE `email_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `acl_groups`
--

LOCK TABLES `acl_groups` WRITE;
/*!40000 ALTER TABLE `acl_groups` DISABLE KEYS */;
INSERT IGNORE INTO `acl_groups` (`id`, `group_name`, `display_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES (1,'administrator','Administrator','Full administrative access to all features',1,'2026-02-03 18:52:15','2026-02-03 18:52:15'),
(2,'cyber_tprm','Cyber TPRM','Cyber Third Party Risk Management team - can manage vendor assessments and FAIR analysis',1,'2026-02-03 18:52:15','2026-02-03 18:52:15'),
(3,'procurement','Procurement','Procurement team - can create and manage vendor onboarding requests',1,'2026-02-03 18:52:15','2026-02-03 18:52:15'),
(4,'stakeholder','Stakeholder','Stakeholders - can view and update assigned vendor onboarding requests',1,'2026-02-03 18:52:15','2026-02-03 18:52:15'),
(109,'auditor','Auditor','Read-only access to all modules. Cannot create, update, delete, or send.',1,'2026-03-04 16:56:58','2026-03-04 16:56:58');
/*!40000 ALTER TABLE `acl_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `acl_permissions`
--

LOCK TABLES `acl_permissions` WRITE;
/*!40000 ALTER TABLE `acl_permissions` DISABLE KEYS */;
INSERT IGNORE INTO `acl_permissions` (`id`, `permission_code`, `name`, `description`, `module`, `resource`, `action`, `created_at`) VALUES (1,'onboarding.create','Create Onboarding Request','Create new vendor onboarding requests','onboarding','request','create','2026-02-03 18:52:36'),
(2,'onboarding.read','View All Onboarding Requests','View all vendor onboarding requests','onboarding','request','read','2026-02-03 18:52:36'),
(3,'onboarding.read_own','View Own Onboarding Requests','View own vendor onboarding requests','onboarding','request','read_own','2026-02-03 18:52:36'),
(4,'onboarding.read_assigned','View Assigned Onboarding Requests','View assigned vendor onboarding requests','onboarding','request','read_assigned','2026-02-03 18:52:36'),
(5,'onboarding.update','Update All Onboarding Requests','Update all vendor onboarding requests','onboarding','request','update','2026-02-03 18:52:36'),
(6,'onboarding.update_own','Update Own Onboarding Requests','Update own vendor onboarding requests','onboarding','request','update_own','2026-02-03 18:52:36'),
(7,'onboarding.update_assigned','Update Assigned Onboarding Requests','Update assigned vendor onboarding requests','onboarding','request','update_assigned','2026-02-03 18:52:36'),
(8,'onboarding.deactivate','Deactivate Onboarding Requests','Deactivate vendor onboarding requests','onboarding','request','deactivate','2026-02-03 18:52:36'),
(9,'onboarding.delete','Delete Onboarding Requests','Delete vendor onboarding requests','onboarding','request','delete','2026-02-03 18:52:36'),
(10,'onboarding.assign_stakeholder','Assign Stakeholders','Assign stakeholders to vendor onboarding requests','onboarding','request','assign_stakeholder','2026-02-03 18:52:36'),
(11,'analysis.create','Create FAIR Analysis','Create FAIR risk analyses','analysis','fair','create','2026-02-03 18:52:36'),
(12,'analysis.read','View FAIR Analysis','View FAIR risk analyses','analysis','fair','read','2026-02-03 18:52:36'),
(13,'annual_review.read','View All Annual Reviews','View all vendor annual reviews','annual_review','review','read','2026-02-05 03:21:28'),
(14,'annual_review.create','Complete Annual Reviews','Complete annual vendor reviews','annual_review','review','create','2026-02-05 03:21:28'),
(15,'annual_review.read_assigned','View Assigned Annual Reviews','View annual reviews for assigned vendors only','annual_review','review','read_assigned','2026-02-05 03:21:28'),
(16,'assessment.create','Create Assessments','Create vendor security assessments','assessment','vendor','create','2026-02-09 11:37:33'),
(17,'assessment.read','View Assessments','View vendor security assessments','assessment','vendor','read','2026-02-09 11:37:33'),
(18,'assessment.update','Update Assessments','Update vendor security assessments','assessment','vendor','update','2026-02-09 11:37:33'),
(19,'assessment.delete','Delete Assessments','Delete vendor security assessments','assessment','vendor','delete','2026-02-09 11:37:33'),
(20,'srs.view','View SRS Scores','View vendor SRS security scores','srs','score','read','2026-02-09 11:37:33'),
(21,'srs.rescore','Trigger Rescore','Manually trigger vendor rescoring','srs','score','update','2026-02-09 11:37:33');
/*!40000 ALTER TABLE `acl_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `acl_group_permissions`
--

LOCK TABLES `acl_group_permissions` WRITE;
/*!40000 ALTER TABLE `acl_group_permissions` DISABLE KEYS */;
INSERT IGNORE INTO `acl_group_permissions` (`group_id`, `permission_id`, `granted_at`, `granted_by`) VALUES (1,1,'2026-02-03 18:52:55',NULL),
(1,2,'2026-02-03 18:52:55',NULL),
(1,3,'2026-02-03 18:52:55',NULL),
(1,4,'2026-02-03 18:52:55',NULL),
(1,5,'2026-02-03 18:52:55',NULL),
(1,6,'2026-02-03 18:52:55',NULL),
(1,7,'2026-02-03 18:52:55',NULL),
(1,8,'2026-02-03 18:52:55',NULL),
(1,9,'2026-02-03 18:52:55',NULL),
(1,10,'2026-02-03 18:52:55',NULL),
(1,11,'2026-02-03 18:52:55',NULL),
(1,12,'2026-02-03 18:52:55',NULL),
(1,13,'2026-02-05 03:21:28',NULL),
(1,14,'2026-02-05 03:21:28',NULL),
(1,15,'2026-02-05 03:21:28',NULL),
(1,16,'2026-02-09 11:37:33',NULL),
(1,17,'2026-02-09 11:37:33',NULL),
(1,18,'2026-02-09 11:37:33',NULL),
(1,19,'2026-02-09 11:37:33',NULL),
(1,20,'2026-02-09 11:37:33',NULL),
(1,21,'2026-02-09 11:37:33',NULL),
(2,1,'2026-02-03 18:52:55',NULL),
(2,2,'2026-02-03 18:52:55',NULL),
(2,3,'2026-02-03 18:52:55',NULL),
(2,4,'2026-02-03 18:52:55',NULL),
(2,5,'2026-02-03 18:52:55',NULL),
(2,6,'2026-02-03 18:52:55',NULL),
(2,7,'2026-02-03 18:52:55',NULL),
(2,8,'2026-02-03 18:52:55',NULL),
(2,9,'2026-02-03 18:52:55',NULL),
(2,10,'2026-02-03 18:52:55',NULL),
(2,11,'2026-02-03 18:52:55',NULL),
(2,12,'2026-02-03 18:52:55',NULL),
(2,13,'2026-02-05 03:21:29',NULL),
(2,14,'2026-02-05 03:21:29',NULL),
(2,15,'2026-02-05 03:21:29',NULL),
(2,16,'2026-02-09 11:37:34',NULL),
(2,17,'2026-02-09 11:37:34',NULL),
(2,18,'2026-02-09 11:37:34',NULL),
(2,19,'2026-02-09 11:37:34',NULL),
(2,20,'2026-02-09 11:37:34',NULL),
(2,21,'2026-02-09 11:37:34',NULL),
(3,1,'2026-02-03 18:52:55',NULL),
(3,2,'2026-02-03 18:52:55',NULL),
(3,6,'2026-02-03 18:52:55',NULL),
(3,10,'2026-02-03 18:52:55',NULL),
(3,12,'2026-02-03 18:52:55',NULL),
(3,13,'2026-02-05 03:21:29',NULL),
(3,14,'2026-02-05 03:21:29',NULL),
(3,17,'2026-02-09 11:37:34',NULL),
(3,20,'2026-02-09 11:37:34',NULL),
(4,1,'2026-02-03 20:43:17',NULL),
(4,3,'2026-02-03 20:43:17',NULL),
(4,4,'2026-02-03 18:52:55',NULL),
(4,6,'2026-02-03 20:43:17',NULL),
(4,7,'2026-02-03 18:52:55',NULL),
(4,14,'2026-02-05 03:21:29',NULL),
(4,15,'2026-02-05 03:21:29',NULL),
(109,2,'2026-03-04 16:57:22',NULL),
(109,12,'2026-03-04 16:57:22',NULL),
(109,13,'2026-03-04 16:57:22',NULL),
(109,17,'2026-03-04 16:57:22',NULL),
(109,20,'2026-03-04 16:57:22',NULL);
/*!40000 ALTER TABLE `acl_group_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `app_config`
--

LOCK TABLES `app_config` WRITE;
/*!40000 ALTER TABLE `app_config` DISABLE KEYS */;
INSERT IGNORE INTO `app_config` (`id`, `config_key`, `config_value`, `is_encrypted`, `description`, `created_at`, `updated_at`) VALUES (1,'logo_url','app/images/logo-default-418x78.png',0,NULL,'2026-02-03 18:12:56','2026-02-03 18:12:56'),
(2,'footer_logo_url','app/images/logo-inverse-416x78.png',0,NULL,'2026-02-03 18:12:56','2026-02-03 18:12:56'),
(3,'header_color','#35a0a3',0,NULL,'2026-02-03 18:12:56','2026-02-03 18:12:56'),
(4,'footer_color','#1a365d',0,NULL,'2026-02-03 18:12:56','2026-02-03 18:12:56'),
(5,'button_color','#35a0a3',0,NULL,'2026-02-03 18:12:56','2026-02-03 18:12:56'),
(6,'nav_fill_color','#e9ecef',0,NULL,'2026-02-03 18:45:25','2026-02-05 16:25:15'),
(7,'nav_font_color','#1f1e1e',0,NULL,'2026-02-03 18:45:25','2026-02-05 16:25:16'),
(8,'nav_width','220',0,NULL,'2026-02-03 18:45:25','2026-02-03 18:45:25'),
(9,'upguard_enabled','0',0,NULL,'2026-02-03 18:49:40','2026-02-03 18:49:40'),
(10,'upguard_scoring_method','range',0,NULL,'2026-02-03 18:49:40','2026-02-25 18:44:49'),
(11,'upguard_max_score','950',0,NULL,'2026-02-03 18:49:40','2026-02-03 18:49:40'),
(12,'upguard_grade_a_min','90',0,NULL,'2026-02-03 18:49:40','2026-02-25 18:39:53'),
(13,'upguard_grade_b_min','80',0,NULL,'2026-02-03 18:49:40','2026-02-25 18:39:53'),
(14,'upguard_grade_c_min','70',0,NULL,'2026-02-03 18:49:40','2026-02-25 18:39:53'),
(15,'upguard_grade_d_min','60',0,NULL,'2026-02-03 18:49:40','2026-02-25 18:39:53'),
(16,'upguard_api_key','',0,NULL,'2026-02-03 18:49:40','2026-02-24 03:40:16'),
(17,'openwebui_enabled','0',0,NULL,'2026-02-03 20:49:08','2026-02-03 20:49:08'),
(18,'openwebui_api_url','',0,NULL,'2026-02-03 20:49:08','2026-02-03 20:49:08'),
(19,'openwebui_model','',0,NULL,'2026-02-03 20:49:08','2026-02-03 20:49:08'),
(20,'openwebui_temperature','0.3',0,NULL,'2026-02-03 20:49:08','2026-02-25 20:00:43'),
(21,'openwebui_max_tokens','4096',0,NULL,'2026-02-03 20:49:08','2026-02-25 20:00:43'),
(22,'openwebui_jwt_token','',0,NULL,'2026-02-03 20:49:08','2026-02-24 03:40:20'),
(23,'email_enabled','0',0,'Enable/disable email notifications (0=disabled, 1=enabled)','2026-02-05 03:21:29','2026-03-05 20:23:50'),
(24,'smtp_host','localhost',0,'SMTP server hostname','2026-02-05 03:21:29','2026-03-05 18:48:13'),
(25,'smtp_port','25',0,'SMTP server port (25, 587, 465)','2026-02-05 03:21:29','2026-03-05 18:48:54'),
(26,'smtp_username','',0,'SMTP authentication username','2026-02-05 03:21:29','2026-03-05 18:56:15'),
(27,'smtp_password','',0,'SMTP authentication password (encrypted)','2026-02-05 03:21:29','2026-03-05 18:49:49'),
(28,'smtp_encryption','none',0,'SMTP encryption type (none, tls, ssl)','2026-02-05 03:21:29','2026-03-05 18:48:13'),
(29,'email_from_email','noreply@example.com',0,'From email address for system notifications','2026-02-05 03:21:29','2026-03-05 18:56:38'),
(30,'email_from_name','TPRM System',0,'From name for system notifications','2026-02-05 03:21:29','2026-03-05 19:02:06'),
(31,'upguard_tier1_days','30',0,NULL,'2026-02-05 22:45:13','2026-02-05 22:45:13'),
(32,'upguard_tier2_days','90',0,NULL,'2026-02-05 22:45:13','2026-02-05 22:45:13'),
(33,'upguard_tier3_days','365',0,NULL,'2026-02-05 22:45:13','2026-02-05 22:45:13'),
(34,'upguard_trending_days','365',0,NULL,'2026-02-05 22:45:13','2026-02-05 22:45:13'),
(35,'auth_type','local',0,NULL,'2026-02-10 00:21:27','2026-02-10 00:21:27'),
(36,'session_timeout','28800',0,NULL,'2026-02-10 00:21:27','2026-02-10 00:21:27'),
(37,'max_login_attempts','10',0,NULL,'2026-02-10 00:21:27','2026-02-10 00:21:27'),
(38,'lockout_duration','90',0,NULL,'2026-02-10 00:21:27','2026-02-10 00:21:27'),
(39,'app_timezone','America/New_York',0,'Application timezone for displaying dates and times','2026-02-10 00:21:27','2026-02-17 13:10:55'),
(40,'shodan_enabled','0',0,'Whether Shodan SRS integration is enabled','2026-02-13 16:41:01','2026-02-13 16:52:03'),
(41,'shodan_scoring_method','range',0,'Scoring method: range or percentage','2026-02-13 16:41:01','2026-02-13 16:52:03'),
(42,'shodan_max_score','100',0,'Maximum possible Shodan score','2026-02-13 16:41:01','2026-02-13 16:52:03'),
(43,'shodan_grade_a_min','90',0,'Minimum score for grade A','2026-02-13 16:41:01','2026-02-13 16:52:03'),
(44,'shodan_grade_b_min','80',0,'Minimum score for grade B','2026-02-13 16:41:01','2026-02-13 21:54:11'),
(45,'shodan_grade_c_min','70',0,'Minimum score for grade C','2026-02-13 16:41:01','2026-02-13 21:54:11'),
(46,'shodan_grade_d_min','60',0,'Minimum score for grade D','2026-02-13 16:41:01','2026-02-13 21:54:11'),
(47,'shodan_api_key','',0,'Shodan API key (encrypted)','2026-02-13 16:41:01','2026-02-24 03:40:14'),
(56,'upguard_display_name','UpGuard',0,NULL,'2026-02-13 17:35:08','2026-02-13 17:35:08'),
(57,'upguard_display_mode','percentage',0,NULL,'2026-02-13 17:35:08','2026-02-13 17:35:08'),
(58,'shodan_display_name','SRS',0,NULL,'2026-02-13 17:45:38','2026-02-13 23:21:15'),
(59,'shodan_category_weights','{\"tls_crypto\":5,\"network_security\":15,\"vuln_exposure\":40,\"email_security\":20}',0,NULL,'2026-02-13 17:49:39','2026-02-13 17:50:55'),
(60,'shodan_max_subdomains','1000',0,NULL,'2026-02-13 17:55:11','2026-03-03 11:31:48'),
(61,'shodan_randomize_subdomains','1',0,NULL,'2026-02-13 17:55:11','2026-02-13 18:04:17'),
(62,'shodan_excluded_domains','',0,NULL,'2026-02-13 18:12:10','2026-03-04 18:33:49'),
(80,'password_min_length','12',0,'Minimum password length','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(81,'require_password_complexity','1',0,'Require complex passwords','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(82,'totp_issuer','TPRM FAIR Analysis',0,'TOTP issuer name','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(87,'pii_breach_cost_per_record','160',0,'Cost per PII record if breached','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(88,'spii_breach_cost_per_record','200',0,'Cost per SPII record if breached','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(89,'sox_breach_penalty','5000000',0,'SOX compliance penalty if breached','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(104,'revalidation_tier1_frequency_days','30',0,'Tier 1 vendor revalidation frequency in days','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(105,'revalidation_tier2_frequency_days','90',0,'Tier 2 vendor revalidation frequency in days','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(106,'revalidation_tier3_frequency_days','365',0,'Tier 3 vendor revalidation frequency in days','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(107,'revalidation_reminder_days','7',0,'Send reminder email X days before revalidation due','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(108,'revalidation_escalation_days','14',0,'Escalate if overdue by X days','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(121,'company_name','FAIRTPRM',0,'Company name displayed in emails and footers','2026-02-17 13:10:56','2026-02-17 13:12:07'),
(122,'log_retention_days','90',0,'Number of days to retain audit log entries (0 = indefinite)','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(123,'procurement_notifications_enabled','0',0,'Enable/disable procurement contract expiry email notifications','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(124,'procurement_notification_recipients','all',0,'Procurement notification recipients: all, selected, or none','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(125,'procurement_notification_warning_days','30',0,'Days before contract expiry to start sending warnings','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(126,'procurement_notification_selected_users','[]',0,'JSON array of selected user IDs for procurement notifications','2026-02-17 13:10:56','2026-02-17 13:10:56'),
(185,'app_url','',0,'Public application URL for email links and cron jobs (e.g. https://demo.fairtprm.com)','2026-02-18 14:02:20','2026-02-24 18:28:48'),
(192,'upguard_vendor_domains','0',0,'Enable vendor subdomain scoring via UpGuard','2026-02-18 14:02:22','2026-02-18 14:07:52'),
(325,'upguard_org_domain','',0,'Organization primary domain for BreachSight API routing','2026-02-18 16:19:11','2026-02-18 16:19:25'),
(460,'upguard_use_cron','0',0,NULL,'2026-02-18 23:31:32','2026-02-19 00:23:32'),
(461,'shodan_use_cron','0',0,NULL,'2026-02-18 23:31:40','2026-02-19 00:23:40'),
(663,'shodan_min_cve_year','0',0,NULL,'2026-02-21 17:26:00','2026-02-21 17:26:00'),
(731,'custom_scoring_enabled','0',0,NULL,'2026-02-24 03:38:57','2026-02-24 03:38:57'),
(732,'fair_annual_revenue','56000000000',0,NULL,'2026-02-24 03:42:13','2026-02-24 19:02:36'),
(733,'fair_revenue_cap_pct','10',0,NULL,'2026-02-24 03:42:13','2026-02-24 03:42:13'),
(734,'fair_ai_enabled','1',0,NULL,'2026-02-24 03:42:13','2026-02-24 04:19:08'),
(735,'app_version','v2.5.6',0,'Application version','2026-02-24 03:48:41','2026-02-25 04:45:27'),
(736,'shodan_banner_max_age','7',0,NULL,'2026-02-24 11:51:17','2026-02-24 11:51:17'),
(737,'shodan_on_demand_scan','0',0,'Enable on-demand Shodan scanning for IPs with no existing data','2026-03-06 00:00:00','2026-03-06 00:00:00'),
(1009,'cron_rescore_queue_enabled','1',0,'Rescore Queue enabled','2026-02-25 06:12:04','2026-02-25 06:12:04'),
(1010,'cron_rescore_queue_schedule','* * * * *',0,'Rescore Queue schedule','2026-02-25 06:12:04','2026-02-25 06:12:04'),
(1011,'cron_srs_rescore_enabled','1',0,'SRS Rescoring enabled','2026-02-25 06:12:04','2026-02-25 06:12:04'),
(1012,'cron_srs_rescore_schedule','0 * * * *',0,'SRS Rescoring schedule','2026-02-25 06:12:04','2026-02-25 06:12:04'),
(1013,'cron_annual_review_enabled','0',0,'Annual Review Reminders enabled','2026-02-25 06:12:04','2026-02-25 21:29:46'),
(1014,'cron_annual_review_schedule','0 2 * * *',0,'Annual Review Reminders schedule','2026-02-25 06:12:04','2026-02-25 06:12:04'),
(1015,'cron_contract_expiry_enabled','0',0,'Contract Expiry Reminders enabled','2026-02-25 06:12:04','2026-02-25 06:13:40'),
(1016,'cron_contract_expiry_schedule','0 3 * * *',0,'Contract Expiry Reminders schedule','2026-02-25 06:12:04','2026-02-25 06:12:04'),
(1017,'cron_assessment_reminders_enabled','0',0,'Assessment Reminders enabled','2026-02-25 06:12:04','2026-02-25 06:13:35'),
(1018,'cron_assessment_reminders_schedule','0 4 * * *',0,'Assessment Reminders schedule','2026-02-25 06:12:04','2026-02-25 06:12:04'),
(1019,'cron_timezone','America/New_York',0,'Cron job timezone','2026-02-25 06:12:04','2026-02-25 06:12:14'),
(1064,'fair_ai_model','novita.qwen/qwen-2.5-72b-instruct',0,NULL,'2026-02-25 16:16:53','2026-02-25 20:00:43'),
(1224,'cron_srs_rescore_batch_size','20',0,'SRS rescoring vendors per cron run (1-100)','2026-03-01 11:51:03','2026-03-01 11:51:03'),
(1295,'waf_mode','enforcing',0,'WAF lockdown mode: disabled, learning, enforcing','2026-03-01 17:21:19','2026-03-05 18:19:17'),
(1296,'waf_learning_started_at','2026-03-05 13:06:55',0,'Timestamp when WAF learning mode was started','2026-03-01 17:21:19','2026-03-05 18:06:55'),
(1297,'waf_audit_log_max_mb','100',0,'Maximum audit log size in MB before rotation','2026-03-01 17:21:19','2026-03-01 17:21:19'),
(1396,'waf_scanner_blocking','1',0,'WAF lockdown: waf_scanner_blocking','2026-03-02 01:30:22','2026-03-05 18:19:20');
/*!40000 ALTER TABLE `app_config` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-06  0:09:29
