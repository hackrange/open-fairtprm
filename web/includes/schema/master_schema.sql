-- =============================================================================
-- TPRM FAIR Analysis - MySQL/MariaDB Database Schema
-- =============================================================================
-- Author: Tim Rice - Hack Range
-- Version: 2.5.3
-- Last Updated: 2026-02-06
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
    preferred_language VARCHAR(10) DEFAULT NULL COMMENT 'User UI language code (e.g. en, es, zh-Hans); NULL = use system default',
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
    vendor_sisterdomains TEXT COMMENT 'Related/sister domains for Shodan scanning (one per line)',
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
    status ENUM('draft', 'submitted', 'in_review', 'approved', 'rejected', 'inactive', 'evaluation') DEFAULT 'draft',
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
    INDEX idx_section_order (template_id, sort_order)
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
    INDEX idx_question_order (section_id, sort_order),
    INDEX idx_depends_on (depends_on_question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cached machine translations of assessment content for the public vendor form.
-- One row per (source field, source id, language). source_hash is a SHA-256 of
-- the original text so a translation is reused until the admin edits the source,
-- then transparently regenerated. source_type 'options' stores a JSON array.
CREATE TABLE IF NOT EXISTS assessment_translations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_type VARCHAR(32) NOT NULL COMMENT 'question_text | help_text | options | section_name | section_description | template_name | template_description',
    source_id INT UNSIGNED NOT NULL COMMENT 'question/section/template id the text belongs to',
    language_code VARCHAR(10) NOT NULL,
    source_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the source text; invalidates the cache when the source changes',
    translated_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_translation (source_type, source_id, language_code),
    INDEX idx_lookup (source_type, source_id, language_code)
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
    -- Submitter attestation captured at final submission (questionnaire or certificate skip)
    submitter_name VARCHAR(255) DEFAULT NULL COMMENT 'Name of the person who submitted',
    submitter_title VARCHAR(255) DEFAULT NULL COMMENT 'Job title of the submitter',
    submitter_email VARCHAR(255) DEFAULT NULL COMMENT 'Email of the submitter',
    submitter_phone VARCHAR(50) DEFAULT NULL COMMENT 'Phone number of the submitter',
    submitter_ip_address VARCHAR(45) DEFAULT NULL COMMENT 'Client IP captured at submission',
    submitter_attested TINYINT(1) DEFAULT 0 COMMENT '1 = submitter attested the information is truthful',
    attested_at DATETIME DEFAULT NULL COMMENT 'When the truthfulness attestation was made',
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
    certification_type VARCHAR(100) DEFAULT NULL COMMENT 'SOC 2 Type II, ISO 27001, CAIQ, CSA STAR, etc.',
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
-- SECTION 11: CYBER TODO ACTIVITIES
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
-- SECTION 12: PERFORMANCE INDEXES
-- =============================================================================

-- Additional composite indexes for query performance
-- Plain CREATE INDEX is used here; duplicate-index errors (1061) are safe
-- to ignore during setup since the migration runner handles them.
CREATE INDEX idx_tprm_vendor_user ON tprm_results(vendor_name, user_id);
CREATE INDEX idx_tprm_status_created ON tprm_results(status, created_at);
CREATE INDEX idx_sessions_activity ON sessions(last_activity);

-- =============================================================================
-- SECTION 13: DEFAULT DATA
-- =============================================================================

-- Default admin user (password: Admin@123 - CHANGE THIS IMMEDIATELY AFTER INSTALL)
INSERT INTO users (username, password_hash, email, full_name, is_active, is_admin, is_super_admin, totp_enabled)
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
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

-- Localization / language settings
('default_language', 'en', 0, 'Default UI language code for new users and pre-login pages'),
('enabled_languages', '["en","es","it","uk","zh-Hans","hi","fr","pt"]', 0, 'JSON array of language codes users may choose in their profile'),

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
('openwebui_api_url', 'https://your-openwebui-instance.com/api/chat/completions', 0, 'OpenWebUI API endpoint URL'),
('openwebui_jwt_token', '', 1, 'OpenWebUI JWT authentication token (encrypted)'),
('openwebui_model', 'novita.google/gemma-3-27b-it', 0, 'OpenWebUI model for generation'),
('openwebui_temperature', '0.7', 0, 'Model temperature (0.0-1.0)'),
('openwebui_max_tokens', '500', 0, 'Maximum tokens for response generation'),
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
('procurement_notification_selected_users', '[]', 0, 'JSON array of selected user IDs for procurement notifications'),

-- WAF lockdown settings
('waf_mode', 'disabled', 0, 'WAF lockdown mode: disabled, learning, enforcing'),
('waf_learning_started_at', '', 0, 'Timestamp when WAF learning mode was started'),
('waf_audit_log_max_mb', '100', 0, 'Maximum audit log size in MB before rotation'),
('waf_scanner_blocking', '0', 0, 'Enable/disable automated scanner/attack detection rules')
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

-- Vendor Onboarding Questions - Section 1: Vendor Information
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order, field_name) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the vendor''s legal name?', 'text', NULL, 1, NULL, 1, 'vendor_name'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the vendor''s primary domain?', 'text', NULL, 0, 'e.g., vendor.com', 2, 'vendor_domain'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'Has this vendor completed Procurement Onboarding?', 'radio', '["Yes", "No"]', 1, NULL, 3, 'vsu_onboarded'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the Vendor ID (VID)?', 'text', NULL, 0, '4-8 digit vendor ID from VSU procurement system.', 4, 'vendor_id'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the vendor type?', 'select', '["General Operations", "Technology", "Professional Services", "Financial Services", "Marketing", "HR/Benefits", "Facilities", "Legal", "Other"]', 1, NULL, 5, 'vendor_type'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'Who is the relationship manager for this vendor?', 'text', NULL, 1, 'Contact person responsible for the vendor relationship.', 6, 'relationship_manager'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the expected procurement date?', 'date', NULL, 1, NULL, 7, 'expected_procurement_date'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'Describe the product or service being provided.', 'textarea', NULL, 1, NULL, 8, 'product_service_description'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'How many users will use this product/service?', 'text', NULL, 1, 'e.g., 50, 100-200', 9, 'target_user_count'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the primary contact email?', 'text', NULL, 1, NULL, 10, 'primary_contact_email'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the primary contact''s full name?', 'text', NULL, 1, NULL, 11, 'primary_contact_details'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the primary contact''s title?', 'text', NULL, 0, 'e.g., Account Manager, Sales Director', 12, 'primary_contact_title'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'What is the primary contact''s direct phone number?', 'phone', NULL, 0, NULL, 13, 'primary_contact_phone'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'Is an NDA in place with this vendor?', 'radio', '["Yes", "No"]', 1, NULL, 14, 'nda_in_place'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1),
'List any known vendor competitors.', 'textarea', NULL, 1, 'Include companies offering similar products or services.', 15, 'vendor_competitors');

-- Vendor Onboarding Questions - Section 2: Data & Risk Assessment
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
'Will confidential information be shared with this vendor?', 'radio', '["Yes", "No"]', 1, NULL, 5, 'confidential_info_shared'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the confidential information to be shared.', 'textarea', NULL, 0, 'Required if confidential information will be shared.', 6, 'confidential_info_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Will data be transferred across international borders?', 'radio', '["Yes", "No"]', 1, NULL, 7, 'cross_border_transfer'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the cross-border data transfer details.', 'textarea', NULL, 0, 'Required if cross-border transfer applies.', 8, 'cross_border_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Will data be hosted off-site by this vendor?', 'radio', '["Yes", "No"]', 1, NULL, 9, 'offsite_data_hosting'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the off-site data hosting arrangements.', 'textarea', NULL, 0, 'Required if off-site hosting applies.', 10, 'offsite_data_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Will this vendor have remote access to your network?', 'radio', '["Yes", "No"]', 1, NULL, 11, 'remote_network_access'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the remote network access requirements.', 'textarea', NULL, 0, 'Required if remote access will be granted.', 12, 'remote_access_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Will this vendor have access to source code or repositories?', 'radio', '["Yes", "No"]', 1, NULL, 13, 'source_code_access'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the source code access requirements.', 'textarea', NULL, 0, 'Required if source code access will be granted.', 14, 'source_code_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Does this vendor support a critical business function?', 'radio', '["Yes", "No"]', 1, NULL, 15, 'critical_business_function'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the critical business function.', 'textarea', NULL, 0, 'Required if vendor supports a critical function.', 16, 'critical_function_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'What would be the impact of unauthorized disclosure of data by this vendor?', 'radio', '["Low", "Moderate", "High", "Severe"]', 1, NULL, 17, 'unauthorized_disclosure_impact'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Describe the potential impact of unauthorized disclosure.', 'textarea', NULL, 0, NULL, 18, 'unauthorized_disclosure_justification'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'What would be the impact of unauthorized modification of data by this vendor?', 'radio', '["Low", "Moderate", "High", "Severe"]', 1, NULL, 19, 'unauthorized_modification_impact'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'What would be the impact of disruption to this vendor''s service?', 'radio', '["Low", "Moderate", "High", "Severe"]', 1, NULL, 20, 'disruption_impact'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Does this vendor support SAML/SSO authentication?', 'radio', '["Yes", "No", "Unknown"]', 1, NULL, 21, 'saml_sso_support'),
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2),
'Is this a SaaS (Software as a Service) product?', 'radio', '["Yes", "No"]', 1, NULL, 22, 'is_saas');

-- Vendor Onboarding Questions - Section 3: Additional Information
INSERT IGNORE INTO assessment_questions (section_id, question_text, question_type, options, is_required, help_text, sort_order, field_name) VALUES
((SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 3),
'Is there any additional information you would like to share about this vendor engagement?', 'textarea', NULL, 0, NULL, 1, 'additional_information');

-- Note: Assessment sections and questions are seeded via includes/schema/vendor_assessments.sql
-- Run that file after this schema for complete assessment data

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
-- SECTION 20: WAF LOCKDOWN TABLES (ModSecurity positive security model)
-- =============================================================================

CREATE TABLE IF NOT EXISTS waf_learned_patterns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uri VARCHAR(500) NOT NULL,
    method VARCHAR(10) NOT NULL DEFAULT 'GET',
    param_names TEXT DEFAULT NULL,
    content_type VARCHAR(200) DEFAULT '',
    response_status SMALLINT UNSIGNED DEFAULT 200,
    frequency INT UNSIGNED DEFAULT 1,
    first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_uri_method_ct (uri(191), method, content_type(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waf_whitelist_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    uri_pattern VARCHAR(500) NOT NULL,
    method_pattern VARCHAR(10) NOT NULL DEFAULT '*',
    rule_text TEXT NOT NULL,
    description VARCHAR(500) DEFAULT '',
    is_auto_generated TINYINT(1) DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rule_id (rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waf_block_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    blocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    client_ip VARCHAR(45) DEFAULT '',
    uri VARCHAR(500) NOT NULL,
    method VARCHAR(10) NOT NULL DEFAULT 'GET',
    rule_id INT UNSIGNED DEFAULT 0,
    rule_message VARCHAR(500) DEFAULT '',
    request_headers TEXT DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT '',
    resolved TINYINT(1) DEFAULT 0,
    INDEX idx_blocked_at (blocked_at),
    INDEX idx_client_ip (client_ip),
    INDEX idx_uri (uri(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waf_ip_whitelist (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    label VARCHAR(200) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waf_learned_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uri VARCHAR(500) NOT NULL,
    method VARCHAR(10) NOT NULL DEFAULT 'GET',
    client_ip VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) DEFAULT '',
    query_string TEXT DEFAULT NULL,
    content_type VARCHAR(200) DEFAULT '',
    response_status SMALLINT UNSIGNED DEFAULT 200,
    request_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_uri_method (uri(191), method),
    INDEX idx_client_ip (client_ip),
    INDEX idx_request_at (request_at)
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
