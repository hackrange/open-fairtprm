-- =============================================================================
-- TPRM FAIR Analysis - SQLite Database Schema
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

-- Enable foreign key enforcement
PRAGMA foreign_keys = ON;

-- =============================================================================
-- SECTION 1: USER MANAGEMENT
-- =============================================================================

-- Users table - Core user accounts with encrypted passwords
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,                          -- Bcrypt/Argon2 hashed password with automatic salt
    email TEXT NOT NULL,
    full_name TEXT NOT NULL,
    is_active INTEGER DEFAULT 1,
    is_admin INTEGER DEFAULT 0,
    is_super_admin INTEGER DEFAULT 0,                    -- Super admin with full system access
    department TEXT DEFAULT NULL,                         -- User department
    job_title TEXT DEFAULT NULL,                          -- User job title
    totp_enabled INTEGER DEFAULT 0,
    totp_secret TEXT DEFAULT NULL,                        -- Encrypted TOTP secret
    failed_login_attempts INTEGER DEFAULT 0,
    last_failed_login TEXT DEFAULT NULL,
    account_locked_until TEXT DEFAULT NULL,
    last_login TEXT DEFAULT NULL,
    -- Theme customization per user
    theme_logo_url TEXT DEFAULT NULL,                     -- User-specific header logo URL
    theme_footer_logo_url TEXT DEFAULT NULL,              -- User-specific footer logo URL
    theme_header_color TEXT DEFAULT NULL,                 -- User-specific header color
    theme_footer_color TEXT DEFAULT NULL,                 -- User-specific footer color
    theme_button_color TEXT DEFAULT NULL,                 -- User-specific button color
    theme_nav_fill_color TEXT DEFAULT NULL,               -- User-specific navigation fill color
    theme_nav_font_color TEXT DEFAULT NULL,               -- User-specific navigation font color
    theme_nav_width TEXT DEFAULT NULL,                    -- User-specific navigation width in pixels
    dashboard_modules TEXT DEFAULT NULL,                  -- JSON array of selected dashboard module keys (max 9)
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active);

-- =============================================================================
-- SECTION 2: ACCESS CONTROL LIST (ACL)
-- =============================================================================

-- ACL Groups/Roles table
CREATE TABLE IF NOT EXISTS acl_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_name TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    description TEXT,
    is_active INTEGER DEFAULT 1,
    is_system INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_acl_groups_group_name ON acl_groups(group_name);
CREATE INDEX IF NOT EXISTS idx_acl_groups_is_active ON acl_groups(is_active);

-- User Group Assignments (Many-to-Many)
CREATE TABLE IF NOT EXISTS user_acl_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,
    assigned_by INTEGER,
    UNIQUE(user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES acl_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_user_acl_groups_user_id ON user_acl_groups(user_id);
CREATE INDEX IF NOT EXISTS idx_user_acl_groups_group_id ON user_acl_groups(group_id);

-- Granular Permissions
CREATE TABLE IF NOT EXISTS acl_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    permission_code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    module TEXT,
    resource TEXT,
    action TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_acl_permissions_module ON acl_permissions(module);
CREATE INDEX IF NOT EXISTS idx_acl_permissions_resource_action ON acl_permissions(resource, action);
CREATE INDEX IF NOT EXISTS idx_acl_permissions_permission_code ON acl_permissions(permission_code);

-- Group-Permission Mapping
CREATE TABLE IF NOT EXISTS acl_group_permissions (
    group_id INTEGER NOT NULL,
    permission_id INTEGER NOT NULL,
    granted_at TEXT DEFAULT CURRENT_TIMESTAMP,
    granted_by INTEGER,
    PRIMARY KEY (group_id, permission_id),
    FOREIGN KEY (group_id) REFERENCES acl_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES acl_permissions(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =============================================================================
-- SECTION 3: SESSIONS AND AUTHENTICATION
-- =============================================================================

-- SAML Group to ACL Group Mapping (for auto-provisioning)
CREATE TABLE IF NOT EXISTS saml_group_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    saml_group_name TEXT NOT NULL UNIQUE,                 -- SAML group/role claim value
    acl_group_id INTEGER NOT NULL,
    auto_assign INTEGER DEFAULT 1,                       -- Automatically assign on SAML login
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (acl_group_id) REFERENCES acl_groups(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_saml_group_mappings_saml_group_name ON saml_group_mappings(saml_group_name);

-- Sessions table - Secure session management
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    ip_address TEXT NOT NULL,
    user_agent TEXT,
    payload TEXT NOT NULL,
    last_activity INTEGER NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_last_activity ON sessions(last_activity);

-- SAML configuration table
CREATE TABLE IF NOT EXISTS saml_config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    is_enabled INTEGER DEFAULT 0,
    idp_entity_id TEXT NOT NULL,
    idp_sso_url TEXT NOT NULL,
    idp_slo_url TEXT,
    idp_certificate TEXT NOT NULL,                       -- X.509 certificate
    sp_entity_id TEXT NOT NULL,
    sp_acs_url TEXT NOT NULL,
    sp_slo_url TEXT,
    sp_certificate TEXT,
    sp_private_key TEXT,                                 -- Encrypted private key
    attribute_mapping TEXT,                               -- Map SAML attributes to user fields (JSON stored as TEXT)
    group_attribute TEXT DEFAULT 'groups',                -- SAML assertion attribute name containing group claims
    auto_activate INTEGER NOT NULL DEFAULT 1,            -- Auto-activate new SSO-provisioned accounts (1=active, 0=pending)
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- SECTION 4: APPLICATION CONFIGURATION
-- =============================================================================

-- Application configuration table (key-value store, some values encrypted)
CREATE TABLE IF NOT EXISTS app_config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    config_key TEXT NOT NULL UNIQUE,
    config_value TEXT NOT NULL,                           -- Encrypted sensitive values
    is_encrypted INTEGER DEFAULT 0,
    description TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_app_config_config_key ON app_config(config_key);

-- =============================================================================
-- SECTION 5: FAIR ANALYSIS / TPRM RESULTS
-- =============================================================================

-- TPRM Results table - All sensitive data encrypted at rest via AES-256-CBC
CREATE TABLE IF NOT EXISTS tprm_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    vendor_name TEXT NOT NULL,
    vendor_domain TEXT DEFAULT NULL,                      -- Vendor domain name (e.g., example.com)

    -- Cyber Security Section - Encrypted
    msa TEXT,                                            -- Encrypted: Master Services Agreement
    scope_of_work TEXT,                                  -- Encrypted: Description of work
    medium_of_data TEXT,                                 -- Encrypted: Data transfer method
    certifications TEXT,                                 -- Encrypted: Security certifications
    compliance TEXT,                                     -- Encrypted: Compliance info
    security_governance TEXT,                            -- Encrypted: Security governance
    incident_response_plan TEXT,                         -- Encrypted: Incident response plan
    continuous_monitoring TEXT,                          -- Encrypted: Continuous monitoring
    supply_chain_risk_mgmt TEXT,                         -- Encrypted: Supply chain risk management
    security_awareness_training TEXT,                    -- Encrypted: Security awareness training
    vulnerability_management TEXT,                       -- Encrypted: Vulnerability management
    patch_management TEXT,                               -- Encrypted: Patch management
    access_controls TEXT,                                -- Encrypted: Access controls
    data_encryption TEXT,                                -- Encrypted: Data encryption
    network_security TEXT,                               -- Encrypted: Network security
    daily_impact TEXT,                                   -- Encrypted: Daily financial impact if vendor services are disrupted

    -- Vendor Risk Section - Encrypted
    security_score TEXT,                                 -- Security grade: A, B, C, D, or F
    vulnerability_data TEXT,                             -- Encrypted: Vulnerability data
    configuration_data TEXT,                             -- Encrypted: Configuration data
    compliance_data TEXT,                                -- Encrypted: Compliance data
    risk_assessment TEXT,                                -- Encrypted: Risk assessment
    threat_intelligence TEXT,                            -- Encrypted: Threat intelligence
    iso_27001_certified INTEGER DEFAULT 0,               -- ISO 27001 certification status (0=No, 1=Yes)
    securityscorecard_rating TEXT DEFAULT NULL,           -- SecurityScorecard rating: A, B, C, D, or F
    pii_record_count INTEGER DEFAULT 0,                  -- Number of PII records - $160 per record breach cost
    spii_record_count INTEGER DEFAULT 0,                 -- Number of SPII records - $200 per record breach cost
    sox_record_count INTEGER DEFAULT 0,                  -- Number of SOX records - $5M flat fine if breached

    -- SRS Input Section - Encrypted
    vendor_risk_assessment TEXT,                         -- Encrypted: Vendor risk assessment
    security_questionnaire TEXT,                         -- Encrypted: Security questionnaire
    compliance_questionnaire TEXT,                       -- Encrypted: Compliance questionnaire
    data_classification TEXT,                            -- Encrypted: Data classification
    data_sharing TEXT,                                   -- Encrypted: Data sharing
    business_impact TEXT,                                -- Encrypted: Business impact
    vendor_performance TEXT,                             -- Encrypted: Vendor performance

    -- Vendor Third-Party Vendors Section - Encrypted
    third_party_vendor_list TEXT,                        -- Encrypted: Third-party vendor list
    third_party_risk_assessment TEXT,                    -- Encrypted: Third-party risk assessment
    third_party_security_questionnaire TEXT,             -- Encrypted: Third-party security questionnaire
    third_party_compliance_questionnaire TEXT,           -- Encrypted: Third-party compliance questionnaire
    vendor_cyber_insurance_coverage TEXT,                -- Encrypted: Vendor cyber insurance coverage amount

    -- Cost and Financial Information Section - Encrypted
    cost_of_breach TEXT,                                 -- Encrypted: Cost of breach
    cost_of_outage TEXT,                                 -- Encrypted: Cost of outage
    sec_fines TEXT,                                      -- Encrypted: SEC fines
    compliance_fines TEXT,                               -- Encrypted: Compliance fines
    total_cost_of_breach TEXT,                           -- Encrypted: Total cost of breach calculation
    pii_breach_cost TEXT,                                -- Encrypted: PII breach cost calculation
    spii_breach_cost TEXT,                               -- Encrypted: SPII breach cost calculation
    sox_breach_cost TEXT,                                -- Encrypted: SOX breach cost calculation
    insurance_premiums TEXT,                             -- Encrypted: Insurance premiums

    -- FAIR Model Output Section - Encrypted
    loss_event_frequency TEXT,                           -- Encrypted: Loss Event Frequency
    loss_magnitude TEXT,                                 -- Encrypted: Loss Magnitude
    primary_loss_magnitude TEXT,                         -- Encrypted: Primary Loss Magnitude
    secondary_loss_magnitude TEXT,                       -- Encrypted: Secondary Loss Magnitude
    risk_output TEXT,                                    -- Encrypted: Risk output
    ale TEXT,                                            -- Encrypted: Annualized Loss Expectancy
    recommended_liability TEXT,                          -- Encrypted: Recommended Liability

    -- Executive Summary (AI-generated, stored once)
    executive_summary TEXT DEFAULT NULL,                  -- AI-generated executive summary for PDF reports

    -- Metadata
    status TEXT DEFAULT 'draft' CHECK(status IN ('draft', 'completed', 'archived')),
    completed_at TEXT DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tprm_results_user_id ON tprm_results(user_id);
CREATE INDEX IF NOT EXISTS idx_tprm_results_vendor_name ON tprm_results(vendor_name);
CREATE INDEX IF NOT EXISTS idx_tprm_results_vendor_domain ON tprm_results(vendor_domain);
CREATE INDEX IF NOT EXISTS idx_tprm_results_status ON tprm_results(status);
CREATE INDEX IF NOT EXISTS idx_tprm_results_created_at ON tprm_results(created_at);

-- =============================================================================
-- SECTION 6: VENDOR ONBOARDING
-- =============================================================================

-- Vendor Onboarding Requests table
CREATE TABLE IF NOT EXISTS vendor_onboarding_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Stakeholder/Owner Information
    created_by INTEGER NOT NULL,                         -- User who created this request

    -- Section 1: Vendor Information
    vendor_name TEXT DEFAULT NULL,                        -- 1.1 Vendor Name
    vendor_domain TEXT DEFAULT NULL,                      -- Vendor domain name for SRS scoring
    vendor_id TEXT DEFAULT NULL,                          -- VSU Vendor ID (VID)
    vendor_type TEXT DEFAULT NULL,                        -- 1.2 Vendor Type
    vendor_tier TEXT DEFAULT NULL CHECK(vendor_tier IN ('1', '2', '3')),  -- Vendor tier for SRS rescoring: 1=Monthly, 2=90-days, 3=Annual
    relationship_manager TEXT DEFAULT NULL,               -- 1.3 Business relationship manager
    expected_procurement_date TEXT DEFAULT NULL,          -- 1.4 Expected procurement date
    product_service_description TEXT,                     -- 1.5 Product/service description
    target_user_count TEXT DEFAULT NULL,                  -- 1.6 Target user count
    primary_contact_email TEXT DEFAULT NULL,              -- 1.7 Primary contact email
    primary_contact_details TEXT,                         -- 1.10 Primary contact full name
    primary_contact_title TEXT DEFAULT NULL,              -- 1.10a Primary contact job title
    primary_contact_phone TEXT DEFAULT NULL,              -- 1.10b Primary contact direct phone
    nda_in_place TEXT DEFAULT '' CHECK(nda_in_place IN ('yes', 'no', '')),  -- 1.9 Is NDA in place?
    vendor_competitors TEXT,                              -- 1.10 Vendor competitors
    vsu_onboarded TEXT DEFAULT '' CHECK(vsu_onboarded IN ('yes', 'no', '')),  -- VSU onboarding status

    -- Section 2: Data & Security Questions
    pii_phi_exchange TEXT DEFAULT '' CHECK(pii_phi_exchange IN ('yes', 'no', '')),  -- 2.1 PII/PHI exchange?
    pii_phi_justification TEXT,                          -- 2.1 PII/PHI justification
    confidential_info_shared TEXT DEFAULT '' CHECK(confidential_info_shared IN ('yes', 'no', '')),  -- 2.2 Confidential info shared?
    confidential_info_justification TEXT,                -- 2.2 Confidential info justification
    cross_border_transfer TEXT DEFAULT '' CHECK(cross_border_transfer IN ('yes', 'no', '')),  -- 2.3 Cross-border data transfer?
    cross_border_justification TEXT,                     -- 2.3 Cross-border justification
    offsite_data_hosting TEXT DEFAULT '' CHECK(offsite_data_hosting IN ('yes', 'no', '')),  -- 2.4 Off-site data hosting?
    offsite_data_justification TEXT,                     -- 2.4 Off-site data justification
    remote_network_access TEXT DEFAULT '' CHECK(remote_network_access IN ('yes', 'no', '')),  -- 2.5 Remote network access?
    remote_access_justification TEXT,                    -- 2.5 Remote access justification
    source_code_access TEXT DEFAULT '' CHECK(source_code_access IN ('yes', 'no', '')),  -- 2.6 Source code/repo access?
    source_code_justification TEXT,                      -- 2.6 Source code justification
    critical_business_function TEXT DEFAULT '' CHECK(critical_business_function IN ('yes', 'no', '')),  -- 2.7 Critical business function?
    critical_function_justification TEXT,                -- 2.7 Critical function justification
    unauthorized_disclosure_impact TEXT DEFAULT '' CHECK(unauthorized_disclosure_impact IN ('low', 'moderate', 'high', 'severe', '')),  -- 2.8 Impact of unauthorized disclosure
    unauthorized_disclosure_justification TEXT,          -- 2.8 Unauthorized disclosure justification
    unauthorized_modification_impact TEXT DEFAULT '' CHECK(unauthorized_modification_impact IN ('low', 'moderate', 'high', 'severe', '')),  -- 2.9 Impact of unauthorized modification
    disruption_impact TEXT DEFAULT '' CHECK(disruption_impact IN ('low', 'moderate', 'high', 'severe', '')),  -- 2.10 Impact of disruption
    saml_sso_support TEXT DEFAULT '' CHECK(saml_sso_support IN ('yes', 'no', 'unknown', '')),  -- 2.11 SAML/SSO support?
    is_saas TEXT DEFAULT '' CHECK(is_saas IN ('yes', 'no', '')),  -- 2.12 Is SaaS product?

    -- FAIR Analysis Fields
    pii_record_count INTEGER DEFAULT 0,                  -- Number of PII records
    spii_record_count INTEGER DEFAULT 0,                 -- Number of SPII records
    sox_record_count INTEGER DEFAULT 0,                  -- Number of SOX records
    business_impact REAL DEFAULT NULL,                   -- Business impact value

    -- Section 3: Additional Information
    additional_information TEXT,                          -- 3.1 Additional information

    -- SRS Fields
    current_srs_score INTEGER DEFAULT NULL,              -- Current UpGuard SRS score (0-950)
    last_srs_score_at TEXT DEFAULT NULL,                  -- Last SRS score fetch time

    -- Shodan Fields
    current_shodan_score INTEGER DEFAULT NULL,            -- Current Shodan security score (0-100)
    last_shodan_score_at TEXT DEFAULT NULL,                -- Last Shodan score fetch time

    -- Background Rescore Status
    rescore_status TEXT DEFAULT NULL,                     -- rescoring, rescoring_shodan, rescoring_upguard, or NULL
    rescore_started_at TEXT DEFAULT NULL,                 -- When the background rescore started
    rescore_result TEXT DEFAULT NULL,                     -- Result message from last background rescore

    -- Vendor Favicon
    vendor_favicon BLOB DEFAULT NULL,                    -- Vendor favicon image data
    vendor_favicon_mime TEXT DEFAULT NULL,                -- MIME type of stored favicon

    -- Status and Workflow
    status TEXT DEFAULT 'draft' CHECK(status IN ('draft', 'submitted', 'in_review', 'approved', 'rejected', 'inactive', 'evaluation')),
    status_notes TEXT,                                   -- Notes about status changes
    marked_inactive_at TEXT DEFAULT NULL,                 -- When marked as inactive
    marked_inactive_by INTEGER DEFAULT NULL,              -- Who marked it inactive

    -- Annual Review Tracking
    last_annual_review TEXT DEFAULT NULL,                 -- Last annual review date with stakeholder
    last_annual_review_due TEXT DEFAULT NULL,             -- Next annual review due date (calculated from last review or approval date)

    -- Timestamps
    last_autosave TEXT DEFAULT NULL,                      -- Last autosave timestamp
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    submitted_at TEXT DEFAULT NULL,                       -- When form was submitted

    -- Foreign Keys
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_inactive_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_vendor_onboarding_created_by ON vendor_onboarding_requests(created_by);
CREATE INDEX IF NOT EXISTS idx_vendor_onboarding_status ON vendor_onboarding_requests(status);
CREATE INDEX IF NOT EXISTS idx_vendor_onboarding_vendor_name ON vendor_onboarding_requests(vendor_name);
CREATE INDEX IF NOT EXISTS idx_vendor_onboarding_vendor_domain ON vendor_onboarding_requests(vendor_domain);
CREATE INDEX IF NOT EXISTS idx_vendor_onboarding_vendor_tier ON vendor_onboarding_requests(vendor_tier);
CREATE INDEX IF NOT EXISTS idx_vendor_onboarding_last_srs_score ON vendor_onboarding_requests(last_srs_score_at);
CREATE INDEX IF NOT EXISTS idx_vendor_onboarding_created_at ON vendor_onboarding_requests(created_at);
CREATE INDEX IF NOT EXISTS idx_vendor_onboarding_submitted_at ON vendor_onboarding_requests(submitted_at);
CREATE INDEX IF NOT EXISTS idx_vendor_onboarding_last_annual_review ON vendor_onboarding_requests(last_annual_review);
CREATE INDEX IF NOT EXISTS idx_vendor_onboarding_last_annual_review_due ON vendor_onboarding_requests(last_annual_review_due);

-- Stakeholder assignments table
CREATE TABLE IF NOT EXISTS vendor_onboarding_stakeholders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT DEFAULT 'stakeholder' CHECK(role IN ('owner', 'stakeholder', 'reviewer')),
    assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,
    assigned_by INTEGER,

    UNIQUE(request_id, user_id),

    FOREIGN KEY (request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_vendor_stakeholders_request_id ON vendor_onboarding_stakeholders(request_id);
CREATE INDEX IF NOT EXISTS idx_vendor_stakeholders_user_id ON vendor_onboarding_stakeholders(user_id);

-- Annual review history table
CREATE TABLE IF NOT EXISTS vendor_annual_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_request_id INTEGER NOT NULL,
    review_date TEXT NOT NULL,
    due_date TEXT NOT NULL,
    reviewer_user_id INTEGER NOT NULL,

    -- Review responses
    is_still_stakeholder TEXT NOT NULL CHECK(is_still_stakeholder IN ('yes', 'no')),
    new_stakeholder_id INTEGER DEFAULT NULL,              -- If stakeholder changed, new stakeholder user ID
    scope_changes TEXT,                                  -- Description of any scope changes
    contact_updates TEXT DEFAULT NULL,                    -- JSON object with updated contact information (stored as TEXT)
    review_notes TEXT,                                   -- Additional notes from reviewer

    -- Timestamps
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,

    -- Foreign key
    FOREIGN KEY (vendor_request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_vendor_annual_reviews_vendor_request ON vendor_annual_reviews(vendor_request_id);
CREATE INDEX IF NOT EXISTS idx_vendor_annual_reviews_review_date ON vendor_annual_reviews(review_date);
CREATE INDEX IF NOT EXISTS idx_vendor_annual_reviews_due_date ON vendor_annual_reviews(due_date);
CREATE INDEX IF NOT EXISTS idx_vendor_annual_reviews_reviewer ON vendor_annual_reviews(reviewer_user_id);

-- Reminder tracking table
CREATE TABLE IF NOT EXISTS vendor_review_reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_request_id INTEGER NOT NULL,
    stakeholder_user_id INTEGER NOT NULL,
    reminder_type TEXT NOT NULL CHECK(reminder_type IN ('30_days_before', 'due_date', 'overdue')),
    due_date TEXT NOT NULL,
    sent_at TEXT DEFAULT NULL,
    email_sent_to TEXT DEFAULT NULL,
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'sent', 'failed')),
    error_message TEXT,

    created_at TEXT DEFAULT CURRENT_TIMESTAMP,

    -- Unique constraint to prevent duplicate reminders
    UNIQUE(vendor_request_id, stakeholder_user_id, reminder_type, due_date),

    -- Foreign key
    FOREIGN KEY (vendor_request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_vendor_reminders_vendor_request ON vendor_review_reminders(vendor_request_id);
CREATE INDEX IF NOT EXISTS idx_vendor_reminders_stakeholder ON vendor_review_reminders(stakeholder_user_id);
CREATE INDEX IF NOT EXISTS idx_vendor_reminders_status ON vendor_review_reminders(status);
CREATE INDEX IF NOT EXISTS idx_vendor_reminders_sent_at ON vendor_review_reminders(sent_at);

-- =============================================================================
-- SECTION 7: SRS (SECURITY RATING SERVICE) SCORING
-- =============================================================================

-- SRS Score History table (for trending)
CREATE TABLE IF NOT EXISTS vendor_srs_scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_onboarding_id INTEGER NOT NULL,               -- Reference to vendor_onboarding_requests
    vendor_domain TEXT NOT NULL,                          -- Vendor domain that was scored

    -- UpGuard Score Data
    score INTEGER NOT NULL,                              -- UpGuard SRS score (0-950)
    score_grade TEXT DEFAULT NULL,                        -- Grade (A, B, C, D, F)

    -- Category Scores (from UpGuard)
    category_scores TEXT DEFAULT NULL,                    -- JSON object with category breakdowns (stored as TEXT)

    -- Risk Counts
    critical_risks INTEGER DEFAULT 0,                    -- Number of critical severity risks
    high_risks INTEGER DEFAULT 0,                        -- Number of high severity risks
    medium_risks INTEGER DEFAULT 0,                      -- Number of medium severity risks
    low_risks INTEGER DEFAULT 0,                         -- Number of low severity risks
    info_risks INTEGER DEFAULT 0,                        -- Number of informational risks

    -- Was vendor already monitored in UpGuard?
    was_already_monitored INTEGER DEFAULT 0,              -- Was vendor already being monitored

    -- Timestamps
    scored_at TEXT NOT NULL,                              -- When the score was fetched
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Key
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_vendor_srs_scores_vendor_onboarding ON vendor_srs_scores(vendor_onboarding_id);
CREATE INDEX IF NOT EXISTS idx_vendor_srs_scores_vendor_domain ON vendor_srs_scores(vendor_domain);
CREATE INDEX IF NOT EXISTS idx_vendor_srs_scores_scored_at ON vendor_srs_scores(scored_at);
CREATE INDEX IF NOT EXISTS idx_vendor_srs_scores_score ON vendor_srs_scores(score);

-- SRS Risk Details table
CREATE TABLE IF NOT EXISTS vendor_srs_risks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    srs_score_id INTEGER NOT NULL,                       -- Reference to vendor_srs_scores

    -- Risk Details
    risk_id TEXT NOT NULL,                               -- UpGuard risk identifier
    risk_name TEXT DEFAULT NULL,                          -- Human-readable risk name
    risk_category TEXT DEFAULT NULL,                      -- Risk category
    severity TEXT DEFAULT NULL,                           -- critical, high, medium, low, info
    risk_host TEXT DEFAULT NULL,                          -- Hostname(s) or IP(s) where risk was detected
    description TEXT DEFAULT NULL,                        -- Risk description

    -- Timestamps
    first_seen TEXT DEFAULT NULL,                         -- When risk was first detected
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Key
    FOREIGN KEY (srs_score_id) REFERENCES vendor_srs_scores(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_vendor_srs_risks_srs_score ON vendor_srs_risks(srs_score_id);
CREATE INDEX IF NOT EXISTS idx_vendor_srs_risks_risk_id ON vendor_srs_risks(risk_id);
CREATE INDEX IF NOT EXISTS idx_vendor_srs_risks_severity ON vendor_srs_risks(severity);
CREATE INDEX IF NOT EXISTS idx_vendor_srs_risks_risk_category ON vendor_srs_risks(risk_category);

-- Vendor Subdomain Scores (UpGuard /vendor/domains)
CREATE TABLE IF NOT EXISTS vendor_subdomain_scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_onboarding_id INTEGER NOT NULL,
    vendor_domain TEXT NOT NULL,
    subdomain TEXT NOT NULL,
    score INTEGER DEFAULT NULL,
    score_grade TEXT DEFAULT NULL,
    is_active INTEGER DEFAULT 1,
    last_scanned TEXT DEFAULT NULL,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(vendor_onboarding_id, subdomain),
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_vss_vendor_onboarding ON vendor_subdomain_scores(vendor_onboarding_id);
CREATE INDEX IF NOT EXISTS idx_vss_vendor_domain ON vendor_subdomain_scores(vendor_domain);
CREATE INDEX IF NOT EXISTS idx_vss_subdomain ON vendor_subdomain_scores(subdomain);

-- =============================================================================
-- SECTION 7B: SHODAN SRS SCORING
-- =============================================================================

-- Shodan Score History table
CREATE TABLE IF NOT EXISTS vendor_shodan_scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_onboarding_id INTEGER NOT NULL,
    vendor_domain TEXT NOT NULL,
    score INTEGER NOT NULL,                                  -- Shodan security score (0-100)
    score_grade TEXT DEFAULT NULL,
    open_ports_count INTEGER DEFAULT 0,
    vuln_count INTEGER DEFAULT 0,
    critical_vulns INTEGER DEFAULT 0,
    high_vulns INTEGER DEFAULT 0,
    medium_vulns INTEGER DEFAULT 0,
    low_vulns INTEGER DEFAULT 0,
    category_scores TEXT DEFAULT NULL,                        -- JSON: per-category scores
    traffic_light TEXT DEFAULT NULL,                          -- Traffic light rating: green, yellow, red
    positive_count INTEGER DEFAULT 0,
    negative_count INTEGER DEFAULT 0,
    subdomains_scanned TEXT DEFAULT NULL,                     -- JSON: list of subdomains scanned
    ip_addresses TEXT DEFAULT NULL,                           -- JSON
    scored_at TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_vendor_shodan_scores_vendor_onboarding ON vendor_shodan_scores(vendor_onboarding_id);
CREATE INDEX IF NOT EXISTS idx_vendor_shodan_scores_vendor_domain ON vendor_shodan_scores(vendor_domain);
CREATE INDEX IF NOT EXISTS idx_vendor_shodan_scores_scored_at ON vendor_shodan_scores(scored_at);
CREATE INDEX IF NOT EXISTS idx_vendor_shodan_scores_score ON vendor_shodan_scores(score);

-- Shodan Finding Details table
CREATE TABLE IF NOT EXISTS vendor_shodan_findings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shodan_score_id INTEGER NOT NULL,
    finding_type TEXT NOT NULL CHECK(finding_type IN (
        'open_port', 'vulnerability', 'service',
        'tls_crypto', 'network_security', 'app_hardening',
        'email_security', 'positive_signal', 'negative_signal'
    )),
    ip_address TEXT DEFAULT NULL,
    port INTEGER DEFAULT NULL,
    protocol TEXT DEFAULT NULL,
    service_name TEXT DEFAULT NULL,
    cve_id TEXT DEFAULT NULL,
    cvss_score REAL DEFAULT NULL,
    description TEXT DEFAULT NULL,
    severity TEXT DEFAULT NULL,
    signal_type TEXT DEFAULT NULL,
    category TEXT DEFAULT NULL,
    points INTEGER DEFAULT NULL,
    confidence TEXT DEFAULT NULL,
    subdomain TEXT DEFAULT NULL,
    proof TEXT DEFAULT NULL,                                  -- JSON proof/evidence data
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shodan_score_id) REFERENCES vendor_shodan_scores(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_vendor_shodan_findings_shodan_score ON vendor_shodan_findings(shodan_score_id);
CREATE INDEX IF NOT EXISTS idx_vendor_shodan_findings_finding_type ON vendor_shodan_findings(finding_type);
CREATE INDEX IF NOT EXISTS idx_vendor_shodan_findings_cve_id ON vendor_shodan_findings(cve_id);

-- Shodan Risk Waivers (per-vendor, per-subdomain false positive management)
CREATE TABLE IF NOT EXISTS vendor_shodan_waivers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_onboarding_id INTEGER NOT NULL,
    signal_name TEXT NOT NULL,                                -- Signal identifier
    category TEXT NOT NULL,                                   -- Scoring category
    subdomain TEXT NOT NULL,                                  -- Subdomain scope
    label TEXT DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    waived_by TEXT DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(vendor_onboarding_id, signal_name, subdomain),
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_vendor_shodan_waivers_vendor ON vendor_shodan_waivers(vendor_onboarding_id);
CREATE INDEX IF NOT EXISTS idx_vendor_shodan_waivers_signal_lookup ON vendor_shodan_waivers(vendor_onboarding_id, signal_name, subdomain);

-- Shodan CVE Waivers (vendor-wide CVE false positive management)
CREATE TABLE IF NOT EXISTS vendor_shodan_cve_waivers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_onboarding_id INTEGER NOT NULL,
    cve_id TEXT NOT NULL,
    reason TEXT DEFAULT NULL,
    waived_by TEXT DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(vendor_onboarding_id, cve_id),
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_vendor_shodan_cve_waivers_vendor ON vendor_shodan_cve_waivers(vendor_onboarding_id);

-- Vendor Technology Inventory (4th Party Risk - extracted from Shodan scan data)
CREATE TABLE IF NOT EXISTS vendor_technologies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_onboarding_id INTEGER NOT NULL,
    vendor_domain TEXT NOT NULL,
    technology_name TEXT NOT NULL,
    technology_category TEXT NOT NULL,                        -- web_server, cdn_waf, cloud_platform, etc.
    technology_version TEXT DEFAULT NULL,
    detected_on TEXT DEFAULT NULL,                            -- Hostname/subdomain where detected
    detected_port INTEGER DEFAULT NULL,
    detection_method TEXT DEFAULT NULL,                       -- banner_product, http_server, etc.
    detection_confidence TEXT DEFAULT 'medium',               -- high, medium, low
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    is_current INTEGER DEFAULT 1,
    raw_evidence TEXT DEFAULT NULL,                           -- JSON evidence data
    cves TEXT DEFAULT NULL,                                   -- JSON array of CVE IDs
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(vendor_onboarding_id, technology_name, technology_category, detected_on),
    FOREIGN KEY (vendor_onboarding_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_vt_vendor_onboarding ON vendor_technologies(vendor_onboarding_id);
CREATE INDEX IF NOT EXISTS idx_vt_vendor_domain ON vendor_technologies(vendor_domain);
CREATE INDEX IF NOT EXISTS idx_vt_technology_name ON vendor_technologies(technology_name);
CREATE INDEX IF NOT EXISTS idx_vt_technology_category ON vendor_technologies(technology_category);
CREATE INDEX IF NOT EXISTS idx_vt_is_current ON vendor_technologies(is_current);

-- =============================================================================
-- SECTION 8: VENDOR ASSESSMENTS
-- =============================================================================

-- Assessment templates (ISO 27001:2022, Tier 2, etc.)
CREATE TABLE IF NOT EXISTS assessment_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    category TEXT DEFAULT 'vendor_assessment',            -- vendor_assessment, procurement, onboarding
    allow_certificate_upload INTEGER DEFAULT 0,
    certificate_upload_prompt TEXT,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Assessment sections/steps
CREATE TABLE IF NOT EXISTS assessment_sections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    sort_order INTEGER DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES assessment_templates(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_section_order ON assessment_sections(template_id, sort_order);

-- Assessment questions
CREATE TABLE IF NOT EXISTS assessment_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    section_id INTEGER NOT NULL,
    question_text TEXT NOT NULL,
    question_type TEXT NOT NULL DEFAULT 'text',
    options TEXT,                                         -- JSON array of options for select/radio/checkbox (stored as TEXT)
    is_required INTEGER DEFAULT 1,
    help_text TEXT,
    sort_order INTEGER DEFAULT 0,
    depends_on_question_id INTEGER DEFAULT NULL,          -- Show only when referenced question has specific value
    depends_on_value TEXT DEFAULT NULL,                   -- Required value of parent question for this to show
    field_name TEXT DEFAULT NULL,                         -- Maps to vendor_onboarding_requests column for onboarding templates
    FOREIGN KEY (section_id) REFERENCES assessment_sections(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_question_order ON assessment_questions(section_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_depends_on ON assessment_questions(depends_on_question_id);

-- Vendor assessment instances (sent to vendors)
CREATE TABLE IF NOT EXISTS vendor_assessments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,                           -- Public access token for vendor
    template_id INTEGER NOT NULL,
    vendor_request_id INTEGER,                           -- Link to vendor_onboarding_requests
    vendor_name TEXT NOT NULL,
    vendor_email TEXT NOT NULL,
    vendor_contact_name TEXT,                             -- Contact person name
    vendor_contact_email TEXT,                            -- Contact person email
    status TEXT DEFAULT 'pending',                        -- pending, in_progress, completed
    current_section_id INTEGER,                          -- Current section for progress tracking
    certificate_uploaded INTEGER DEFAULT 0,
    certificate_path TEXT,                               -- Path or db:ID reference for certificate
    certificate_expiry TEXT,
    started_at TEXT,
    completed_at TEXT,
    expires_at TEXT,
    created_by INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES assessment_templates(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (vendor_request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_vendor_assessments_status ON vendor_assessments(status);
CREATE INDEX IF NOT EXISTS idx_vendor_assessments_uuid ON vendor_assessments(uuid);
CREATE INDEX IF NOT EXISTS idx_vendor_assessments_vendor_request ON vendor_assessments(vendor_request_id);

-- Vendor assessment responses
CREATE TABLE IF NOT EXISTS vendor_assessment_responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INTEGER NOT NULL,
    question_id INTEGER NOT NULL,
    response_value TEXT,
    file_path TEXT,                                      -- Path or db:ID reference for file uploads
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(assessment_id, question_id),
    FOREIGN KEY (assessment_id) REFERENCES vendor_assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES assessment_questions(id)
);

CREATE INDEX IF NOT EXISTS idx_vendor_assessment_responses_assessment ON vendor_assessment_responses(assessment_id);

-- Assessment files (encrypted file storage in database)
CREATE TABLE IF NOT EXISTS assessment_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_uuid TEXT NOT NULL,                             -- Public-facing UUID for file references
    assessment_id INTEGER NOT NULL,
    file_type TEXT NOT NULL DEFAULT 'attachment',         -- attachment, certificate
    question_id INTEGER,                                 -- NULL for certificate uploads
    original_filename TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL,                          -- Size in bytes
    encrypted_data BLOB NOT NULL,                        -- AES-256 encrypted file data
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assessment_id) REFERENCES vendor_assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES assessment_questions(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_assessment_files_uuid ON assessment_files(file_uuid);
CREATE INDEX IF NOT EXISTS idx_assessment_files_assessment ON assessment_files(assessment_id);
CREATE INDEX IF NOT EXISTS idx_assessment_files_file_type ON assessment_files(file_type);

-- Vendor documents (encrypted file storage for contracts, certifications, etc.)
CREATE TABLE IF NOT EXISTS vendor_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_uuid TEXT NOT NULL,                              -- Public-facing UUID for download links
    vendor_request_id INTEGER NOT NULL,                   -- FK to vendor_onboarding_requests
    document_type TEXT NOT NULL,                          -- contract, certification, or other
    contract_name TEXT DEFAULT NULL,                      -- Contract display name
    contract_type TEXT DEFAULT NULL,                      -- DPA, Master Service Agreement, Privacy, Order Form, PO
    contract_creation_date TEXT DEFAULT NULL,
    contract_expiration_date TEXT DEFAULT NULL,
    certification_type TEXT DEFAULT NULL,                 -- SOC 2 Type II, ISO 27001, CAIQ, CSA STAR, etc.
    certification_expiration_date TEXT DEFAULT NULL,      -- Expiration date for certifications
    is_active INTEGER NOT NULL DEFAULT 1,                -- 1=Active, 0=Inactive
    description TEXT DEFAULT NULL,                        -- Description for other document type
    original_filename TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL,                          -- Size in bytes
    encrypted_data BLOB NOT NULL,                        -- AES-256 encrypted file data
    uploaded_by INTEGER NOT NULL,                        -- FK to users
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_request_id) REFERENCES vendor_onboarding_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_vendor_documents_uuid ON vendor_documents(file_uuid);
CREATE INDEX IF NOT EXISTS idx_vendor_documents_request ON vendor_documents(vendor_request_id);
CREATE INDEX IF NOT EXISTS idx_vendor_documents_type ON vendor_documents(document_type);
CREATE INDEX IF NOT EXISTS idx_vendor_documents_contract_exp ON vendor_documents(contract_expiration_date);
CREATE INDEX IF NOT EXISTS idx_vendor_documents_cert_type ON vendor_documents(certification_type);
CREATE INDEX IF NOT EXISTS idx_vendor_documents_cert_exp ON vendor_documents(certification_expiration_date);
CREATE INDEX IF NOT EXISTS idx_vendor_documents_is_active ON vendor_documents(is_active);

-- Contract expiry reminder tracking table
CREATE TABLE IF NOT EXISTS vendor_contract_reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_document_id INTEGER NOT NULL,
    recipient_user_id INTEGER NOT NULL,
    reminder_type TEXT NOT NULL,
    expiration_date TEXT NOT NULL,
    sent_at TEXT DEFAULT NULL,
    email_sent_to TEXT DEFAULT NULL,
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending','sent','failed')),
    error_message TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_document_id) REFERENCES vendor_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS unique_contract_reminder ON vendor_contract_reminders(vendor_document_id, recipient_user_id, reminder_type, expiration_date);
CREATE INDEX IF NOT EXISTS idx_vcr_vendor_document ON vendor_contract_reminders(vendor_document_id);
CREATE INDEX IF NOT EXISTS idx_vcr_recipient ON vendor_contract_reminders(recipient_user_id);
CREATE INDEX IF NOT EXISTS idx_vcr_status ON vendor_contract_reminders(status);

-- Email templates (customizable email content for vendor/stakeholder communications)
CREATE TABLE IF NOT EXISTS email_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_category TEXT NOT NULL,                       -- vendor, stakeholder, or procurement
    template_key TEXT NOT NULL,                            -- Machine identifier e.g. assessment_request
    assessment_template_id INTEGER DEFAULT NULL,           -- FK to assessment_templates.id (NULL = generic)
    display_name TEXT NOT NULL,
    email_subject TEXT NOT NULL,
    email_body_html TEXT NOT NULL,
    email_body_text TEXT NOT NULL,
    available_variables TEXT DEFAULT NULL,                  -- JSON array of placeholder names
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(template_category, template_key, assessment_template_id),
    FOREIGN KEY (assessment_template_id) REFERENCES assessment_templates(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_email_templates_category ON email_templates(template_category);

-- =============================================================================
-- SECTION 9: AUDIT LOGGING
-- =============================================================================

-- Audit log table for security tracking
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    table_name TEXT,
    record_id INTEGER,
    old_values TEXT,                                      -- JSON stored as TEXT
    new_values TEXT,                                      -- JSON stored as TEXT
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_log_user_id ON audit_log(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action);
CREATE INDEX IF NOT EXISTS idx_audit_log_table_name ON audit_log(table_name);
CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at);

-- =============================================================================
-- SECTION 10: CRON JOB EXECUTION HISTORY
-- =============================================================================

-- Cron job execution history for monitoring and troubleshooting
CREATE TABLE IF NOT EXISTS cron_execution_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_name TEXT NOT NULL,                               -- e.g., srs-rescore, assessment-reminder
    started_at TEXT NOT NULL,
    completed_at TEXT,
    status TEXT DEFAULT 'running' CHECK(status IN ('running', 'completed', 'failed')),
    processed_count INTEGER DEFAULT 0,
    success_count INTEGER DEFAULT 0,
    failed_count INTEGER DEFAULT 0,
    error_messages TEXT,                                  -- JSON array of error messages (stored as TEXT)
    execution_time_seconds REAL,                         -- Total execution time
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cron_execution_job_name ON cron_execution_history(job_name);
CREATE INDEX IF NOT EXISTS idx_cron_execution_started_at ON cron_execution_history(started_at);
CREATE INDEX IF NOT EXISTS idx_cron_execution_status ON cron_execution_history(status);

-- =============================================================================
-- SECTION 10B: CYBER TODO ACTIVITIES
-- =============================================================================

CREATE TABLE IF NOT EXISTS cyber_todo_activities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Reference to the related entity
    todo_type TEXT NOT NULL CHECK(todo_type IN ('cert_expiry', 'srs_rescore', 'score_drop', 'annual_review', 'not_approved', 'not_tiered', 'custom', 'user_import', 'vendor_import', 'contract_expiry')),
    reference_type TEXT NOT NULL,                             -- Table name reference
    reference_id INTEGER NOT NULL,

    -- Activity details
    activity_type TEXT DEFAULT 'note' CHECK(activity_type IN ('note', 'action', 'status_change', 'reminder', 'import', 'revert')),
    title TEXT DEFAULT NULL,
    description TEXT NOT NULL,
    metadata TEXT DEFAULT NULL,                               -- JSON: stores import data for revert capability

    -- Status tracking
    status TEXT DEFAULT 'open' CHECK(status IN ('open', 'in_progress', 'closed', 'deferred', 'reverted')),
    due_date TEXT DEFAULT NULL,
    closed_at TEXT DEFAULT NULL,

    -- User tracking
    created_by INTEGER NOT NULL,
    closed_by INTEGER DEFAULT NULL,
    assigned_to INTEGER DEFAULT NULL,

    -- Threading
    parent_id INTEGER DEFAULT NULL,                          -- Parent case ID for threaded responses

    -- Timestamps
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cyber_todo_todo_type ON cyber_todo_activities(todo_type);
CREATE INDEX IF NOT EXISTS idx_cyber_todo_reference ON cyber_todo_activities(reference_type, reference_id);
CREATE INDEX IF NOT EXISTS idx_cyber_todo_status ON cyber_todo_activities(status);
CREATE INDEX IF NOT EXISTS idx_cyber_todo_created_by ON cyber_todo_activities(created_by);
CREATE INDEX IF NOT EXISTS idx_cyber_todo_due_date ON cyber_todo_activities(due_date);
CREATE INDEX IF NOT EXISTS idx_cyber_todo_assigned_to ON cyber_todo_activities(assigned_to);
CREATE INDEX IF NOT EXISTS idx_cyber_todo_parent_id ON cyber_todo_activities(parent_id);

-- =============================================================================
-- SECTION 11: PERFORMANCE INDEXES
-- =============================================================================

-- Additional composite indexes for query performance
CREATE INDEX IF NOT EXISTS idx_tprm_vendor_user ON tprm_results(vendor_name, user_id);
CREATE INDEX IF NOT EXISTS idx_tprm_status_created ON tprm_results(status, created_at);
CREATE INDEX IF NOT EXISTS idx_sessions_activity ON sessions(last_activity);

-- =============================================================================
-- SECTION 12: DEFAULT DATA
-- =============================================================================

-- Default ACL groups
INSERT INTO acl_groups (group_name, display_name, description, is_active, is_system) VALUES
('administrator', 'Administrator', 'Full administrative access to all features', 1, 1),
('cyber_tprm', 'Cyber TPRM', 'Cyber Third Party Risk Management team - can manage vendor assessments and FAIR analysis', 1, 1),
('procurement', 'Procurement', 'Procurement team - can create and manage vendor onboarding requests', 1, 1),
('stakeholder', 'Stakeholder', 'Stakeholders - can view and update assigned vendor onboarding requests', 1, 1);

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
);

-- Assign admin to administrator group
INSERT INTO user_acl_groups (user_id, group_id, assigned_by)
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
('annual_review.read_assigned', 'View Assigned Annual Reviews', 'annual_review', 'review', 'read_assigned', 'View annual reviews for assigned vendors only');

-- Assign permissions to groups
-- Administrator gets ALL permissions
INSERT INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'administrator'), id, 1 FROM acl_permissions;

-- Cyber TPRM gets all permissions
INSERT INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'cyber_tprm'), id, 1 FROM acl_permissions;

-- Procurement gets create, read all, update own, assign stakeholder, view analysis, view assessments, view SRS, annual reviews
INSERT INTO acl_group_permissions (group_id, permission_id, granted_by)
SELECT (SELECT id FROM acl_groups WHERE group_name = 'procurement'), id, 1
FROM acl_permissions WHERE permission_code IN (
    'onboarding.create', 'onboarding.read', 'onboarding.update_own',
    'onboarding.assign_stakeholder', 'analysis.read', 'assessment.read', 'srs.view',
    'annual_review.read', 'annual_review.create'
);

-- Stakeholder gets create, read own, read assigned, update own, update assigned, annual review assigned
INSERT INTO acl_group_permissions (group_id, permission_id, granted_by)
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

-- Email/SMTP settings for annual review reminders
('email_enabled', '0', 0, 'Enable/disable email notifications (0=disabled, 1=enabled)'),
('smtp_host', 'localhost', 0, 'SMTP server hostname'),
('smtp_port', '25', 0, 'SMTP server port (25, 587, 465)'),
('smtp_username', '', 0, 'SMTP authentication username'),
('smtp_password', '', 1, 'SMTP authentication password (encrypted)'),
('smtp_encryption', 'none', 0, 'SMTP encryption type (none, tls, ssl)'),
('email_from_email', 'noreply@example.com', 0, 'From email address for system notifications'),
('email_from_name', 'TPRM System', 0, 'From name for system notifications');

-- General application settings
INSERT OR IGNORE INTO app_config (config_key, config_value, is_encrypted, description) VALUES
('app_url', '', 0, 'Public application URL for email links and cron jobs (e.g. https://demo.fairtprm.com)');
INSERT OR IGNORE INTO app_config (config_key, config_value, is_encrypted, description) VALUES
('company_name', '', 0, 'Company name displayed in emails and footers');
INSERT OR IGNORE INTO app_config (config_key, config_value, is_encrypted, description) VALUES
('log_retention_days', '90', 0, 'Number of days to retain audit log entries (0 = indefinite)');

-- Procurement notification settings
INSERT OR IGNORE INTO app_config (config_key, config_value, is_encrypted, description) VALUES
('procurement_notifications_enabled', '0', 0, 'Enable/disable procurement contract expiry email notifications');
INSERT OR IGNORE INTO app_config (config_key, config_value, is_encrypted, description) VALUES
('procurement_notification_recipients', 'all', 0, 'Procurement notification recipients: all, selected, or none');
INSERT OR IGNORE INTO app_config (config_key, config_value, is_encrypted, description) VALUES
('procurement_notification_warning_days', '30', 0, 'Days before contract expiry to start sending warnings');
INSERT OR IGNORE INTO app_config (config_key, config_value, is_encrypted, description) VALUES
('procurement_notification_selected_users', '[]', 0, 'JSON array of selected user IDs for procurement notifications');

-- =============================================================================
-- SECTION 14: ASSESSMENT TEMPLATES DATA
-- =============================================================================

INSERT INTO assessment_templates (name, slug, description, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('ISO 27001:2022 Assessment', 'iso-27001-2022', 'Comprehensive information security management system assessment based on ISO 27001:2022 standard.', 1, 'If your organization holds a valid ISO 27001:2022 certification, you may upload it here to skip the detailed assessment. Please ensure the certificate is current and includes your organization name.', 1),
('Tier 2 Vendor Assessment', 'tier-2-vendor', 'Streamlined security assessment for lower-risk vendor relationships.', 0, NULL, 1);

-- Note: Assessment sections and questions are seeded via includes/schema/vendor_assessments.sql
-- Run that file after this schema for complete assessment data

-- =============================================================================
-- SECTION 15: LEGACY SRS SCORING TABLES
-- =============================================================================
-- These tables are from the original standalone SRS scoring system that
-- predates the full TPRM application. They contain historical vendor
-- security score data collected via automated scanning.
-- =============================================================================

-- Legacy score history - raw security scores per vendor per scan source
CREATE TABLE IF NOT EXISTS score (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    Company VARCHAR(255) DEFAULT NULL,
    Average_Score DECIMAL(5,2) DEFAULT NULL,
    Letter_Grade CHAR(1) DEFAULT NULL,
    Date_Ran DATE DEFAULT NULL,
    Source VARCHAR(255) DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_score_company ON score(Company);
CREATE INDEX IF NOT EXISTS idx_score_date_ran ON score(Date_Ran);
CREATE INDEX IF NOT EXISTS idx_score_source ON score(Source);

-- Legacy vendor tier and scan configuration
CREATE TABLE IF NOT EXISTS tier (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain VARCHAR(64) NOT NULL,
    tier INTEGER NOT NULL,
    scanoption VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_tier_domain ON tier(domain);
CREATE INDEX IF NOT EXISTS idx_tier_tier ON tier(tier);

-- Legacy subdomain exclusions for scanning
CREATE TABLE IF NOT EXISTS domainexclusions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subdomain VARCHAR(64) NOT NULL
);

-- =============================================================================
-- SECTION 16: LEGACY SRS SCORING VIEWS
-- =============================================================================

-- View: Most recent scores per vendor per source (joined with tier data)
CREATE VIEW IF NOT EXISTS recent_scores AS
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
-- Uses JOIN approach for broad SQLite compatibility
CREATE VIEW IF NOT EXISTS view_mostrecent AS
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
CREATE VIEW IF NOT EXISTS view_next_scan AS
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
        WHEN t.tier = 1 THEN DATE(s.Date_Ran, '+1 day')
        WHEN t.tier = 2 THEN DATE(s.Date_Ran, '+90 days')
        WHEN t.tier = 3 THEN DATE(s.Date_Ran, '+365 days')
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

CREATE VIEW IF NOT EXISTS view_cve_search AS
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
            (vsf.subdomain IS NOT NULL AND vsr.risk_host LIKE '%' || vsf.subdomain || '%')
            OR (vsf.ip_address IS NOT NULL AND vsr.risk_host LIKE '%' || vsf.ip_address || '%')
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
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_name VARCHAR(500) NOT NULL,
    vendor_domain VARCHAR(255) DEFAULT NULL,
    relationship_manager VARCHAR(255) DEFAULT NULL,
    number_of_users INTEGER DEFAULT NULL,
    current_srs_score INTEGER DEFAULT NULL,
    last_srs_score_at DATETIME DEFAULT NULL,
    current_shodan_score INTEGER DEFAULT NULL,
    last_shodan_score_at DATETIME DEFAULT NULL,
    rescore_status VARCHAR(30) DEFAULT NULL,
    rescore_started_at DATETIME DEFAULT NULL,
    rescore_result TEXT DEFAULT NULL,
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'onboarded', 'dismissed')),
    onboarded_vendor_id INTEGER DEFAULT NULL,
    onboarded_at DATETIME DEFAULT NULL,
    onboarded_by INTEGER DEFAULT NULL,
    created_by INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_shadow_saas_status ON shadow_saas (status);
CREATE INDEX IF NOT EXISTS idx_shadow_saas_vendor_name ON shadow_saas (vendor_name);
CREATE INDEX IF NOT EXISTS idx_shadow_saas_vendor_domain ON shadow_saas (vendor_domain);
CREATE INDEX IF NOT EXISTS idx_shadow_saas_created_by ON shadow_saas (created_by);
CREATE INDEX IF NOT EXISTS idx_shadow_saas_rescore ON shadow_saas (rescore_status);

-- =============================================================================
-- POST-INSTALL SECURITY NOTES
-- =============================================================================
-- 1. CHANGE the default admin password immediately
-- 2. Set file permissions: config/ (700), config.php (600)
-- 3. Generate a new encryption key: openssl rand -base64 32
-- 4. Consider restricting database file permissions (chmod 600)
-- =============================================================================
