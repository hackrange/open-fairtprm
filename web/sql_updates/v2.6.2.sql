-- =============================================================================
-- FairTPRM v2.6.2 - Complete Database Schema and Seed Data
-- 
-- This file creates ALL required database objects and seeds default data.
-- Safe to run on new or existing databases (idempotent).
-- Compatible with MySQL 8.0+ and MariaDB 10.5+
--
-- IMPORTANT: This file will NOT overwrite:
--   - User-configurable settings (app_config values already set)
--   - Custom email templates (existing templates are preserved)
--   - User data, vendor data, assessment data, or any operational data
--   - Integration credentials (SMTP, Microsoft Graph, Shodan, UpGuard, etc.)
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- SECTION 1: Complete Database Schema (95 tables)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `acl_group_permissions` (
  `group_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  `granted_at` timestamp NULL DEFAULT current_timestamp(),
  `granted_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`group_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  KEY `granted_by` (`granted_by`),
  CONSTRAINT `acl_group_permissions_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `acl_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `acl_group_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `acl_permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `acl_group_permissions_ibfk_3` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `acl_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_name` varchar(100) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_name` (`group_name`),
  KEY `idx_group_name` (`group_name`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=178 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `acl_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `permission_code` varchar(100) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `module` varchar(50) DEFAULT NULL,
  `resource` varchar(50) DEFAULT NULL,
  `action` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_code` (`permission_code`),
  KEY `idx_module` (`module`),
  KEY `idx_resource_action` (`resource`,`action`),
  KEY `idx_permission_code` (`permission_code`)
) ENGINE=InnoDB AUTO_INCREMENT=438 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `api_request_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token_id` int(10) unsigned DEFAULT NULL,
  `method` varchar(10) NOT NULL,
  `endpoint` varchar(500) NOT NULL,
  `request_ip` varchar(45) NOT NULL,
  `request_params` text DEFAULT NULL COMMENT 'Sanitized request parameters (no secrets)',
  `response_code` smallint(5) unsigned NOT NULL,
  `response_time_ms` int(10) unsigned DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token_id`),
  KEY `idx_endpoint` (`endpoint`(191)),
  KEY `idx_ip` (`request_ip`),
  KEY `idx_created` (`created_at`),
  KEY `idx_response` (`response_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `api_token_ips` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token_id` int(10) unsigned NOT NULL,
  `ip_address` varchar(45) NOT NULL COMMENT 'Single IP address only - NO CIDR ranges',
  `label` varchar(255) DEFAULT NULL COMMENT 'Description of this IP',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_ip` (`token_id`,`ip_address`),
  KEY `idx_token` (`token_id`),
  KEY `idx_ip` (`ip_address`),
  CONSTRAINT `fk_tokenip_token` FOREIGN KEY (`token_id`) REFERENCES `api_tokens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` varchar(128) NOT NULL COMMENT 'SHA-512 hash of the bearer token',
  `token_prefix` varchar(8) NOT NULL COMMENT 'First 8 chars for identification (e.g., tprm_xxxx)',
  `name` varchar(255) NOT NULL COMMENT 'Human-readable token name',
  `description` text DEFAULT NULL,
  `scope` enum('read','read_write') DEFAULT 'read',
  `module_access` text DEFAULT NULL COMMENT 'JSON array: ["tprm", "grc", "both"]',
  `user_id` int(10) unsigned NOT NULL COMMENT 'User account this token belongs to',
  `is_active` tinyint(1) DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `last_used_ip` varchar(45) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `request_count` bigint(20) unsigned DEFAULT 0,
  `rate_limit_per_minute` int(10) unsigned DEFAULT 60,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_hash` (`token_hash`),
  KEY `idx_prefix` (`token_prefix`),
  KEY `idx_user` (`user_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `app_config` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL COMMENT 'Encrypted sensitive values',
  `is_encrypted` tinyint(1) DEFAULT 0,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`),
  KEY `idx_config_key` (`config_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2231 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `assessment_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assessment_id` int(11) NOT NULL,
  `file_type` enum('certificate','response','attachment') NOT NULL DEFAULT 'response',
  `question_id` int(11) DEFAULT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL,
  `encrypted_data` longblob NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `file_uuid` varchar(36) NOT NULL DEFAULT '' COMMENT 'Public-facing UUID for file references',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_uuid` (`file_uuid`),
  KEY `idx_assessment` (`assessment_id`),
  KEY `idx_type` (`file_type`),
  CONSTRAINT `assessment_files_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `vendor_assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `assessment_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` varchar(50) NOT NULL DEFAULT 'text',
  `options` text DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 1,
  `help_text` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `depends_on_question_id` int(11) DEFAULT NULL,
  `depends_on_value` varchar(255) DEFAULT NULL,
  `field_name` varchar(100) DEFAULT NULL COMMENT 'Maps to vendor_onboarding_requests column for onboarding templates',
  `triggers_assessment_template_id` int(11) DEFAULT NULL,
  `triggers_on_value` varchar(255) DEFAULT NULL,
  `include_in_minimal` tinyint(1) DEFAULT 0 COMMENT 'When the template is in minimal certificate mode, only questions flagged here are presented/required',
  `visible_roles` text DEFAULT NULL COMMENT 'JSON array of role slugs allowed to see this question/field (onboarding templates only); NULL/empty = visible to all',
  `editable_roles` text DEFAULT NULL COMMENT 'JSON array of role slugs allowed to edit this question/field (onboarding templates only); NULL/empty = no one (grant-only). Edit access implies visibility.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_section_sort` (`section_id`,`sort_order`),
  KEY `idx_depends_on` (`depends_on_question_id`),
  CONSTRAINT `assessment_questions_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `assessment_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2827 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `assessment_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `visible_roles` text DEFAULT NULL COMMENT 'JSON array of role slugs allowed to see this section (onboarding templates only); NULL/empty = visible to all',
  `editable_roles` text DEFAULT NULL COMMENT 'JSON array of role slugs allowed to edit this section''s questions (onboarding templates only); NULL/empty = no one (grant-only). Edit access implies visibility.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_template_sort` (`template_id`,`sort_order`),
  CONSTRAINT `assessment_sections_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `assessment_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=526 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `assessment_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `allow_certificate_upload` tinyint(1) DEFAULT 0,
  `certificate_upload_prompt` text DEFAULT NULL,
  `certificate_upload_mode` varchar(20) DEFAULT 'none' COMMENT 'none = full assessment; skip = cert upload completes; minimal = cert upload + selected questions',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `category` varchar(50) DEFAULT 'vendor_assessment',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `assessment_workflow_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `source_template_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `condition_operator` varchar(20) DEFAULT 'equals',
  `condition_value` varchar(255) NOT NULL,
  `target_template_id` int(11) NOT NULL,
  `assign_to` varchar(20) DEFAULT 'vendor',
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `source_template_id` (`source_template_id`),
  KEY `question_id` (`question_id`),
  KEY `target_template_id` (`target_template_id`),
  CONSTRAINT `assessment_workflow_rules_ibfk_1` FOREIGN KEY (`source_template_id`) REFERENCES `assessment_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assessment_workflow_rules_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `assessment_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assessment_workflow_rules_ibfk_3` FOREIGN KEY (`target_template_id`) REFERENCES `assessment_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int(10) unsigned DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6300 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cron_execution_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `job_name` varchar(100) NOT NULL COMMENT 'e.g., srs-rescore, assessment-reminder',
  `started_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `status` enum('running','completed','failed') DEFAULT 'running',
  `processed_count` int(11) DEFAULT 0,
  `success_count` int(11) DEFAULT 0,
  `failed_count` int(11) DEFAULT 0,
  `error_messages` text DEFAULT NULL COMMENT 'JSON array of error messages',
  `execution_time_seconds` decimal(10,3) DEFAULT NULL COMMENT 'Total execution time',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_job_name` (`job_name`),
  KEY `idx_started_at` (`started_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=891 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `ai_job_queue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `job_type` varchar(50) NOT NULL COMMENT 'fair_analysis, contract_pricing, assessment_autofill',
  `status` enum('pending','processing','completed','failed') DEFAULT 'pending',
  `user_id` int(10) unsigned NOT NULL,
  `request_payload` longtext NOT NULL COMMENT 'JSON: messages, options, and context',
  `result_payload` longtext DEFAULT NULL COMMENT 'JSON: full result for frontend',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aiq_status` (`status`),
  KEY `idx_aiq_user_id` (`user_id`),
  KEY `idx_aiq_created_at` (`created_at`),
  KEY `idx_aiq_type_status` (`job_type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cyber_todo_activities` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `todo_type` enum('cert_expiry','srs_rescore','score_drop','annual_review','not_approved','not_tiered','custom','user_import','vendor_import','contract_expiry') NOT NULL,
  `reference_type` varchar(50) NOT NULL,
  `reference_id` int(10) unsigned NOT NULL,
  `activity_type` enum('note','action','status_change','reminder','import','revert') DEFAULT 'note',
  `title` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `status` enum('open','in_progress','closed','deferred','reverted') DEFAULT 'open',
  `due_date` date DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `assigned_to` int(10) unsigned DEFAULT NULL COMMENT 'User this activity is assigned to',
  `parent_id` int(10) unsigned DEFAULT NULL COMMENT 'Parent case ID for threaded responses',
  PRIMARY KEY (`id`),
  KEY `idx_todo_type` (`todo_type`),
  KEY `idx_reference` (`reference_type`,`reference_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_parent_id` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=718 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cyber_todo_snoozes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `todo_type` varchar(50) NOT NULL,
  `reference_type` varchar(50) NOT NULL,
  `reference_id` int(10) unsigned NOT NULL,
  `snoozed_until` datetime NOT NULL,
  `snoozed_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_unique_snooze` (`todo_type`,`reference_type`,`reference_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_scheduled_actions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL COMMENT 'vendor_onboarding_requests.id this action belongs to',
  `action` varchar(50) NOT NULL COMMENT 'contact_vendor|contact_stakeholder|send_assessment|force_annual_review',
  `assessment_template_id` int(11) DEFAULT NULL COMMENT 'Vendor Assessment template to send (send_assessment only)',
  `scheduled_date` date NOT NULL COMMENT 'Date the action becomes due',
  `description` text DEFAULT NULL COMMENT 'Carried into the generated Cyber To-Do',
  `assignees` text DEFAULT NULL COMMENT 'JSON array of cyber_tprm user ids to assign the generated To-Do to',
  `notify_assignees` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Email each assignee when the action fires',
  `notify_emails` text DEFAULT NULL COMMENT 'Editable comma-separated notification addresses; overrides assignee account emails when set',
  `status` enum('pending','in_progress','problem','completed','cancelled') NOT NULL DEFAULT 'pending' COMMENT 'User-editable workflow status; firing is tracked by executed_at',
  `result_type` varchar(50) DEFAULT NULL COMMENT 'Table the result row lives in (vendor_assessments, cyber_todo_activities, ...)',
  `result_id` int(10) unsigned DEFAULT NULL COMMENT 'Id of the row created when the action fired',
  `error` text DEFAULT NULL COMMENT 'Failure detail when status=failed',
  `executed_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vsa_request` (`request_id`),
  KEY `idx_vsa_due` (`status`,`scheduled_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `domainexclusions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain` varchar(64) NOT NULL COMMENT 'Subdomain to exclude from scanning',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `email_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `template_category` varchar(30) NOT NULL,
  `template_key` varchar(100) NOT NULL,
  `assessment_template_id` int(11) DEFAULT NULL,
  `display_name` varchar(255) NOT NULL,
  `email_subject` varchar(500) NOT NULL,
  `email_body_html` mediumtext NOT NULL,
  `email_body_text` text NOT NULL,
  `available_variables` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_template` (`template_category`,`template_key`,`assessment_template_id`),
  KEY `idx_category` (`template_category`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `field_references` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `field_name` varchar(100) NOT NULL,
  `column_type` varchar(100) NOT NULL DEFAULT 'VARCHAR(500)',
  `section` varchar(100) NOT NULL DEFAULT '',
  `category` varchar(50) NOT NULL DEFAULT 'all',
  `description` text DEFAULT NULL,
  `is_builtin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_field_name` (`field_name`)
) ENGINE=InnoDB AUTO_INCREMENT=210 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `grc_assessment_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_id` int(10) unsigned NOT NULL COMMENT 'FK to assessment session',
  `question_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = whole assessment or domain-level',
  `domain_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = specific question, set = whole domain',
  `assigned_to` int(10) unsigned NOT NULL COMMENT 'User ID of the assignee',
  `assigned_by` int(10) unsigned NOT NULL COMMENT 'User ID who made the assignment',
  `role` enum('assessor','validator','reviewer') DEFAULT 'assessor',
  `status` enum('assigned','in_progress','completed','declined') DEFAULT 'assigned',
  `due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_assessment` (`assessment_id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_assigned_by` (`assigned_by`),
  KEY `idx_status` (`status`),
  KEY `idx_role` (`role`),
  KEY `idx_due` (`due_date`),
  CONSTRAINT `fk_aa_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `grc_assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_assessment_domain_scores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_id` int(10) unsigned NOT NULL COMMENT 'FK to assessment session',
  `domain_id` int(10) unsigned NOT NULL COMMENT 'FK to security domain',
  `average_score` decimal(3,2) DEFAULT NULL COMMENT 'Average FairScore 1.00-4.00',
  `questions_total` int(10) unsigned DEFAULT 0,
  `questions_answered` int(10) unsigned DEFAULT 0,
  `conforming_count` int(10) unsigned DEFAULT 0,
  `partial_count` int(10) unsigned DEFAULT 0,
  `non_conforming_count` int(10) unsigned DEFAULT 0,
  `not_applicable_count` int(10) unsigned DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assessment_domain` (`assessment_id`,`domain_id`),
  KEY `idx_assessment` (`assessment_id`),
  KEY `idx_domain` (`domain_id`),
  CONSTRAINT `fk_ads_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `grc_assessments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ads_domain` FOREIGN KEY (`domain_id`) REFERENCES `grc_security_domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_assessment_evidence` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_id` int(10) unsigned NOT NULL COMMENT 'FK to grc_audit_requirement_assessments.id',
  `evidence_id` int(10) unsigned NOT NULL COMMENT 'FK to grc_evidence.id',
  `linked_by` int(10) unsigned DEFAULT NULL COMMENT 'User who linked the evidence',
  `linked_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assessment_evidence` (`assessment_id`,`evidence_id`),
  KEY `idx_evidence` (`evidence_id`),
  CONSTRAINT `fk_ae_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `grc_audit_requirement_assessments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ae_evidence` FOREIGN KEY (`evidence_id`) REFERENCES `grc_evidence` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Links evidence to per-requirement audit assessments';
CREATE TABLE IF NOT EXISTS `grc_assessment_response_evidence` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `response_id` int(10) unsigned NOT NULL COMMENT 'FK to assessment response',
  `evidence_id` int(10) unsigned NOT NULL COMMENT 'FK to grc_evidence',
  `linked_by` int(10) unsigned DEFAULT NULL COMMENT 'User who linked the evidence',
  `linked_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_response_evidence` (`response_id`,`evidence_id`),
  KEY `idx_response` (`response_id`),
  KEY `idx_evidence` (`evidence_id`),
  CONSTRAINT `fk_are_evidence` FOREIGN KEY (`evidence_id`) REFERENCES `grc_evidence` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_are_response` FOREIGN KEY (`response_id`) REFERENCES `grc_assessment_responses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_assessment_responses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_id` int(10) unsigned NOT NULL COMMENT 'FK to assessment session',
  `question_id` int(10) unsigned NOT NULL COMMENT 'FK to unified question',
  `maturity_rating` tinyint(3) unsigned DEFAULT NULL COMMENT '1-4, NULL = not yet answered',
  `conformity_status` enum('conforming','partial','non_conforming','not_applicable','not_assessed') DEFAULT 'not_assessed',
  `notes` text DEFAULT NULL,
  `assessor_user_id` int(10) unsigned DEFAULT NULL COMMENT 'Who answered this question',
  `assessed_at` datetime DEFAULT NULL,
  `validation_status` enum('pending','validated','rejected','needs_review') DEFAULT 'pending',
  `validated_by` int(10) unsigned DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `validation_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assessment_question` (`assessment_id`,`question_id`),
  KEY `idx_assessment` (`assessment_id`),
  KEY `idx_question` (`question_id`),
  KEY `idx_conformity` (`conformity_status`),
  KEY `idx_validation` (`validation_status`),
  KEY `idx_assessor` (`assessor_user_id`),
  CONSTRAINT `fk_ar_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `grc_assessments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ar_question` FOREIGN KEY (`question_id`) REFERENCES `grc_unified_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=178 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_assessment_tasks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `task_ref` varchar(50) NOT NULL COMMENT 'Reference code (e.g., TSK-001)',
  `assessment_id` int(10) unsigned NOT NULL COMMENT 'FK to assessment session',
  `question_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to unified question (nullable)',
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `task_type` enum('evidence_request','remediation','review','documentation','implementation') DEFAULT 'evidence_request',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `assigned_to` int(10) unsigned DEFAULT NULL COMMENT 'User ID of the assignee',
  `assigned_by` int(10) unsigned NOT NULL COMMENT 'User ID who created the task',
  `status` enum('open','in_progress','completed','cancelled','blocked') DEFAULT 'open',
  `due_date` date DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_ref` (`task_ref`),
  KEY `idx_ref` (`task_ref`),
  KEY `idx_assessment` (`assessment_id`),
  KEY `idx_question` (`question_id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_assigned_by` (`assigned_by`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_due` (`due_date`),
  CONSTRAINT `fk_at_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `grc_assessments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_at_question` FOREIGN KEY (`question_id`) REFERENCES `grc_unified_questions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_assessments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_ref` varchar(50) NOT NULL COMMENT 'Reference code (e.g., FA-001)',
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `assessment_type` enum('initial','periodic','targeted','pre_audit','certification') DEFAULT 'initial',
  `scope` text DEFAULT NULL COMMENT 'Scope description',
  `scope_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to grc_scopes for scope selection',
  `status` enum('draft','in_progress','under_review','completed','archived') DEFAULT 'draft',
  `lead_auditor_id` int(10) unsigned DEFAULT NULL COMMENT 'User who leads the assessment',
  `planned_start` date DEFAULT NULL,
  `planned_end` date DEFAULT NULL,
  `actual_start` date DEFAULT NULL,
  `actual_end` date DEFAULT NULL,
  `overall_fairscore` decimal(3,2) DEFAULT NULL COMMENT 'Overall FairScore 1.00-4.00',
  `overall_compliance_pct` decimal(5,2) DEFAULT NULL COMMENT 'Overall compliance percentage',
  `report_encrypted_data` longblob DEFAULT NULL COMMENT 'Encrypted PDF report',
  `report_file_name` varchar(255) DEFAULT NULL,
  `report_file_mime` varchar(100) DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `assessment_ref` (`assessment_ref`),
  KEY `idx_ref` (`assessment_ref`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`assessment_type`),
  KEY `idx_lead` (`lead_auditor_id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_dates` (`planned_start`,`planned_end`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_audit_artifacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `audit_id` int(10) unsigned NOT NULL,
  `artifact_type` enum('certification_draft','evidence_package','control_matrix','gap_analysis','soc2_report','iso_soa','custom') DEFAULT 'evidence_package',
  `title` varchar(500) NOT NULL,
  `file_format` enum('pdf','rtf','xlsx','csv','json') DEFAULT 'pdf',
  `file_name` varchar(255) NOT NULL,
  `file_mime` varchar(100) NOT NULL,
  `encrypted_data` longblob NOT NULL COMMENT 'AES-256-CBC encrypted file content',
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `generated_by` int(10) unsigned DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit` (`audit_id`),
  KEY `idx_type` (`artifact_type`),
  CONSTRAINT `fk_artifact_audit` FOREIGN KEY (`audit_id`) REFERENCES `grc_audits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_audit_evidence_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `audit_id` int(10) unsigned NOT NULL,
  `requirement_id` int(10) unsigned DEFAULT NULL,
  `control_id` int(10) unsigned DEFAULT NULL,
  `description` text NOT NULL COMMENT 'What evidence is needed',
  `status` enum('requested','in_progress','provided','accepted','rejected') DEFAULT 'requested',
  `requested_by` int(10) unsigned DEFAULT NULL,
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `evidence_ids` text DEFAULT NULL COMMENT 'JSON array of grc_evidence.id values provided',
  `due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit` (`audit_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned` (`assigned_to`),
  CONSTRAINT `fk_evreq_audit` FOREIGN KEY (`audit_id`) REFERENCES `grc_audits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_audit_findings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `audit_id` int(10) unsigned NOT NULL,
  `finding_ref` varchar(50) NOT NULL COMMENT 'Finding reference within audit',
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `severity` enum('informational','low','medium','high','critical') DEFAULT 'medium',
  `finding_type` enum('nonconformity','observation','opportunity','strength') DEFAULT 'nonconformity',
  `requirement_id` int(10) unsigned DEFAULT NULL COMMENT 'Related framework requirement',
  `control_id` int(10) unsigned DEFAULT NULL COMMENT 'Related internal control',
  `status` enum('open','in_remediation','remediated','verified_closed','risk_accepted') DEFAULT 'open',
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `remediation_plan` text DEFAULT NULL,
  `remediation_evidence` text DEFAULT NULL COMMENT 'JSON: evidence IDs proving remediation',
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_finding_req` (`requirement_id`),
  KEY `fk_finding_control` (`control_id`),
  KEY `idx_audit` (`audit_id`),
  KEY `idx_severity` (`severity`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned` (`assigned_to`),
  KEY `idx_due` (`due_date`),
  CONSTRAINT `fk_finding_audit` FOREIGN KEY (`audit_id`) REFERENCES `grc_audits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_finding_control` FOREIGN KEY (`control_id`) REFERENCES `grc_internal_controls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_finding_req` FOREIGN KEY (`requirement_id`) REFERENCES `grc_framework_requirements` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_audit_requirement_assessments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `audit_id` int(10) unsigned NOT NULL,
  `requirement_id` int(10) unsigned NOT NULL,
  `assessment_status` enum('not_assessed','conforming','non_conforming','partially_conforming','not_applicable') DEFAULT 'not_assessed',
  `assessor_user_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'Auditor notes for this requirement',
  `assessed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_audit_requirement` (`audit_id`,`requirement_id`),
  KEY `idx_ara_audit` (`audit_id`),
  KEY `idx_ara_requirement` (`requirement_id`),
  KEY `idx_ara_status` (`assessment_status`),
  CONSTRAINT `fk_ara_audit` FOREIGN KEY (`audit_id`) REFERENCES `grc_audits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ara_requirement` FOREIGN KEY (`requirement_id`) REFERENCES `grc_framework_requirements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_audits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `audit_ref` varchar(50) NOT NULL COMMENT 'Audit reference (e.g., AUD-2026-001)',
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `audit_type` enum('internal','external','certification','surveillance','readiness') DEFAULT 'internal',
  `framework_id` int(10) unsigned DEFAULT NULL COMMENT 'Primary framework being audited',
  `scope` text DEFAULT NULL COMMENT 'Audit scope description',
  `scope_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to grc_scopes for scope filtering',
  `status` enum('planning','fieldwork','reporting','remediation','closed') DEFAULT 'planning',
  `lead_auditor` varchar(255) DEFAULT NULL,
  `lead_auditor_user_id` int(10) unsigned DEFAULT NULL,
  `audit_firm` varchar(255) DEFAULT NULL COMMENT 'External audit firm name',
  `planned_start` date DEFAULT NULL,
  `planned_end` date DEFAULT NULL,
  `actual_start` date DEFAULT NULL,
  `actual_end` date DEFAULT NULL,
  `findings_count` int(10) unsigned DEFAULT 0,
  `critical_findings` int(10) unsigned DEFAULT 0,
  `certification_body` varchar(255) DEFAULT NULL COMMENT 'Certifying organization',
  `certification_number` varchar(255) DEFAULT NULL,
  `certification_valid_from` date DEFAULT NULL,
  `certification_valid_until` date DEFAULT NULL,
  `report_encrypted_data` longblob DEFAULT NULL COMMENT 'Encrypted audit report PDF',
  `report_file_name` varchar(255) DEFAULT NULL,
  `report_file_mime` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `audit_ref` (`audit_ref`),
  KEY `idx_ref` (`audit_ref`),
  KEY `idx_type` (`audit_type`),
  KEY `idx_status` (`status`),
  KEY `idx_framework` (`framework_id`),
  KEY `idx_dates` (`planned_start`,`planned_end`),
  KEY `idx_scope_id` (`scope_id`),
  CONSTRAINT `fk_audit_framework` FOREIGN KEY (`framework_id`) REFERENCES `grc_frameworks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_autosave_drafts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `form_type` varchar(100) NOT NULL COMMENT 'e.g., control_edit, policy_edit, evidence_upload',
  `form_id` varchar(100) DEFAULT NULL COMMENT 'ID of the record being edited (NULL for new)',
  `draft_data` longtext NOT NULL COMMENT 'JSON-serialized form data',
  `last_saved_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_form` (`user_id`,`form_type`,`form_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_form` (`form_type`,`form_id`),
  KEY `idx_saved` (`last_saved_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_continuous_monitors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_ref` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `integration_id` int(10) unsigned DEFAULT NULL COMMENT 'Link to cloud/service integration',
  `collector_class` varchar(255) NOT NULL COMMENT 'PHP class implementing EvidenceCollectorInterface',
  `collector_config` text DEFAULT NULL COMMENT 'JSON: collector-specific configuration',
  `check_type` varchar(100) NOT NULL COMMENT 'e.g., aws_iam_mfa, azure_encryption, github_branch_protection',
  `frequency` enum('hourly','daily','weekly','monthly') DEFAULT 'daily',
  `is_enabled` tinyint(1) DEFAULT 1,
  `last_run_at` datetime DEFAULT NULL,
  `last_result` enum('pass','fail','error','warning','not_run') DEFAULT 'not_run',
  `last_result_detail` text DEFAULT NULL COMMENT 'JSON: detailed check results',
  `next_run_at` datetime DEFAULT NULL,
  `failure_count` int(10) unsigned DEFAULT 0 COMMENT 'Consecutive failures',
  `alert_on_failure` tinyint(1) DEFAULT 1,
  `control_ids` text DEFAULT NULL COMMENT 'JSON array of control IDs this monitor validates',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `monitor_ref` (`monitor_ref`),
  KEY `idx_ref` (`monitor_ref`),
  KEY `idx_enabled` (`is_enabled`),
  KEY `idx_next_run` (`next_run_at`),
  KEY `idx_last_result` (`last_result`),
  KEY `idx_check_type` (`check_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_control_implementations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `control_id` int(10) unsigned NOT NULL,
  `framework_id` int(10) unsigned NOT NULL,
  `maturity_level` tinyint(3) unsigned DEFAULT NULL COMMENT 'Current maturity level achieved',
  `target_maturity_level` tinyint(3) unsigned DEFAULT NULL COMMENT 'Target maturity level',
  `implementation_status` enum('not_started','planned','in_progress','implemented','not_applicable') DEFAULT 'not_started',
  `implementation_date` datetime DEFAULT NULL,
  `implementation_notes` text DEFAULT NULL,
  `assessment_date` datetime DEFAULT NULL COMMENT 'Last assessment date',
  `assessor` varchar(255) DEFAULT NULL COMMENT 'Who assessed this',
  `evidence_validity_days` int(10) unsigned DEFAULT 365 COMMENT 'How long evidence stays valid',
  `sprs_score` int(11) DEFAULT NULL COMMENT 'SPRS score contribution for CMMC',
  `poam_required` tinyint(1) DEFAULT 0 COMMENT 'Plan of Action required?',
  `poam_completion_date` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_control_framework` (`control_id`,`framework_id`),
  KEY `idx_control` (`control_id`),
  KEY `idx_framework` (`framework_id`),
  KEY `idx_status` (`implementation_status`),
  KEY `idx_maturity` (`maturity_level`),
  CONSTRAINT `fk_impl_control` FOREIGN KEY (`control_id`) REFERENCES `grc_internal_controls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_impl_framework` FOREIGN KEY (`framework_id`) REFERENCES `grc_frameworks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_control_requirement_map` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `control_id` int(10) unsigned NOT NULL,
  `requirement_id` int(10) unsigned NOT NULL,
  `mapping_notes` text DEFAULT NULL COMMENT 'How this control satisfies the requirement',
  `coverage` enum('full','partial','planned') DEFAULT 'full',
  `mapped_by` int(10) unsigned DEFAULT NULL,
  `mapped_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_control_requirement` (`control_id`,`requirement_id`),
  KEY `idx_control` (`control_id`),
  KEY `idx_requirement` (`requirement_id`),
  CONSTRAINT `fk_map_control` FOREIGN KEY (`control_id`) REFERENCES `grc_internal_controls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_map_requirement` FOREIGN KEY (`requirement_id`) REFERENCES `grc_framework_requirements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_control_tests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `control_id` int(10) unsigned NOT NULL,
  `test_date` datetime NOT NULL DEFAULT current_timestamp(),
  `tester_user_id` int(10) unsigned DEFAULT NULL,
  `test_type` enum('design','operating_effectiveness','walkthrough','inquiry','observation','inspection','reperformance') DEFAULT 'operating_effectiveness',
  `result` enum('effective','partially_effective','ineffective','not_tested') NOT NULL,
  `sample_size` int(10) unsigned DEFAULT NULL,
  `exceptions_found` int(10) unsigned DEFAULT 0,
  `description` text DEFAULT NULL,
  `evidence_ids` text DEFAULT NULL COMMENT 'JSON array of evidence IDs',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_control` (`control_id`),
  KEY `idx_date` (`test_date`),
  KEY `idx_result` (`result`),
  CONSTRAINT `fk_ctest_control` FOREIGN KEY (`control_id`) REFERENCES `grc_internal_controls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_crosswalk_cache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source_framework_id` int(10) unsigned NOT NULL,
  `source_requirement_id` int(10) unsigned NOT NULL,
  `target_framework_id` int(10) unsigned NOT NULL,
  `target_requirement_id` int(10) unsigned NOT NULL,
  `relationship` enum('equivalent','subset','superset','related','partial') DEFAULT 'related',
  `confidence` decimal(3,2) DEFAULT 1.00 COMMENT '0.00-1.00 mapping confidence',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_crosswalk` (`source_requirement_id`,`target_requirement_id`),
  KEY `fk_xwalk_tgt_req` (`target_requirement_id`),
  KEY `idx_source` (`source_framework_id`,`source_requirement_id`),
  KEY `idx_target` (`target_framework_id`,`target_requirement_id`),
  CONSTRAINT `fk_xwalk_src_fw` FOREIGN KEY (`source_framework_id`) REFERENCES `grc_frameworks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_xwalk_src_req` FOREIGN KEY (`source_requirement_id`) REFERENCES `grc_framework_requirements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_xwalk_tgt_fw` FOREIGN KEY (`target_framework_id`) REFERENCES `grc_frameworks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_xwalk_tgt_req` FOREIGN KEY (`target_requirement_id`) REFERENCES `grc_framework_requirements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_dashboard_snapshots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `framework_id` int(10) unsigned NOT NULL,
  `snapshot_date` date NOT NULL,
  `total_requirements` int(10) unsigned DEFAULT 0,
  `implemented_count` int(10) unsigned DEFAULT 0,
  `partial_count` int(10) unsigned DEFAULT 0,
  `planned_count` int(10) unsigned DEFAULT 0,
  `not_applicable_count` int(10) unsigned DEFAULT 0,
  `compliance_percentage` decimal(5,2) DEFAULT 0.00,
  `evidence_current` int(10) unsigned DEFAULT 0,
  `evidence_expired` int(10) unsigned DEFAULT 0,
  `open_findings` int(10) unsigned DEFAULT 0,
  `snapshot_data` text DEFAULT NULL COMMENT 'JSON: full snapshot details',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_framework_date` (`framework_id`,`snapshot_date`),
  KEY `idx_framework` (`framework_id`),
  KEY `idx_date` (`snapshot_date`),
  CONSTRAINT `fk_snap_framework` FOREIGN KEY (`framework_id`) REFERENCES `grc_frameworks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_evidence` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `evidence_ref` varchar(50) NOT NULL COMMENT 'Reference code (e.g., EV-001)',
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `evidence_type` enum('screenshot','api_log','document','configuration','report','certificate','policy','automated','manual_upload') DEFAULT 'manual_upload',
  `collection_method` enum('manual','automated','hybrid') DEFAULT 'manual',
  `file_name` varchar(255) DEFAULT NULL,
  `file_mime` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `encrypted_data` longblob DEFAULT NULL COMMENT 'AES-256-CBC encrypted file content',
  `screenshot_data` longblob DEFAULT NULL COMMENT 'Screenshot stored as encrypted BLOB',
  `screenshot_mime` varchar(100) DEFAULT NULL,
  `external_url` varchar(1000) DEFAULT NULL COMMENT 'Link to external evidence source',
  `api_response_data` text DEFAULT NULL COMMENT 'JSON: automated collection response',
  `monitor_id` int(10) unsigned DEFAULT NULL COMMENT 'Link to continuous monitor that collected this',
  `collected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `valid_from` datetime DEFAULT NULL,
  `valid_until` datetime DEFAULT NULL COMMENT 'Evidence expiry for CMMC validity tracking',
  `status` enum('current','expired','superseded','draft') DEFAULT 'current',
  `collected_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `evidence_ref` (`evidence_ref`),
  KEY `idx_ref` (`evidence_ref`),
  KEY `idx_type` (`evidence_type`),
  KEY `idx_status` (`status`),
  KEY `idx_valid_until` (`valid_until`),
  KEY `idx_monitor` (`monitor_id`),
  KEY `idx_collected_by` (`collected_by`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_evidence_control_map` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `evidence_id` int(10) unsigned NOT NULL,
  `control_id` int(10) unsigned NOT NULL,
  `notes` text DEFAULT NULL,
  `linked_by` int(10) unsigned DEFAULT NULL,
  `linked_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_evidence_control` (`evidence_id`,`control_id`),
  KEY `idx_evidence` (`evidence_id`),
  KEY `idx_control` (`control_id`),
  CONSTRAINT `fk_evmap_control` FOREIGN KEY (`control_id`) REFERENCES `grc_internal_controls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_evmap_evidence` FOREIGN KEY (`evidence_id`) REFERENCES `grc_evidence` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_evidence_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `control_id` int(10) unsigned DEFAULT NULL,
  `requirement_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `requested_by` int(10) unsigned NOT NULL,
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `status` enum('pending','in_progress','provided','accepted','rejected') DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `evidence_id` int(10) unsigned DEFAULT NULL COMMENT 'Linked evidence once provided',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_control` (`control_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned` (`assigned_to`),
  KEY `idx_due` (`due_date`),
  CONSTRAINT `fk_evreq_control` FOREIGN KEY (`control_id`) REFERENCES `grc_internal_controls` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_framework_requirements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `framework_id` int(10) unsigned NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL COMMENT 'Hierarchical nesting (sections > subsections)',
  `requirement_ref` varchar(100) NOT NULL COMMENT 'Official reference (e.g., CC6.1, A.8.1.1, 3.1.1)',
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `guidance` text DEFAULT NULL COMMENT 'Implementation guidance notes',
  `maturity_level` tinyint(3) unsigned DEFAULT NULL COMMENT 'Required CMMC maturity level',
  `is_required` tinyint(1) DEFAULT 1 COMMENT '1=mandatory, 0=optional/advisory',
  `sort_order` int(10) unsigned DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_framework_ref` (`framework_id`,`requirement_ref`),
  KEY `idx_framework` (`framework_id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_ref` (`requirement_ref`),
  CONSTRAINT `fk_req_framework` FOREIGN KEY (`framework_id`) REFERENCES `grc_frameworks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_req_parent` FOREIGN KEY (`parent_id`) REFERENCES `grc_framework_requirements` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1098 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_frameworks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL COMMENT 'Short code: SOC2, ISO27001, CMMC, PCI, SOX, CSF',
  `name` varchar(255) NOT NULL COMMENT 'Full framework name',
  `version` varchar(50) DEFAULT NULL COMMENT 'Framework version (e.g., 2022, Rev 5)',
  `description` text DEFAULT NULL,
  `scope_id` int(10) unsigned DEFAULT NULL,
  `framework_url` varchar(500) DEFAULT NULL COMMENT 'Official framework URL',
  `has_maturity_levels` tinyint(1) DEFAULT 0 COMMENT 'CMMC-style maturity tracking',
  `max_maturity_level` tinyint(3) unsigned DEFAULT NULL COMMENT 'Max maturity level (e.g., 5 for CMMC)',
  `is_active` tinyint(1) DEFAULT 1,
  `compliance_year` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Compliance year for filtering historical data',
  `sort_order` int(10) unsigned DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `generated_from` varchar(50) DEFAULT NULL COMMENT 'Catalog code used to generate this framework',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_code` (`code`),
  KEY `idx_active` (`is_active`),
  KEY `idx_year` (`compliance_year`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_integrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `integration_type` enum('aws','azure','gcp','oci','ibm_cloud','github','gitlab','okta','crowdstrike','custom') NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `credentials_encrypted` text DEFAULT NULL COMMENT 'AES-256-CBC encrypted JSON credentials',
  `endpoint_url` varchar(1000) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_connection_test` datetime DEFAULT NULL,
  `connection_status` enum('untested','connected','failed','expired') DEFAULT 'untested',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_type` (`integration_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_internal_controls` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `control_ref` varchar(50) NOT NULL COMMENT 'Internal reference (e.g., IC-001)',
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `scope_id` int(10) unsigned DEFAULT NULL,
  `control_type` enum('preventive','detective','corrective','directive') DEFAULT 'preventive',
  `control_category` enum('technical','administrative','physical') DEFAULT 'technical',
  `implementation_status` enum('planned','in_progress','implemented','not_applicable') DEFAULT 'planned',
  `effectiveness` enum('not_tested','ineffective','partially_effective','effective') DEFAULT 'not_tested',
  `owner_user_id` int(10) unsigned DEFAULT NULL COMMENT 'Control owner',
  `frequency` enum('continuous','daily','weekly','monthly','quarterly','annually','ad_hoc') DEFAULT 'ad_hoc',
  `last_tested_at` datetime DEFAULT NULL,
  `next_test_due` datetime DEFAULT NULL,
  `risk_level` enum('low','medium','high','critical') DEFAULT 'medium',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `control_ref` (`control_ref`),
  KEY `idx_ref` (`control_ref`),
  KEY `idx_status` (`implementation_status`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_next_test` (`next_test_due`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_maturity_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_id` int(10) unsigned NOT NULL COMMENT 'FK to assessment session',
  `snapshot_date` date NOT NULL,
  `overall_score` decimal(3,2) DEFAULT NULL COMMENT 'Overall FairScore at snapshot time',
  `domain_scores` text DEFAULT NULL COMMENT 'JSON: {domain_code: score, ...}',
  `framework_scores` text DEFAULT NULL COMMENT 'JSON: {framework_code: compliance_pct, ...}',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_assessment` (`assessment_id`),
  KEY `idx_snapshot_date` (`snapshot_date`),
  CONSTRAINT `fk_mh_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `grc_assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_maturity_targets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int(10) unsigned NOT NULL COMMENT 'FK to security domain',
  `target_score` decimal(3,2) NOT NULL COMMENT 'Target FairScore 1.00-4.00',
  `current_score` decimal(3,2) DEFAULT NULL COMMENT 'Current FairScore',
  `target_date` date DEFAULT NULL COMMENT 'Target date to achieve score',
  `action_plan` text DEFAULT NULL COMMENT 'What to do to improve maturity',
  `status` enum('not_started','in_progress','achieved','at_risk') DEFAULT 'not_started',
  `assigned_to` int(10) unsigned DEFAULT NULL COMMENT 'User responsible for improvement',
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_domain` (`domain_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned` (`assigned_to`),
  KEY `idx_target_date` (`target_date`),
  CONSTRAINT `fk_mt_domain` FOREIGN KEY (`domain_id`) REFERENCES `grc_security_domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_monitor_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_id` int(10) unsigned NOT NULL,
  `run_at` datetime NOT NULL DEFAULT current_timestamp(),
  `result` enum('pass','fail','error','warning') NOT NULL,
  `result_detail` text DEFAULT NULL COMMENT 'JSON: full check output',
  `evidence_id` int(10) unsigned DEFAULT NULL COMMENT 'Auto-generated evidence record',
  `duration_ms` int(10) unsigned DEFAULT NULL COMMENT 'Execution time in milliseconds',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_monitor` (`monitor_id`),
  KEY `idx_run_at` (`run_at`),
  KEY `idx_result` (`result`),
  CONSTRAINT `fk_mresult_monitor` FOREIGN KEY (`monitor_id`) REFERENCES `grc_continuous_monitors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_policies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `policy_ref` varchar(50) NOT NULL COMMENT 'Reference code (e.g., POL-001)',
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `scope_id` int(10) unsigned DEFAULT NULL,
  `category` enum('security','privacy','compliance','operational','hr','it','business_continuity','incident_response','access_control','other') DEFAULT 'security',
  `status` enum('draft','review','approved','published','retired') DEFAULT 'draft',
  `current_version` int(10) unsigned DEFAULT 1,
  `owner_user_id` int(10) unsigned DEFAULT NULL COMMENT 'Policy owner',
  `approver_user_id` int(10) unsigned DEFAULT NULL COMMENT 'Who approved current version',
  `approved_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `review_frequency_months` int(10) unsigned DEFAULT 12 COMMENT 'How often to review (months)',
  `next_review_date` date DEFAULT NULL,
  `last_reviewed_at` datetime DEFAULT NULL,
  `requires_acknowledgment` tinyint(1) DEFAULT 1 COMMENT 'Employees must read & accept',
  `acknowledgment_deadline_days` int(10) unsigned DEFAULT 30 COMMENT 'Days to acknowledge after publish',
  `related_frameworks` text DEFAULT NULL COMMENT 'JSON array of framework IDs this policy covers',
  `related_controls` text DEFAULT NULL COMMENT 'JSON array of control IDs',
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `policy_ref` (`policy_ref`),
  KEY `idx_ref` (`policy_ref`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_next_review` (`next_review_date`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_policy_acknowledgments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `policy_id` int(10) unsigned NOT NULL,
  `policy_version_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `acknowledged_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `signature_hash` varchar(128) DEFAULT NULL COMMENT 'SHA-512 hash of user_id+policy_id+timestamp as digital signature',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_policy_version` (`user_id`,`policy_id`,`policy_version_id`),
  KEY `fk_pack_version` (`policy_version_id`),
  KEY `idx_policy` (`policy_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_acknowledged` (`acknowledged_at`),
  CONSTRAINT `fk_pack_policy` FOREIGN KEY (`policy_id`) REFERENCES `grc_policies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pack_version` FOREIGN KEY (`policy_version_id`) REFERENCES `grc_policy_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_policy_review_schedule` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `policy_id` int(10) unsigned NOT NULL,
  `scheduled_date` date NOT NULL,
  `reviewer_user_id` int(10) unsigned DEFAULT NULL,
  `status` enum('pending','in_progress','completed','overdue','skipped') DEFAULT 'pending',
  `completed_at` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `reminder_sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_policy` (`policy_id`),
  KEY `idx_scheduled` (`scheduled_date`),
  KEY `idx_status` (`status`),
  KEY `idx_reviewer` (`reviewer_user_id`),
  CONSTRAINT `fk_psched_policy` FOREIGN KEY (`policy_id`) REFERENCES `grc_policies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_policy_versions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `policy_id` int(10) unsigned NOT NULL,
  `version_number` int(10) unsigned NOT NULL,
  `content_markdown` longtext NOT NULL COMMENT 'Policy content in Markdown format',
  `change_summary` text DEFAULT NULL COMMENT 'What changed in this version',
  `status` enum('draft','review','approved','published','superseded') DEFAULT 'draft',
  `created_by` int(10) unsigned NOT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_policy_version` (`policy_id`,`version_number`),
  KEY `idx_policy` (`policy_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_pver_policy` FOREIGN KEY (`policy_id`) REFERENCES `grc_policies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_question_framework_map` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `question_id` int(10) unsigned NOT NULL COMMENT 'FK to unified question',
  `framework_id` int(10) unsigned NOT NULL COMMENT 'FK to compliance framework',
  `requirement_id` int(10) unsigned NOT NULL COMMENT 'FK to framework requirement',
  `mapping_strength` enum('exact','strong','partial','related') DEFAULT 'strong' COMMENT 'How closely the question maps',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_question_requirement` (`question_id`,`requirement_id`),
  KEY `idx_question` (`question_id`),
  KEY `idx_framework` (`framework_id`),
  KEY `idx_requirement` (`requirement_id`),
  KEY `idx_strength` (`mapping_strength`),
  CONSTRAINT `fk_qfm_framework` FOREIGN KEY (`framework_id`) REFERENCES `grc_frameworks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qfm_question` FOREIGN KEY (`question_id`) REFERENCES `grc_unified_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qfm_requirement` FOREIGN KEY (`requirement_id`) REFERENCES `grc_framework_requirements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1053 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_recommended_controls` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(100) NOT NULL COMMENT 'Control category grouping',
  `control_name` varchar(255) NOT NULL COMMENT 'Common name of the control',
  `description` text DEFAULT NULL COMMENT 'What this control does',
  `control_type` enum('preventive','detective','corrective','directive') DEFAULT 'preventive',
  `control_category` enum('technical','administrative','physical') DEFAULT 'technical',
  `applicable_domains` text DEFAULT NULL COMMENT 'JSON array of domain codes this applies to',
  `vendor_examples` text DEFAULT NULL COMMENT 'Example vendor products',
  `sort_order` int(10) unsigned DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cat_name` (`category`,`control_name`),
  KEY `idx_category` (`category`),
  KEY `idx_active` (`is_active`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_remediation_plans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plan_ref` varchar(50) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `source_type` enum('audit_finding','control_test','risk','vulnerability','incident','other') DEFAULT 'audit_finding',
  `source_id` int(10) unsigned DEFAULT NULL COMMENT 'ID from the source table',
  `finding_id` int(10) unsigned DEFAULT NULL,
  `control_id` int(10) unsigned DEFAULT NULL,
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `status` enum('open','in_progress','completed','overdue','cancelled') DEFAULT 'open',
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `planned_start` date DEFAULT NULL,
  `planned_completion` date DEFAULT NULL,
  `actual_completion` date DEFAULT NULL,
  `milestones` text DEFAULT NULL COMMENT 'JSON array of milestone objects',
  `resources_required` text DEFAULT NULL,
  `cost_estimate` decimal(12,2) DEFAULT NULL,
  `verification_method` text DEFAULT NULL,
  `verified_by` int(10) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_ref` (`plan_ref`),
  KEY `fk_rplan_finding` (`finding_id`),
  KEY `fk_rplan_control` (`control_id`),
  KEY `idx_ref` (`plan_ref`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_assigned` (`assigned_to`),
  KEY `idx_due` (`planned_completion`),
  CONSTRAINT `fk_rplan_control` FOREIGN KEY (`control_id`) REFERENCES `grc_internal_controls` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rplan_finding` FOREIGN KEY (`finding_id`) REFERENCES `grc_audit_findings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_risk_register` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `risk_ref` varchar(50) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `risk_category` enum('strategic','operational','financial','compliance','reputational','technology','third_party') DEFAULT 'compliance',
  `likelihood` enum('rare','unlikely','possible','likely','almost_certain') DEFAULT 'possible',
  `impact` enum('insignificant','minor','moderate','major','catastrophic') DEFAULT 'moderate',
  `inherent_risk_score` decimal(5,2) DEFAULT NULL,
  `residual_risk_score` decimal(5,2) DEFAULT NULL,
  `risk_treatment` enum('accept','mitigate','transfer','avoid') DEFAULT 'mitigate',
  `treatment_plan` text DEFAULT NULL,
  `owner_user_id` int(10) unsigned DEFAULT NULL,
  `control_ids` text DEFAULT NULL COMMENT 'JSON array of mitigating control IDs',
  `status` enum('identified','assessing','treating','monitoring','closed') DEFAULT 'identified',
  `review_date` date DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `vendor_id` int(10) unsigned DEFAULT NULL COMMENT 'vendor_onboarding_requests.id this risk is associated with',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `risk_ref` (`risk_ref`),
  KEY `idx_ref` (`risk_ref`),
  KEY `idx_category` (`risk_category`),
  KEY `idx_status` (`status`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_vendor_id` (`vendor_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_scopes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) DEFAULT '#6B7280',
  `sort_order` int(10) unsigned DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scope_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_security_domains` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain_code` varchar(10) NOT NULL COMMENT 'Short code: GOV, IAM, DSP, etc.',
  `name` varchar(255) NOT NULL COMMENT 'Full domain name',
  `description` text DEFAULT NULL,
  `icon` varchar(100) DEFAULT NULL COMMENT 'Dashboard icon filename (e.g., shield-01.svg)',
  `sort_order` int(10) unsigned DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_code` (`domain_code`),
  KEY `idx_domain_code` (`domain_code`),
  KEY `idx_active` (`is_active`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `grc_task_digest_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `task_fingerprint` varchar(64) NOT NULL,
  `task_count` int(10) unsigned NOT NULL DEFAULT 0,
  `sent_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_sent` (`user_id`,`sent_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `grc_unified_questions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `question_ref` varchar(20) NOT NULL COMMENT 'Unique reference (e.g., GOV-01, IAM-03)',
  `domain_id` int(10) unsigned NOT NULL COMMENT 'FK to security domain',
  `question_text` text NOT NULL COMMENT 'The actual assessment question',
  `guidance` text DEFAULT NULL COMMENT 'Implementation guidance with examples',
  `maturity_1_desc` varchar(500) DEFAULT NULL COMMENT 'What Initial/Ad Hoc looks like',
  `maturity_2_desc` varchar(500) DEFAULT NULL COMMENT 'What Developing/Repeatable looks like',
  `maturity_3_desc` varchar(500) DEFAULT NULL COMMENT 'What Established/Defined looks like',
  `maturity_4_desc` varchar(500) DEFAULT NULL COMMENT 'What Adaptive/Optimized looks like',
  `control_examples` text DEFAULT NULL COMMENT 'Practical examples for non-technical auditors',
  `is_required` tinyint(1) DEFAULT 1,
  `sort_order` int(10) unsigned DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `question_ref` (`question_ref`),
  KEY `idx_domain` (`domain_id`),
  KEY `idx_ref` (`question_ref`),
  KEY `idx_required` (`is_required`),
  KEY `idx_sort` (`sort_order`),
  CONSTRAINT `fk_uq_domain` FOREIGN KEY (`domain_id`) REFERENCES `grc_security_domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=174 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `saml_config` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `is_enabled` tinyint(1) DEFAULT 0,
  `idp_entity_id` varchar(255) NOT NULL,
  `idp_sso_url` varchar(500) NOT NULL,
  `idp_slo_url` varchar(500) DEFAULT NULL,
  `idp_certificate` text NOT NULL COMMENT 'X.509 certificate',
  `sp_entity_id` varchar(255) NOT NULL,
  `sp_acs_url` varchar(500) NOT NULL,
  `sp_slo_url` varchar(500) DEFAULT NULL,
  `sp_certificate` text DEFAULT NULL,
  `sp_private_key` text DEFAULT NULL COMMENT 'Encrypted private key',
  `attribute_mapping` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Map SAML attributes to user fields' CHECK (json_valid(`attribute_mapping`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `group_attribute` varchar(255) DEFAULT 'groups' COMMENT 'SAML assertion attribute name containing group claims',
  `auto_activate` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Auto-activate new SSO-provisioned accounts (1=active, 0=pending)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `saml_group_mappings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `saml_group_name` varchar(255) NOT NULL COMMENT 'SAML group/role claim value',
  `acl_group_id` int(10) unsigned NOT NULL,
  `auto_assign` tinyint(1) DEFAULT 1 COMMENT 'Automatically assign on SAML login',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `saml_group_name` (`saml_group_name`),
  KEY `acl_group_id` (`acl_group_id`),
  KEY `idx_saml_group_name` (`saml_group_name`),
  CONSTRAINT `saml_group_mappings_ibfk_1` FOREIGN KEY (`acl_group_id`) REFERENCES `acl_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `score` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `Company` varchar(255) DEFAULT NULL COMMENT 'Vendor domain name',
  `Average_Score` decimal(5,2) DEFAULT NULL COMMENT 'Average security score for this scan',
  `Letter_Grade` char(1) DEFAULT NULL COMMENT 'Letter grade (A-F)',
  `Date_Ran` date DEFAULT NULL COMMENT 'Date the scan was executed',
  `Source` varchar(255) DEFAULT NULL COMMENT 'Score source/category (e.g., total average)',
  PRIMARY KEY (`id`),
  KEY `idx_company` (`Company`),
  KEY `idx_date_ran` (`Date_Ran`),
  KEY `idx_source` (`Source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_last_activity` (`last_activity`),
  KEY `idx_sessions_activity` (`last_activity`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `shadow_saas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_name` varchar(500) NOT NULL,
  `vendor_domain` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `risk_score` int(10) unsigned DEFAULT NULL,
  `risk_type` text DEFAULT NULL,
  `relationship_manager` varchar(255) DEFAULT NULL,
  `number_of_users` int(10) unsigned DEFAULT NULL,
  `application_category` varchar(255) DEFAULT NULL,
  `breaches_in_three_years` int(10) unsigned DEFAULT NULL,
  `downloadbytes` bigint(20) unsigned DEFAULT NULL,
  `uploadbytes` bigint(20) unsigned DEFAULT NULL,
  `filesharing` varchar(10) DEFAULT NULL,
  `mfasupport` varchar(10) DEFAULT NULL,
  `current_srs_score` int(11) DEFAULT NULL,
  `last_srs_score_at` datetime DEFAULT NULL,
  `current_shodan_score` int(11) DEFAULT NULL,
  `last_shodan_score_at` datetime DEFAULT NULL,
  `rescore_status` varchar(30) DEFAULT NULL,
  `rescore_started_at` datetime DEFAULT NULL,
  `rescore_result` text DEFAULT NULL,
  `status` enum('pending','onboarded','dismissed','unsanctioned') DEFAULT 'pending',
  `onboarded_vendor_id` int(10) unsigned DEFAULT NULL,
  `onboarded_at` datetime DEFAULT NULL,
  `onboarded_by` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_vendor_name` (`vendor_name`),
  KEY `idx_vendor_domain` (`vendor_domain`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=14584 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `tier` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(64) NOT NULL COMMENT 'Vendor domain name',
  `tier` int(11) NOT NULL COMMENT 'Vendor tier: 1=daily, 2=90-day, 3=annual',
  `scanoption` varchar(32) NOT NULL COMMENT 'Scan configuration option',
  PRIMARY KEY (`id`),
  KEY `idx_domain` (`domain`),
  KEY `idx_tier` (`tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `tprm_results` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `vendor_name` varchar(255) NOT NULL,
  `vendor_domain` varchar(255) DEFAULT NULL COMMENT 'Vendor domain name (e.g., example.com)',
  `msa` text DEFAULT NULL COMMENT 'Encrypted: Master Services Agreement',
  `scope_of_work` text DEFAULT NULL COMMENT 'Encrypted: Description of work',
  `medium_of_data` text DEFAULT NULL COMMENT 'Encrypted: Data transfer method',
  `certifications` text DEFAULT NULL COMMENT 'Encrypted: Security certifications',
  `compliance` text DEFAULT NULL COMMENT 'Encrypted: Compliance info',
  `security_governance` text DEFAULT NULL COMMENT 'Encrypted: Security governance',
  `incident_response_plan` text DEFAULT NULL COMMENT 'Encrypted: Incident response plan',
  `continuous_monitoring` text DEFAULT NULL COMMENT 'Encrypted: Continuous monitoring',
  `supply_chain_risk_mgmt` text DEFAULT NULL COMMENT 'Encrypted: Supply chain risk management',
  `security_awareness_training` text DEFAULT NULL COMMENT 'Encrypted: Security awareness training',
  `vulnerability_management` text DEFAULT NULL COMMENT 'Encrypted: Vulnerability management',
  `patch_management` text DEFAULT NULL COMMENT 'Encrypted: Patch management',
  `access_controls` text DEFAULT NULL COMMENT 'Encrypted: Access controls',
  `data_encryption` text DEFAULT NULL COMMENT 'Encrypted: Data encryption',
  `network_security` text DEFAULT NULL COMMENT 'Encrypted: Network security',
  `daily_impact` text DEFAULT NULL COMMENT 'Encrypted: Daily financial impact if vendor services are disrupted',
  `security_score` varchar(1) DEFAULT NULL COMMENT 'Security grade: A, B, C, D, or F',
  `vulnerability_data` text DEFAULT NULL COMMENT 'Encrypted: Vulnerability data',
  `configuration_data` text DEFAULT NULL COMMENT 'Encrypted: Configuration data',
  `compliance_data` text DEFAULT NULL COMMENT 'Encrypted: Compliance data',
  `risk_assessment` text DEFAULT NULL COMMENT 'Encrypted: Risk assessment',
  `threat_intelligence` text DEFAULT NULL COMMENT 'Encrypted: Threat intelligence',
  `iso_27001_certified` tinyint(1) DEFAULT 0 COMMENT 'ISO 27001 certification status (0=No, 1=Yes)',
  `securityscorecard_rating` varchar(1) DEFAULT NULL COMMENT 'SecurityScorecard rating: A, B, C, D, or F',
  `pii_record_count` int(10) unsigned DEFAULT 0 COMMENT 'Number of PII records - $160 per record breach cost',
  `spii_record_count` int(10) unsigned DEFAULT 0 COMMENT 'Number of SPII records - $200 per record breach cost',
  `sox_record_count` int(10) unsigned DEFAULT 0 COMMENT 'Number of SOX records - $5M flat fine if breached',
  `vendor_risk_assessment` text DEFAULT NULL COMMENT 'Encrypted: Vendor risk assessment',
  `security_questionnaire` text DEFAULT NULL COMMENT 'Encrypted: Security questionnaire',
  `compliance_questionnaire` text DEFAULT NULL COMMENT 'Encrypted: Compliance questionnaire',
  `data_classification` text DEFAULT NULL COMMENT 'Encrypted: Data classification',
  `data_sharing` text DEFAULT NULL COMMENT 'Encrypted: Data sharing',
  `business_impact` text DEFAULT NULL COMMENT 'Encrypted: Business impact',
  `vendor_performance` text DEFAULT NULL COMMENT 'Encrypted: Vendor performance',
  `third_party_vendor_list` text DEFAULT NULL COMMENT 'Encrypted: Third-party vendor list',
  `third_party_risk_assessment` text DEFAULT NULL COMMENT 'Encrypted: Third-party risk assessment',
  `third_party_security_questionnaire` text DEFAULT NULL COMMENT 'Encrypted: Third-party security questionnaire',
  `third_party_compliance_questionnaire` text DEFAULT NULL COMMENT 'Encrypted: Third-party compliance questionnaire',
  `vendor_cyber_insurance_coverage` text DEFAULT NULL COMMENT 'Encrypted: Vendor cyber insurance coverage amount',
  `cost_of_breach` text DEFAULT NULL COMMENT 'Encrypted: Cost of breach',
  `cost_of_outage` text DEFAULT NULL COMMENT 'Encrypted: Cost of outage',
  `sec_fines` text DEFAULT NULL COMMENT 'Encrypted: SEC fines',
  `compliance_fines` text DEFAULT NULL COMMENT 'Encrypted: Compliance fines',
  `total_cost_of_breach` text DEFAULT NULL COMMENT 'Encrypted: Total cost of breach calculation',
  `pii_breach_cost` text DEFAULT NULL COMMENT 'Encrypted: PII breach cost calculation',
  `spii_breach_cost` text DEFAULT NULL COMMENT 'Encrypted: SPII breach cost calculation',
  `sox_breach_cost` text DEFAULT NULL COMMENT 'Encrypted: SOX breach cost calculation',
  `insurance_premiums` text DEFAULT NULL COMMENT 'Encrypted: Insurance premiums',
  `loss_event_frequency` text DEFAULT NULL COMMENT 'Encrypted: Loss Event Frequency',
  `loss_magnitude` text DEFAULT NULL COMMENT 'Encrypted: Loss Magnitude',
  `primary_loss_magnitude` text DEFAULT NULL COMMENT 'Encrypted: Primary Loss Magnitude',
  `secondary_loss_magnitude` text DEFAULT NULL COMMENT 'Encrypted: Secondary Loss Magnitude',
  `risk_output` text DEFAULT NULL COMMENT 'Encrypted: Risk output',
  `ale` text DEFAULT NULL COMMENT 'Encrypted: Annualized Loss Expectancy',
  `recommended_liability` text DEFAULT NULL COMMENT 'Encrypted: Recommended Liability',
  `executive_summary` text DEFAULT NULL COMMENT 'AI-generated executive summary for PDF reports',
  `status` enum('draft','completed','archived') DEFAULT 'draft',
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_vendor_name` (`vendor_name`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_tprm_vendor_user` (`vendor_name`,`user_id`),
  KEY `idx_tprm_status_created` (`status`,`created_at`),
  KEY `idx_vendor_domain` (`vendor_domain`),
  KEY `idx_completed_at` (`completed_at`),
  CONSTRAINT `tprm_results_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `user_acl_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `assigned_at` timestamp NULL DEFAULT current_timestamp(),
  `assigned_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_group` (`user_id`,`group_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `assigned_by` (`assigned_by`),
  CONSTRAINT `user_acl_groups_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_acl_groups_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `acl_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_acl_groups_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=381 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL COMMENT 'Bcrypt/Argon2 hashed password with automatic salt',
  `email` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_admin` tinyint(1) DEFAULT 0,
  `is_super_admin` tinyint(1) DEFAULT 0 COMMENT 'Super admin with full system access',
  `totp_enabled` tinyint(1) DEFAULT 0,
  `totp_secret` varchar(255) DEFAULT NULL COMMENT 'Encrypted TOTP secret',
  `failed_login_attempts` int(11) DEFAULT 0,
  `last_failed_login` datetime DEFAULT NULL,
  `account_locked_until` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `theme_logo_url` varchar(500) DEFAULT NULL COMMENT 'User-specific header logo URL (overrides app default)',
  `theme_footer_logo_url` varchar(500) DEFAULT NULL COMMENT 'User-specific footer logo URL (overrides app default)',
  `theme_header_color` varchar(20) DEFAULT NULL COMMENT 'User-specific header color',
  `theme_footer_color` varchar(20) DEFAULT NULL COMMENT 'User-specific footer color',
  `theme_button_color` varchar(20) DEFAULT NULL COMMENT 'User-specific button color',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `department` varchar(100) DEFAULT NULL COMMENT 'User department',
  `job_title` varchar(100) DEFAULT NULL COMMENT 'User job title',
  `theme_nav_fill_color` varchar(20) DEFAULT NULL,
  `theme_nav_font_color` varchar(20) DEFAULT NULL,
  `theme_nav_width` varchar(10) DEFAULT NULL,
  `dashboard_modules` text DEFAULT NULL COMMENT 'JSON array of selected dashboard module keys (max 9)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=360 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_annual_reviews` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_request_id` int(10) unsigned NOT NULL,
  `review_date` datetime NOT NULL,
  `due_date` date NOT NULL,
  `reviewer_user_id` int(10) unsigned NOT NULL,
  `is_still_stakeholder` enum('yes','no') NOT NULL,
  `new_stakeholder_id` int(10) unsigned DEFAULT NULL COMMENT 'If stakeholder changed, new stakeholder user ID',
  `scope_changes` text DEFAULT NULL COMMENT 'Description of any scope changes',
  `contact_updates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON object with updated contact information' CHECK (json_valid(`contact_updates`)),
  `review_notes` text DEFAULT NULL COMMENT 'Additional notes from reviewer',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vendor_request` (`vendor_request_id`),
  KEY `idx_review_date` (`review_date`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_reviewer` (`reviewer_user_id`),
  CONSTRAINT `vendor_annual_reviews_ibfk_1` FOREIGN KEY (`vendor_request_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_assessment_reminders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_id` int(11) NOT NULL,
  `reminder_type` enum('initial','7_days_before','3_days_before','expiry_day') NOT NULL,
  `expires_at` date NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `email_sent_to` varchar(255) DEFAULT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_assessment_reminder` (`assessment_id`,`reminder_type`,`expires_at`),
  KEY `idx_var_assessment` (`assessment_id`),
  KEY `idx_var_status` (`status`),
  CONSTRAINT `vendor_assessment_reminders_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `vendor_assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_assessment_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assessment_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `response_value` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_response` (`assessment_id`,`question_id`),
  KEY `question_id` (`question_id`),
  KEY `idx_assessment` (`assessment_id`),
  CONSTRAINT `vendor_assessment_responses_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `vendor_assessments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_assessment_responses_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `assessment_questions` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1908 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_assessments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `template_id` int(11) NOT NULL,
  `vendor_request_id` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) NOT NULL,
  `vendor_email` varchar(255) NOT NULL,
  `vendor_contact_name` varchar(255) DEFAULT NULL,
  `vendor_contact_email` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `current_section_id` int(11) DEFAULT NULL,
  `certificate_uploaded` tinyint(1) DEFAULT 0,
  `certificate_path` varchar(500) DEFAULT NULL,
  `certificate_expiry` date DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `triggered_by_assessment_id` int(11) DEFAULT NULL,
  `triggered_by_question_id` int(11) DEFAULT NULL,
  `triggered_by_rule_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `template_id` (`template_id`),
  KEY `idx_status` (`status`),
  KEY `idx_uuid` (`uuid`),
  KEY `idx_vendor_request` (`vendor_request_id`),
  CONSTRAINT `vendor_assessments_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `assessment_templates` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=313 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_contract_reminders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_document_id` int(10) unsigned NOT NULL,
  `recipient_user_id` int(10) unsigned NOT NULL,
  `reminder_type` varchar(30) NOT NULL,
  `expiration_date` date NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `email_sent_to` varchar(255) DEFAULT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_contract_reminder` (`vendor_document_id`,`recipient_user_id`,`reminder_type`,`expiration_date`),
  KEY `idx_vcr_vendor_document` (`vendor_document_id`),
  KEY `idx_vcr_recipient` (`recipient_user_id`),
  KEY `idx_vcr_status` (`status`),
  CONSTRAINT `vendor_contract_reminders_ibfk_1` FOREIGN KEY (`vendor_document_id`) REFERENCES `vendor_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_contract_reminders_ibfk_2` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `file_uuid` varchar(36) NOT NULL COMMENT 'Public-facing UUID for download links',
  `vendor_request_id` int(10) unsigned NOT NULL COMMENT 'FK to vendor_onboarding_requests',
  `document_type` varchar(20) NOT NULL COMMENT 'contract, certification, or other',
  `contract_name` varchar(255) DEFAULT NULL COMMENT 'Contract display name',
  `contract_type` varchar(50) DEFAULT NULL COMMENT 'NDA, DPA, Master Service Agreement, Privacy, Order Form, PO',
  `contract_creation_date` date DEFAULT NULL,
  `contract_expiration_date` date DEFAULT NULL,
  `certification_type` varchar(100) DEFAULT NULL COMMENT 'SOC 2 Type II, ISO 27001, etc.',
  `certification_expiration_date` date DEFAULT NULL COMMENT 'Expiration date for certifications',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=Active, 0=Inactive',
  `description` text DEFAULT NULL COMMENT 'Description for other document type',
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL COMMENT 'Size in bytes',
  `encrypted_data` longblob NOT NULL COMMENT 'AES-256 encrypted file data',
  `uploaded_by` int(10) unsigned NOT NULL COMMENT 'FK to users',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `contract_pricing` text DEFAULT NULL COMMENT 'JSON: pricing metadata for Order Form/PO contracts',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_uuid` (`file_uuid`),
  KEY `idx_vendor_request` (`vendor_request_id`),
  KEY `idx_document_type` (`document_type`),
  KEY `idx_contract_expiration` (`contract_expiration_date`),
  KEY `idx_certification_type` (`certification_type`),
  KEY `idx_cert_expiration` (`certification_expiration_date`),
  KEY `idx_is_active` (`is_active`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `vendor_documents_ibfk_1` FOREIGN KEY (`vendor_request_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=126 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_onboarding_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created_by` int(10) unsigned NOT NULL COMMENT 'User who created this request',
  `vendor_name` varchar(500) DEFAULT NULL COMMENT '1.1 Vendor Name',
  `vendor_domain` varchar(255) DEFAULT NULL COMMENT 'Vendor domain name for SRS scoring',
  `vendor_sisterdomains` text DEFAULT NULL COMMENT 'Related/sister domains for Shodan scanning (one per line)',
  `vendor_id` varchar(20) DEFAULT NULL COMMENT 'VSU Vendor ID (VID)',
  `vendor_type` varchar(255) DEFAULT NULL COMMENT '1.2 Vendor Type',
  `vendor_tier` enum('1','2','3') DEFAULT NULL COMMENT 'Vendor tier for SRS rescoring: 1=Monthly, 2=90-days, 3=Daily',
  `relationship_manager` varchar(255) DEFAULT NULL COMMENT '1.3 Business relationship manager',
  `expected_procurement_date` date DEFAULT NULL COMMENT '1.4 Expected procurement date',
  `product_service_description` text DEFAULT NULL COMMENT '1.5 Product/service description',
  `target_user_count` varchar(100) DEFAULT NULL COMMENT '1.6 Target user count',
  `primary_contact_email` varchar(255) DEFAULT NULL COMMENT '1.7 Primary contact email',
  `primary_contact_details` text DEFAULT NULL COMMENT '1.8 Primary contact full name, title, phone',
  `nda_in_place` enum('yes','no','') DEFAULT '' COMMENT '1.9 Is NDA in place?',
  `vendor_competitors` text DEFAULT NULL COMMENT '1.10 Vendor competitors',
  `vsu_onboarded` enum('yes','no','') DEFAULT '' COMMENT 'VSU onboarding status',
  `pii_phi_exchange` enum('yes','no','') DEFAULT '' COMMENT '2.1 PII/PHI exchange?',
  `pii_phi_justification` text DEFAULT NULL COMMENT '2.1 PII/PHI justification',
  `confidential_info_shared` enum('yes','no','') DEFAULT '' COMMENT '2.2 Confidential info shared?',
  `confidential_info_justification` text DEFAULT NULL COMMENT '2.2 Confidential info justification',
  `cross_border_transfer` enum('yes','no','') DEFAULT '' COMMENT '2.3 Cross-border data transfer?',
  `cross_border_justification` text DEFAULT NULL COMMENT '2.3 Cross-border justification',
  `offsite_data_hosting` enum('yes','no','') DEFAULT '' COMMENT '2.4 Off-site data hosting?',
  `offsite_data_justification` text DEFAULT NULL COMMENT '2.4 Off-site data justification',
  `remote_network_access` enum('yes','no','') DEFAULT '' COMMENT '2.5 Remote network access?',
  `remote_access_justification` text DEFAULT NULL COMMENT '2.5 Remote access justification',
  `source_code_access` enum('yes','no','') DEFAULT '' COMMENT '2.6 Source code/repo access?',
  `source_code_justification` text DEFAULT NULL COMMENT '2.6 Source code justification',
  `critical_business_function` enum('yes','no','') DEFAULT '' COMMENT '2.7 Critical business function?',
  `critical_function_justification` text DEFAULT NULL COMMENT '2.7 Critical function justification',
  `unauthorized_disclosure_impact` enum('low','moderate','high','severe','') DEFAULT '' COMMENT '2.8 Impact of unauthorized disclosure',
  `unauthorized_disclosure_justification` text DEFAULT NULL COMMENT '2.8 Unauthorized disclosure justification',
  `unauthorized_modification_impact` enum('low','moderate','high','severe','') DEFAULT '' COMMENT '2.9 Impact of unauthorized modification',
  `disruption_impact` enum('low','moderate','high','severe','') DEFAULT '' COMMENT '2.10 Impact of disruption',
  `saml_sso_support` enum('yes','no','unknown','') DEFAULT '' COMMENT '2.11 SAML/SSO support?',
  `is_saas` enum('yes','no','') DEFAULT '' COMMENT '2.12 Is SaaS product?',
  `vendor_use_ai` enum('yes','no','') DEFAULT '' COMMENT 'Whether vendor solution uses AI',
  `pii_record_count` int(10) unsigned DEFAULT 0 COMMENT 'Number of PII records',
  `spii_record_count` int(10) unsigned DEFAULT 0 COMMENT 'Number of SPII records',
  `sox_record_count` int(10) unsigned DEFAULT 0 COMMENT 'Number of SOX records',
  `business_impact` decimal(15,2) DEFAULT NULL COMMENT 'Business impact value',
  `additional_information` text DEFAULT NULL COMMENT '3.1 Additional information',
  `current_srs_score` int(11) DEFAULT NULL COMMENT 'Current UpGuard SRS score (0-950)',
  `last_srs_score_at` datetime DEFAULT NULL COMMENT 'Last SRS score fetch time',
  `current_shodan_score` int(11) DEFAULT NULL COMMENT 'Current Shodan security score (0-100)',
  `last_shodan_score_at` datetime DEFAULT NULL COMMENT 'Last Shodan score fetch time',
  `status` enum('draft','submitted','in_review','ai_review','approved','rejected','inactive','evaluation') DEFAULT 'draft',
  `status_notes` text DEFAULT NULL COMMENT 'Notes about status changes',
  `marked_inactive_at` datetime DEFAULT NULL COMMENT 'When marked as inactive',
  `marked_inactive_by` int(10) unsigned DEFAULT NULL COMMENT 'Who marked it inactive',
  `last_autosave` datetime DEFAULT NULL COMMENT 'Last autosave timestamp',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `submitted_at` datetime DEFAULT NULL COMMENT 'When form was submitted',
  `last_annual_review` datetime DEFAULT NULL,
  `last_annual_review_due` date DEFAULT NULL COMMENT 'Next annual review due date (calculated from last review or approval date)',
  `vendor_favicon` mediumblob DEFAULT NULL,
  `vendor_favicon_mime` varchar(50) DEFAULT NULL,
  `primary_contact_title` varchar(255) DEFAULT NULL,
  `primary_contact_phone` varchar(50) DEFAULT NULL,
  `vat_number` varchar(64) DEFAULT NULL COMMENT 'EU VAT number (canonical, e.g. DE123456789)',
  `rescore_status` varchar(30) DEFAULT NULL,
  `rescore_started_at` datetime DEFAULT NULL,
  `rescore_result` text DEFAULT NULL,
  `custom_score` int(11) DEFAULT NULL COMMENT 'Custom manual security score (1-100)',
  PRIMARY KEY (`id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status` (`status`),
  KEY `idx_vendor_name` (`vendor_name`(255)),
  KEY `idx_vendor_domain` (`vendor_domain`),
  KEY `idx_vendor_tier` (`vendor_tier`),
  KEY `idx_last_srs_score` (`last_srs_score_at`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_submitted_at` (`submitted_at`),
  KEY `marked_inactive_by` (`marked_inactive_by`),
  KEY `idx_last_annual_review_due` (`last_annual_review_due`),
  KEY `idx_last_annual_review` (`last_annual_review`),
  CONSTRAINT `vendor_onboarding_requests_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_onboarding_requests_ibfk_2` FOREIGN KEY (`marked_inactive_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=471 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_onboarding_stakeholders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` enum('owner','stakeholder','reviewer') DEFAULT 'stakeholder',
  `assigned_at` timestamp NULL DEFAULT current_timestamp(),
  `assigned_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_request_user` (`request_id`,`user_id`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `assigned_by` (`assigned_by`),
  CONSTRAINT `vendor_onboarding_stakeholders_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_onboarding_stakeholders_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_onboarding_stakeholders_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=530 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_review_reminders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_request_id` int(10) unsigned NOT NULL,
  `stakeholder_user_id` int(10) unsigned NOT NULL,
  `reminder_type` enum('30_days_before','due_date','overdue') NOT NULL,
  `due_date` date NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `email_sent_to` varchar(255) DEFAULT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reminder` (`vendor_request_id`,`stakeholder_user_id`,`reminder_type`,`due_date`),
  KEY `idx_vendor_request` (`vendor_request_id`),
  KEY `idx_stakeholder` (`stakeholder_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sent_at` (`sent_at`),
  CONSTRAINT `vendor_review_reminders_ibfk_1` FOREIGN KEY (`vendor_request_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_shodan_cve_waivers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_onboarding_id` int(10) unsigned NOT NULL,
  `cve_id` varchar(50) NOT NULL,
  `reason` text DEFAULT NULL,
  `waived_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cve_waiver` (`vendor_onboarding_id`,`cve_id`),
  KEY `idx_cve_waiver_vendor` (`vendor_onboarding_id`),
  CONSTRAINT `vendor_shodan_cve_waivers_ibfk_1` FOREIGN KEY (`vendor_onboarding_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `shodan_rescan_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `scan_id` varchar(64) DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `requested_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_requested` (`ip_address`,`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_shodan_findings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `shodan_score_id` int(10) unsigned NOT NULL,
  `finding_type` enum('open_port','vulnerability','service','tls_crypto','network_security','app_hardening','email_security','positive_signal','negative_signal') NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `port` int(11) DEFAULT NULL,
  `protocol` varchar(20) DEFAULT NULL,
  `service_name` varchar(255) DEFAULT NULL,
  `cve_id` varchar(50) DEFAULT NULL,
  `cvss_score` decimal(3,1) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `severity` varchar(20) DEFAULT NULL,
  `signal_type` varchar(10) DEFAULT NULL,
  `category` varchar(30) DEFAULT NULL,
  `points` int(11) DEFAULT NULL,
  `confidence` varchar(10) DEFAULT NULL,
  `subdomain` varchar(255) DEFAULT NULL,
  `proof` text DEFAULT NULL COMMENT 'JSON proof/evidence data from Shodan',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shodan_score` (`shodan_score_id`),
  KEY `idx_finding_type` (`finding_type`),
  KEY `idx_cve_id` (`cve_id`),
  CONSTRAINT `vendor_shodan_findings_ibfk_1` FOREIGN KEY (`shodan_score_id`) REFERENCES `vendor_shodan_scores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=76842 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_shodan_scores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_onboarding_id` int(10) unsigned NOT NULL,
  `vendor_domain` varchar(255) NOT NULL,
  `score` int(11) NOT NULL COMMENT 'Shodan security score (0-100)',
  `score_grade` varchar(10) DEFAULT NULL,
  `open_ports_count` int(11) DEFAULT 0,
  `vuln_count` int(11) DEFAULT 0,
  `critical_vulns` int(11) DEFAULT 0,
  `high_vulns` int(11) DEFAULT 0,
  `medium_vulns` int(11) DEFAULT 0,
  `low_vulns` int(11) DEFAULT 0,
  `category_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`category_scores`)),
  `traffic_light` varchar(10) DEFAULT NULL,
  `positive_count` int(11) DEFAULT 0,
  `negative_count` int(11) DEFAULT 0,
  `subdomains_scanned` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`subdomains_scanned`)),
  `ip_addresses` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ip_addresses`)),
  `scored_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vendor_onboarding` (`vendor_onboarding_id`),
  KEY `idx_vendor_domain` (`vendor_domain`),
  KEY `idx_scored_at` (`scored_at`),
  KEY `idx_score` (`score`),
  CONSTRAINT `vendor_shodan_scores_ibfk_1` FOREIGN KEY (`vendor_onboarding_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=721 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_shodan_waivers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_onboarding_id` int(10) unsigned NOT NULL,
  `signal_name` varchar(100) NOT NULL COMMENT 'Signal identifier e.g. tls_1_0_enabled, server_version_disclosed',
  `category` varchar(30) NOT NULL COMMENT 'Scoring category: tls_crypto, network_security, etc.',
  `subdomain` varchar(255) NOT NULL COMMENT 'Subdomain this waiver applies to (per-subdomain scope)',
  `label` varchar(255) DEFAULT NULL COMMENT 'Human-readable signal label',
  `reason` text DEFAULT NULL COMMENT 'User-provided justification for the waiver',
  `waived_by` varchar(255) DEFAULT NULL COMMENT 'Username of who created the waiver',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_waiver` (`vendor_onboarding_id`,`signal_name`,`subdomain`),
  KEY `idx_vendor` (`vendor_onboarding_id`),
  KEY `idx_signal_lookup` (`vendor_onboarding_id`,`signal_name`,`subdomain`),
  CONSTRAINT `vendor_shodan_waivers_ibfk_1` FOREIGN KEY (`vendor_onboarding_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=143 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_srs_risks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `srs_score_id` int(10) unsigned NOT NULL COMMENT 'Reference to vendor_srs_scores',
  `risk_id` varchar(255) NOT NULL COMMENT 'UpGuard risk identifier',
  `risk_name` varchar(500) DEFAULT NULL COMMENT 'Human-readable risk name',
  `risk_category` varchar(255) DEFAULT NULL COMMENT 'Risk category',
  `severity` varchar(50) DEFAULT NULL COMMENT 'high, medium, low, info',
  `risk_host` mediumtext DEFAULT NULL COMMENT 'Hostname(s) or IP(s) where risk was detected',
  `description` text DEFAULT NULL COMMENT 'Risk description',
  `first_seen` datetime DEFAULT NULL COMMENT 'When risk was first detected',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_srs_score` (`srs_score_id`),
  KEY `idx_risk_id` (`risk_id`),
  KEY `idx_severity` (`severity`),
  CONSTRAINT `vendor_srs_risks_ibfk_1` FOREIGN KEY (`srs_score_id`) REFERENCES `vendor_srs_scores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=61614 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_srs_scores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_onboarding_id` int(10) unsigned NOT NULL COMMENT 'Reference to vendor_onboarding_requests',
  `vendor_domain` varchar(255) NOT NULL COMMENT 'Vendor domain that was scored',
  `score` int(11) NOT NULL COMMENT 'UpGuard SRS score (0-950)',
  `score_grade` varchar(10) DEFAULT NULL COMMENT 'Grade (A, B, C, D, F)',
  `category_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON object with category breakdowns' CHECK (json_valid(`category_scores`)),
  `critical_risks` int(11) DEFAULT 0 COMMENT 'Number of critical severity risks',
  `high_risks` int(11) DEFAULT 0 COMMENT 'Number of high severity risks',
  `medium_risks` int(11) DEFAULT 0 COMMENT 'Number of medium severity risks',
  `low_risks` int(11) DEFAULT 0 COMMENT 'Number of low severity risks',
  `info_risks` int(11) DEFAULT 0 COMMENT 'Number of informational risks',
  `was_already_monitored` tinyint(1) DEFAULT 0 COMMENT 'Was vendor already being monitored',
  `scored_at` datetime NOT NULL COMMENT 'When the score was fetched',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vendor_onboarding` (`vendor_onboarding_id`),
  KEY `idx_vendor_domain` (`vendor_domain`),
  KEY `idx_scored_at` (`scored_at`),
  KEY `idx_score` (`score`),
  CONSTRAINT `vendor_srs_scores_ibfk_1` FOREIGN KEY (`vendor_onboarding_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=523 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_subdomain_scores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_onboarding_id` int(10) unsigned NOT NULL COMMENT 'FK to vendor_onboarding_requests',
  `vendor_domain` varchar(255) NOT NULL COMMENT 'Parent domain (e.g. hackrange.com)',
  `subdomain` varchar(255) NOT NULL COMMENT 'Subdomain/domain found (e.g. mail.hackrange.com)',
  `score` int(11) DEFAULT NULL COMMENT 'UpGuard numeric score (0-950)',
  `score_grade` varchar(10) DEFAULT NULL COMMENT 'Letter grade (A-F)',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Active/inactive flag',
  `last_scanned` datetime DEFAULT NULL COMMENT 'When UpGuard last scanned it',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP address (from Shodan DNS resolution)',
  `shodan_score` int(11) DEFAULT NULL COMMENT 'Per-subdomain Shodan score (0-100)',
  `shodan_grade` varchar(10) DEFAULT NULL COMMENT 'Shodan letter grade (A-F)',
  `shodan_findings_count` int(11) DEFAULT 0 COMMENT 'Number of Shodan findings for this subdomain',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vendor_subdomain` (`vendor_onboarding_id`,`subdomain`),
  KEY `idx_vendor_onboarding` (`vendor_onboarding_id`),
  KEY `idx_vendor_domain` (`vendor_domain`),
  KEY `idx_subdomain` (`subdomain`),
  CONSTRAINT `vendor_subdomain_scores_ibfk_1` FOREIGN KEY (`vendor_onboarding_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=219255 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_subprocessor_mappings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_onboarding_id` int(10) unsigned NOT NULL,
  `subprocessor_id` int(10) unsigned NOT NULL,
  `service_description` text DEFAULT NULL,
  `data_shared` text DEFAULT NULL,
  `added_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vendor_subprocessor` (`vendor_onboarding_id`,`subprocessor_id`),
  KEY `idx_subprocessor` (`subprocessor_id`),
  KEY `added_by` (`added_by`),
  CONSTRAINT `vendor_subprocessor_mappings_ibfk_1` FOREIGN KEY (`vendor_onboarding_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_subprocessor_mappings_ibfk_2` FOREIGN KEY (`subprocessor_id`) REFERENCES `vendor_subprocessors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_subprocessor_mappings_ibfk_3` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_subprocessors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `subprocessor_name` varchar(500) NOT NULL,
  `subprocessor_domain` varchar(255) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `linked_vendor_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_subprocessor_name` (`subprocessor_name`(255)),
  KEY `idx_linked_vendor` (`linked_vendor_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `vendor_subprocessors_ibfk_1` FOREIGN KEY (`linked_vendor_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_subprocessors_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `vendor_technologies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_onboarding_id` int(10) unsigned NOT NULL,
  `vendor_domain` varchar(255) NOT NULL,
  `technology_name` varchar(255) NOT NULL,
  `technology_category` varchar(50) NOT NULL,
  `technology_version` varchar(100) DEFAULT NULL,
  `detected_on` varchar(255) DEFAULT NULL,
  `detected_port` int(11) DEFAULT NULL,
  `detection_method` varchar(50) DEFAULT NULL,
  `detection_confidence` varchar(10) DEFAULT 'medium',
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `is_current` tinyint(1) DEFAULT 1,
  `raw_evidence` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cves` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vendor_tech` (`vendor_onboarding_id`,`technology_name`,`technology_category`,`detected_on`),
  KEY `idx_vendor_onboarding` (`vendor_onboarding_id`),
  KEY `idx_vendor_domain` (`vendor_domain`),
  KEY `idx_technology_name` (`technology_name`),
  KEY `idx_technology_category` (`technology_category`),
  KEY `idx_is_current` (`is_current`),
  CONSTRAINT `vendor_technologies_ibfk_1` FOREIGN KEY (`vendor_onboarding_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=38804 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `waf_block_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `blocked_at` datetime DEFAULT current_timestamp(),
  `client_ip` varchar(45) DEFAULT '',
  `uri` varchar(500) NOT NULL,
  `method` varchar(10) NOT NULL DEFAULT 'GET',
  `rule_id` int(10) unsigned DEFAULT 0,
  `rule_message` varchar(500) DEFAULT '',
  `request_headers` text DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT '',
  `resolved` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_blocked_at` (`blocked_at`),
  KEY `idx_client_ip` (`client_ip`),
  KEY `idx_uri` (`uri`(191))
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `waf_ip_whitelist` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `label` varchar(200) DEFAULT '',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip` (`ip_address`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `waf_learned_patterns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uri` varchar(500) NOT NULL,
  `method` varchar(10) NOT NULL DEFAULT 'GET',
  `param_names` text DEFAULT NULL,
  `content_type` varchar(200) DEFAULT '',
  `response_status` smallint(5) unsigned DEFAULT 200,
  `frequency` int(10) unsigned DEFAULT 1,
  `first_seen` datetime DEFAULT current_timestamp(),
  `last_seen` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_uri_method_ct` (`uri`(191),`method`,`content_type`(100))
) ENGINE=InnoDB AUTO_INCREMENT=2245 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `waf_learned_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uri` varchar(500) NOT NULL,
  `method` varchar(10) NOT NULL DEFAULT 'GET',
  `client_ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(500) DEFAULT '',
  `query_string` text DEFAULT NULL,
  `content_type` varchar(200) DEFAULT '',
  `response_status` smallint(5) unsigned DEFAULT 200,
  `request_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_uri_method` (`uri`(191),`method`),
  KEY `idx_client_ip` (`client_ip`),
  KEY `idx_request_at` (`request_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2052 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `waf_whitelist_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` int(10) unsigned NOT NULL,
  `uri_pattern` varchar(500) NOT NULL,
  `method_pattern` varchar(10) NOT NULL DEFAULT '*',
  `rule_text` text NOT NULL,
  `description` varchar(500) DEFAULT '',
  `is_auto_generated` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rule_id` (`rule_id`)
) ENGINE=InnoDB AUTO_INCREMENT=512 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cyber_breach_alerts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `alert_hash` varchar(64) NOT NULL,
  `alert_type` enum('data_breach','cyber_attack','vulnerability','supply_chain','ransomware','other') NOT NULL DEFAULT 'other',
  `severity` enum('critical','high','medium','low') NOT NULL DEFAULT 'medium',
  `title` varchar(500) NOT NULL,
  `summary` text NOT NULL,
  `affected_entity` varchar(500) NOT NULL,
  `affected_entity_type` enum('vendor','subprocessor','technology') NOT NULL,
  `affected_vendor_ids` text DEFAULT NULL,
  `affected_technology` varchar(255) DEFAULT NULL,
  `vendor_count` int(10) unsigned NOT NULL DEFAULT 0,
  `source_urls` text NOT NULL,
  `ai_analysis` text DEFAULT NULL,
  `status` enum('new','acknowledged','investigating','resolved','false_positive') NOT NULL DEFAULT 'new',
  `email_sent_at` datetime DEFAULT NULL,
  `acknowledged_by` int(10) unsigned DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `investigating_by` int(10) unsigned DEFAULT NULL,
  `investigating_at` datetime DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alert_hash` (`alert_hash`),
  KEY `idx_status` (`status`),
  KEY `idx_severity` (`severity`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_affected_entity_type` (`affected_entity_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- SECTION 2: Seed Data - ACL Groups and Permissions
-- =============================================================================

LOCK TABLES `acl_groups` WRITE;
INSERT IGNORE INTO `acl_groups` (`id`, `group_name`, `display_name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES (1,'administrator','Administrator','Full administrative access to all features',1,'2026-02-03 18:52:15','2026-02-03 18:52:15'),
(2,'cyber_tprm','Cyber TPRM','Cyber Third Party Risk Management team - can manage vendor assessments and FAIR analysis',1,'2026-02-03 18:52:15','2026-02-03 18:52:15'),
(3,'procurement','Procurement','Procurement team - can create and manage vendor onboarding requests',1,'2026-02-03 18:52:15','2026-02-03 18:52:15'),
(4,'stakeholder','Stakeholder','Stakeholders - can view and update assigned vendor onboarding requests',1,'2026-02-03 18:52:15','2026-02-03 18:52:15'),
(109,'auditor','Auditor','Read-only access to all modules. Cannot create, update, delete, or send.',1,'2026-03-04 16:56:58','2026-03-04 16:56:58'),
(166,'cyber_grc','Cyber GRC','GRC Compliance team members - full access to GRC module',1,'2026-03-09 13:56:08','2026-03-09 16:32:24'),
(167,'grc_contributors','GRC Contributors','GRC Contributors - IT and Compliance staff who provide evidence and complete assessment tasks',1,'2026-03-09 13:56:17','2026-03-09 16:32:24');
UNLOCK TABLES;
LOCK TABLES `acl_permissions` WRITE;
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
(21,'srs.rescore','Trigger Rescore','Manually trigger vendor rescoring','srs','score','update','2026-02-09 11:37:33'),
(359,'grc.frameworks.read','','View compliance frameworks and requirements','grc','frameworks','read','2026-03-09 13:56:08'),
(360,'grc.frameworks.manage','','Create and edit framework configurations','grc','frameworks','manage','2026-03-09 13:56:08'),
(361,'grc.controls.read','','View internal controls','grc','controls','read','2026-03-09 13:56:08'),
(362,'grc.controls.manage','','Create, edit, and map controls','grc','controls','manage','2026-03-09 13:56:08'),
(363,'grc.evidence.read','','View compliance evidence','grc','evidence','read','2026-03-09 13:56:08'),
(364,'grc.evidence.manage','','Upload and manage evidence','grc','evidence','manage','2026-03-09 13:56:08'),
(365,'grc.policies.read','','View policies','grc','policies','read','2026-03-09 13:56:08'),
(366,'grc.policies.manage','','Create, edit, publish policies','grc','policies','manage','2026-03-09 13:56:08'),
(367,'grc.audits.read','','View audit records and findings','grc','audits','read','2026-03-09 13:56:08'),
(368,'grc.audits.manage','','Create and manage audits','grc','audits','manage','2026-03-09 13:56:08'),
(369,'grc.monitors.read','','View continuous monitors','grc','monitors','read','2026-03-09 13:56:08'),
(370,'grc.monitors.manage','','Configure and run monitors','grc','monitors','manage','2026-03-09 13:56:08'),
(371,'grc.risks.read','','View risk register','grc','risks','read','2026-03-09 13:56:08'),
(372,'grc.risks.manage','','Manage risk register entries','grc','risks','manage','2026-03-09 13:56:08'),
(373,'grc.reports.read','','View and generate GRC reports','grc','reports','read','2026-03-09 13:56:08'),
(374,'grc.reports.export','','Export audit artifacts and certification drafts','grc','reports','export','2026-03-09 13:56:08'),
(375,'api.manage','','Manage API tokens and settings','api','tokens','manage','2026-03-09 13:56:08'),
(376,'api.test','','Access API testing playground','api','playground','test','2026-03-09 13:56:08'),
(377,'grc.assessments.read','','View unified assessments and FairScore results','grc','assessments','read','2026-03-09 13:56:17'),
(378,'grc.assessments.manage','','Create, edit, and manage unified assessments','grc','assessments','manage','2026-03-09 13:56:17'),
(379,'grc.assessments.validate','','Validate assessment responses','grc','assessments','validate','2026-03-09 13:56:17'),
(380,'grc.tasks.read','','View assigned assessment tasks','grc','tasks','read','2026-03-09 13:56:17'),
(381,'grc.tasks.manage','','Create and manage assessment tasks','grc','tasks','manage','2026-03-09 13:56:17'),
(382,'grc.tasks.complete','','Complete assigned tasks and provide evidence','grc','tasks','complete','2026-03-09 13:56:17'),
(383,'grc.domains.read','','View security domains and questions','grc','domains','read','2026-03-09 13:56:17'),
(384,'grc.domains.manage','','Manage security domains and unified questions','grc','domains','manage','2026-03-09 13:56:17'),
(385,'grc.maturity.read','','View maturity history and targets','grc','maturity','read','2026-03-09 13:56:17'),
(386,'grc.maturity.manage','','Set maturity targets and action plans','grc','maturity','manage','2026-03-09 13:56:17');
UNLOCK TABLES;
LOCK TABLES `acl_group_permissions` WRITE;
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
(109,20,'2026-03-04 16:57:22',NULL),
(109,359,'2026-03-09 13:56:08',NULL),
(109,361,'2026-03-09 13:56:08',NULL),
(109,363,'2026-03-09 13:56:08',NULL),
(109,365,'2026-03-09 13:56:08',NULL),
(109,367,'2026-03-09 13:56:08',NULL),
(109,369,'2026-03-09 13:56:08',NULL),
(109,371,'2026-03-09 13:56:08',NULL),
(109,373,'2026-03-09 13:56:08',NULL),
(109,377,'2026-03-09 13:56:17',NULL),
(109,379,'2026-03-09 13:56:17',NULL),
(109,380,'2026-03-09 13:56:17',NULL),
(109,383,'2026-03-09 13:56:17',NULL),
(109,385,'2026-03-09 13:56:17',NULL),
(166,359,'2026-03-09 13:56:08',NULL),
(166,360,'2026-03-09 13:56:08',NULL),
(166,361,'2026-03-09 13:56:08',NULL),
(166,362,'2026-03-09 13:56:08',NULL),
(166,363,'2026-03-09 13:56:08',NULL),
(166,364,'2026-03-09 13:56:08',NULL),
(166,365,'2026-03-09 13:56:08',NULL),
(166,366,'2026-03-09 13:56:08',NULL),
(166,367,'2026-03-09 13:56:08',NULL),
(166,368,'2026-03-09 13:56:08',NULL),
(166,369,'2026-03-09 13:56:08',NULL),
(166,370,'2026-03-09 13:56:08',NULL),
(166,371,'2026-03-09 13:56:08',NULL),
(166,372,'2026-03-09 13:56:08',NULL),
(166,373,'2026-03-09 13:56:08',NULL),
(166,374,'2026-03-09 13:56:08',NULL),
(166,377,'2026-03-09 13:56:17',NULL),
(166,378,'2026-03-09 13:56:17',NULL),
(166,379,'2026-03-09 13:56:17',NULL),
(166,380,'2026-03-09 13:56:17',NULL),
(166,381,'2026-03-09 13:56:17',NULL),
(166,382,'2026-03-09 13:56:17',NULL),
(166,383,'2026-03-09 13:56:17',NULL),
(166,384,'2026-03-09 13:56:17',NULL),
(166,385,'2026-03-09 13:56:17',NULL),
(166,386,'2026-03-09 13:56:17',NULL),
(167,359,'2026-03-09 13:56:17',NULL),
(167,361,'2026-03-09 13:56:17',NULL),
(167,363,'2026-03-09 13:56:17',NULL),
(167,364,'2026-03-09 13:56:17',NULL),
(167,365,'2026-03-09 13:56:17',NULL),
(167,367,'2026-03-09 13:56:17',NULL),
(167,369,'2026-03-09 13:56:17',NULL),
(167,371,'2026-03-09 13:56:17',NULL),
(167,373,'2026-03-09 13:56:17',NULL),
(167,377,'2026-03-09 13:56:17',NULL),
(167,380,'2026-03-09 13:56:17',NULL),
(167,382,'2026-03-09 13:56:17',NULL),
(167,383,'2026-03-09 13:56:17',NULL),
(167,385,'2026-03-09 13:56:17',NULL);
UNLOCK TABLES;

-- =============================================================================
-- SECTION 3: Seed Data - Default App Config (safe defaults for fresh installs)
-- =============================================================================

INSERT IGNORE INTO `app_config` (`config_key`, `config_value`) VALUES
('app_timezone', 'America/New_York'),
('app_version', 'v2.6.2'),
('auth_type', 'local'),
('local_login_enabled', '1'),
('break_glass_admin_username', 'admin'),
('company_name', 'FairTPRM'),
('org_self_domain', ''),
('app_url', 'http://localhost:8080'),
('email_enabled', '0'),
('email_method', 'smtp'),
('grc_enabled', '1'),
('fair_ai_enabled', '0'),
('fairscore_maturity_scale_max', '4'),
('fairscore_passing_threshold', '2.50'),
('session_timeout', '28800'),
('max_login_attempts', '10'),
('lockout_duration', '90'),
('log_retention_days', '90'),
('password_min_length', '12'),
('require_password_complexity', '1'),
('totp_issuer', 'TPRM FAIR Analysis'),
('nav_width', '220'),
('custom_scoring_enabled', '0'),
('assessment_auto_snapshot', '1'),
('assessment_require_validation', '1'),
('revalidation_tier1_frequency_days', '30'),
('revalidation_tier2_frequency_days', '90'),
('revalidation_tier3_frequency_days', '365'),
('revalidation_reminder_days', '7'),
('revalidation_escalation_days', '14'),
('fair_annual_revenue', '1000000000'),
('fair_revenue_cap_pct', '10'),
('pii_breach_cost_per_record', '160'),
('spii_breach_cost_per_record', '200'),
('sox_breach_penalty', '5000000'),
('shodan_enabled', '0'),
('shodan_display_name', 'SRS Scanner'),
('shodan_scoring_method', 'range'),
('shodan_max_score', '100'),
('shodan_grade_a_min', '90'),
('shodan_grade_b_min', '80'),
('shodan_grade_c_min', '70'),
('shodan_grade_d_min', '60'),
('shodan_max_subdomains', '1000'),
('shodan_banner_max_age', '90'),
('shodan_min_cve_year', '0'),
('shodan_randomize_subdomains', '1'),
('shodan_on_demand_scan', '0'),
('shodan_rescan_cooldown_hours', '24'),
('shodan_use_cron', '1'),
('shodan_category_weights', '{"tls_crypto":5,"network_security":15,"vuln_exposure":40,"email_security":20}'),
('upguard_enabled', '0'),
('upguard_display_name', 'UpGuard'),
('upguard_display_mode', 'percentage'),
('upguard_scoring_method', 'range'),
('upguard_max_score', '950'),
('upguard_grade_a_min', '90'),
('upguard_grade_b_min', '80'),
('upguard_grade_c_min', '70'),
('upguard_grade_d_min', '60'),
('upguard_tier1_days', '30'),
('upguard_tier2_days', '90'),
('upguard_tier3_days', '365'),
('upguard_trending_days', '365'),
('upguard_use_cron', '1'),
('upguard_vendor_domains', '1'),
('openwebui_enabled', '0'),
('openwebui_max_tokens', '4096'),
('openwebui_temperature', '0.3'),
('librechat_enabled', '0'),
('librechat_max_tokens', '500'),
('librechat_temperature', '0.7'),
('custom_enabled', '0'),
('custom_secret_header_name', 'x-app-secret'),
('procurement_notifications_enabled', '0'),
('procurement_notification_recipients', 'all'),
('procurement_notification_selected_users', '[]'),
('procurement_notification_warning_days', '30'),
('cron_timezone', 'America/New_York'),
('cron_annual_review_enabled', '1'),
('cron_annual_review_schedule', '0 2 * * *'),
('cron_assessment_reminders_enabled', '1'),
('cron_assessment_reminders_schedule', '0 4 * * *'),
('cron_contract_expiry_enabled', '1'),
('cron_contract_expiry_schedule', '0 3 * * *'),
('cron_grc_monitors_enabled', '0'),
('cron_grc_monitors_schedule', '*/5 * * * *'),
('cron_grc_policy_reminders_enabled', '1'),
('cron_grc_policy_reminders_schedule', '0 9 * * 2'),
('cron_ai_queue_enabled', '1'),
('cron_ai_queue_schedule', '* * * * *'),
('cron_rescore_queue_enabled', '1'),
('cron_rescore_queue_schedule', '* * * * *'),
('cron_srs_rescore_enabled', '1'),
('cron_srs_rescore_schedule', '0 * * * *'),
('cron_srs_rescore_batch_size', '20'),
('waf_mode', 'disabled'),
('waf_rate_limit_enabled', '1'),
('waf_rate_limit_requests', '1200'),
('waf_rate_limit_period', '60'),
('waf_rate_limit_block', '60'),
('waf_scanner_blocking', '0'),
('waf_audit_log_max_mb', '100'),
('breach_alert_enabled', '0'),
('breach_alert_recipients', ''),
('breach_alert_last_run', ''),
('cron_breach_monitor_enabled', '1'),
('cron_breach_monitor_schedule', '0 7 * * *');

-- Onboarding Scheduled Actions processor (Action Plan tab). Daily at 7 AM.
INSERT IGNORE INTO `app_config` (`config_key`, `config_value`) VALUES
('cron_onboarding_scheduled_actions_enabled', '1'),
('cron_onboarding_scheduled_actions_schedule', '0 7 * * *');

-- =============================================================================
-- Microsoft 365 Graph API email transport config (folded in from the former
-- standalone v2.5.8_graph_email.sql migration). Lets admins choose between SMTP
-- and Microsoft Graph for outbound email. Seeded empty; the client secret row
-- is flagged is_encrypted=1 so the app stores it encrypted once configured.
-- =============================================================================
INSERT IGNORE INTO `app_config` (`config_key`, `config_value`, `is_encrypted`) VALUES
('email_ms_tenant_id',     '', 0),
('email_ms_client_id',     '', 0),
('email_ms_client_secret', '', 1),
('email_ms_sender',        '', 0);

-- =============================================================================
-- Fix UpGuard grade thresholds: older installs seeded raw-score values
-- (850/700/500/300) which break percentage-mode grading. Only update if the
-- values still match the old wrong defaults - preserve user-customized values.
-- =============================================================================
UPDATE `app_config` SET `config_value` = '90' WHERE `config_key` = 'upguard_grade_a_min' AND `config_value` = '850';
UPDATE `app_config` SET `config_value` = '80' WHERE `config_key` = 'upguard_grade_b_min' AND `config_value` = '700';
UPDATE `app_config` SET `config_value` = '70' WHERE `config_key` = 'upguard_grade_c_min' AND `config_value` = '500';
UPDATE `app_config` SET `config_value` = '60' WHERE `config_key` = 'upguard_grade_d_min' AND `config_value` = '300';


-- =============================================================================
-- SECTION 4: Seed Data - GRC Security Domains, Frameworks, and Requirements
-- =============================================================================

LOCK TABLES `grc_security_domains` WRITE;
INSERT IGNORE INTO `grc_security_domains` (`id`, `domain_code`, `name`, `description`, `icon`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES (1,'GOV','Governance & Leadership','Organizational governance, security leadership, policies, roles, responsibilities, and board oversight','shield-01.svg',1,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(2,'IAM','Identity & Access Management','User authentication, authorization, privileged access, credential management, and access reviews','users-01.svg',2,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(3,'DSP','Data Security & Privacy','Data classification, encryption, DLP, privacy controls, and data lifecycle management','lock-01.svg',3,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(4,'EPS','Endpoint & Platform Security','Endpoint protection, OS hardening, mobile device management, and workstation security','monitor-01.svg',4,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(5,'NET','Network Security','Network segmentation, firewalls, IDS/IPS, VPN, wireless security, and DNS protection','globe-01.svg',5,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(6,'APS','Application Security','Secure SDLC, code review, vulnerability scanning, WAF, API security, and change management','code-01.svg',6,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(7,'OPS','Security Operations','SIEM, SOC, log management, threat detection, vulnerability management, and patching','activity-01.svg',7,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(8,'INC','Incident Management','Incident response planning, detection, containment, eradication, recovery, and lessons learned','alert-triangle.svg',8,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(9,'SCM','Supply Chain & Third Party','Vendor risk management, third-party assessments, SLA monitoring, and supply chain security','link-01.svg',9,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(10,'PHY','Physical & Environmental','Physical access controls, CCTV, environmental controls, data center security, and visitor management','building-01.svg',10,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(11,'HRS','Human Resources Security','Background checks, security awareness training, onboarding/offboarding, and acceptable use','user-check-01.svg',11,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(12,'BCP','Business Continuity','BCP/DR planning, backup strategy, RTO/RPO, testing, and crisis communication','refresh-ccw-01.svg',12,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(13,'CRY','Cryptography & Key Management','Encryption standards, key lifecycle, certificate management, and cryptographic controls','key-01.svg',13,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(14,'CMP','Compliance & Assurance','Regulatory compliance tracking, audit readiness, legal requirements, and management review','clipboard-check.svg',14,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(15,'AIG','AI Governance & Trustworthiness','AI system lifecycle governance, risk management for AI/ML systems, fairness, bias, transparency, explainability, accountability, and AI-specific third-party risk. Aligned with NIST AI RMF 1.0 (AI 100-1).','cpu-01.svg',15,1,'2026-03-13 03:52:33','2026-03-13 03:52:33');
UNLOCK TABLES;
LOCK TABLES `grc_frameworks` WRITE;
INSERT IGNORE INTO `grc_frameworks` (`id`, `code`, `name`, `version`, `description`, `scope_id`, `framework_url`, `has_maturity_levels`, `max_maturity_level`, `is_active`, `compliance_year`, `sort_order`, `created_by`, `created_at`, `updated_at`, `generated_from`) VALUES (1,'SOC2','SOC 2 Type II','2017','Service Organization Control 2 - Trust Services Criteria covering Security, Availability, Processing Integrity, Confidentiality, and Privacy.',NULL,NULL,0,NULL,1,2026,1,NULL,'2026-03-09 13:56:08','2026-03-09 14:03:40','SOC2'),
(2,'ISO27001','ISO/IEC 27001','2022','Information security management systems (ISMS) - Requirements. The 2022 version restructures controls into 4 themes: Organizational, People, Physical, and Technological.',NULL,NULL,0,NULL,1,2026,2,NULL,'2026-03-09 13:56:08','2026-03-09 14:03:40','ISO27001'),
(3,'SOX','Sarbanes-Oxley Act','2002','US federal law mandating financial reporting controls, IT general controls (ITGC), and internal control over financial reporting (ICFR).',NULL,NULL,0,NULL,1,2026,3,NULL,'2026-03-09 13:56:08','2026-03-09 14:03:40','SOX'),
(4,'PCI','PCI DSS','v4.0.1','Payment Card Industry Data Security Standard - Requirements for organizations that handle cardholder data.',NULL,NULL,0,NULL,1,2026,4,NULL,'2026-03-09 13:56:08','2026-03-09 16:12:45',NULL),
(5,'CSF','NIST Cybersecurity Framework','2.0','Voluntary framework for managing cybersecurity risk. Organized into six functions: Govern, Identify, Protect, Detect, Respond, Recover.',NULL,NULL,0,NULL,1,2026,5,NULL,'2026-03-09 13:56:08','2026-03-09 14:03:40',NULL),
(6,'CMMC','CMMC / NIST 800-171','v2.0','Cybersecurity Maturity Model Certification based on NIST SP 800-171 Rev 2 for protecting Controlled Unclassified Information (CUI) in the Defense Industrial Base.',NULL,NULL,1,3,1,2026,6,NULL,'2026-03-09 13:56:08','2026-03-09 14:03:40','CMMC'),
(7,'NIST-171','NIST SP 800-171 Rev 2','Rev 2','Protecting Controlled Unclassified Information in Nonfederal Systems and Organizations',NULL,'https://csrc.nist.gov/publications/detail/sp/800-171/rev-2/final',0,NULL,1,2026,0,NULL,'2026-03-09 13:56:17','2026-03-09 14:03:40','NIST-171'),
(8,'NIST-CSF','NIST Cybersecurity Framework 2.0','2.0','Framework for improving critical infrastructure cybersecurity',NULL,'https://www.nist.gov/cyberframework',0,NULL,1,2026,0,NULL,'2026-03-09 13:56:17','2026-03-09 14:03:40','NIST-CSF'),
(9,'PCI-DSS','PCI DSS 4.0','4.0','Payment Card Industry Data Security Standard',NULL,'https://www.pcisecuritystandards.org/document_library/',0,NULL,1,2026,0,NULL,'2026-03-09 13:56:17','2026-03-09 14:03:40','PCI-DSS'),
(10,'HIPAA','HIPAA Security Rule','2013','Health Insurance Portability and Accountability Act',NULL,NULL,0,NULL,1,2026,0,NULL,'2026-03-09 13:56:22','2026-03-09 14:03:40',NULL),
(11,'CIS','CIS Controls v8','v8','Center for Internet Security Critical Security Controls',NULL,NULL,0,NULL,1,2026,0,NULL,'2026-03-09 13:56:22','2026-03-09 14:03:40',NULL),
(12,'AI-RMF','NIST AI Risk Management Framework','1.0','Voluntary framework for managing risks associated with AI systems throughout their lifecycle. Organized into four core functions: Govern, Map, Measure, and Manage. Published as NIST AI 100-1.',NULL,'https://www.nist.gov/itl/ai-risk-management-framework',0,NULL,1,2026,12,NULL,'2026-03-13 03:52:55','2026-03-13 03:56:02','AI-RMF');
UNLOCK TABLES;
LOCK TABLES `grc_framework_requirements` WRITE;
INSERT IGNORE INTO `grc_framework_requirements` (`id`, `framework_id`, `parent_id`, `requirement_ref`, `title`, `description`, `guidance`, `maturity_level`, `is_required`, `sort_order`, `created_at`, `updated_at`) VALUES (1,1,NULL,'CC1.1','Security Governance - Integrity and Ethical Values','The entity demonstrates commitment to integrity and ethical values across the organization. Board and management establish a security-conscious culture, including codes of conduct, ethics policies, and whistleblower mechanisms.',NULL,NULL,1,1,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(2,1,NULL,'CC1.2','Security Governance - Board Oversight of Cybersecurity','The board of directors exercises independent oversight of cybersecurity and internal control programs. The board reviews security posture, risk assessments, audit findings, and ensures management implements effective security controls.',NULL,NULL,1,2,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(3,1,NULL,'CC1.3','Organizational Structure and Security Responsibilities','Management establishes clear organizational structures, reporting lines, and defined security roles and responsibilities (e.g., CISO, security team, data owners).',NULL,NULL,1,3,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(4,1,NULL,'CC1.4','Security Personnel Competence and Training','The entity attracts, develops, and retains competent security personnel. This includes security awareness training, technical skills development, background checks, and ongoing education.',NULL,NULL,1,4,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(5,1,NULL,'CC1.5','Security Accountability and Performance Evaluation','The entity holds individuals accountable for their security responsibilities through performance evaluations, disciplinary procedures, and incentive structures.',NULL,NULL,1,5,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(6,1,NULL,'CC2.1','Security Information Quality and Relevance','The entity obtains and generates relevant, quality security information (threat intelligence, vulnerability data, log data, compliance reports) to support security controls.',NULL,NULL,1,6,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(7,1,NULL,'CC2.2','Internal Security Communication and Awareness','The entity communicates security policies, procedures, responsibilities, and threats to internal personnel including security awareness programs and escalation procedures.',NULL,NULL,1,7,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(8,1,NULL,'CC2.3','External Security Communication and Reporting','The entity communicates security commitments, responsibilities, and incidents to external parties including customers, regulators, and partners.',NULL,NULL,1,8,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(9,1,NULL,'CC3.1','Security Objectives and Risk Appetite Definition','The entity defines security objectives aligned with business strategy and establishes risk tolerance levels for cybersecurity risk management.',NULL,NULL,1,9,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(10,1,NULL,'CC3.2','Cybersecurity Risk Identification and Analysis','The entity performs comprehensive cybersecurity risk assessments including threat modeling, vulnerability assessments, and impact analysis.',NULL,NULL,1,10,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(11,1,NULL,'CC3.3','Insider Threat and Fraud Risk Assessment','The entity assesses risks from insider threats, social engineering, and fraud including privileged users, contractors, business email compromise, and phishing.',NULL,NULL,1,11,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(12,1,NULL,'CC3.4','Threat Landscape and Change Impact Analysis','The entity monitors emerging threats, new vulnerabilities, and significant changes to the technology environment that could impact security posture.',NULL,NULL,1,12,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(13,1,NULL,'CC4.1','Continuous Security Monitoring and Assessment','The entity implements continuous security monitoring, periodic assessments, penetration testing, and vulnerability scanning to evaluate security control effectiveness.',NULL,NULL,1,13,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(14,1,NULL,'CC4.2','Security Deficiency Reporting and Remediation Tracking','The entity identifies, evaluates, and communicates security control deficiencies to responsible parties with tracked remediation and verification.',NULL,NULL,1,14,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(15,1,NULL,'CC5.1','Security Control Design and Implementation','The entity selects and implements preventive, detective, and corrective security controls including network segmentation, encryption, access controls, and endpoint protection.',NULL,NULL,1,15,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(16,1,NULL,'CC5.2','IT General Controls and Technology Security','The entity implements IT general controls over technology infrastructure including configuration management, patch management, database security, and OS hardening.',NULL,NULL,1,16,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(17,1,NULL,'CC5.3','Security Policies, Standards, and Procedures','The entity maintains documented security policies, standards, and procedures including acceptable use, data classification, incident response, and access management.',NULL,NULL,1,17,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(18,1,NULL,'CC6.1','Identity and Access Management Infrastructure','The entity implements IAM infrastructure including authentication systems, directory services, SSO, and multi-factor authentication (MFA).',NULL,NULL,1,18,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(19,1,NULL,'CC6.2','User Provisioning and Deprovisioning','The entity controls user lifecycle management including registration, provisioning, modification, and deprovisioning of user accounts.',NULL,NULL,1,19,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(20,1,NULL,'CC6.3','Least Privilege, RBAC, and Segregation of Duties','The entity enforces least privilege access, role-based access control, and segregation of duties with periodic access reviews.',NULL,NULL,1,20,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(21,1,NULL,'CC6.4','Physical Security and Data Center Controls','The entity restricts physical access to data centers and sensitive areas using badge access, biometrics, CCTV, visitor logs, and environmental controls.',NULL,NULL,1,21,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(22,1,NULL,'CC6.5','Secure Data and Asset Disposal','The entity implements secure disposal procedures for hardware, media, and data including cryptographic erasure and physical destruction.',NULL,NULL,1,22,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(23,1,NULL,'CC6.6','Network Security and Perimeter Defense','The entity implements firewalls, IDS/IPS, WAF, DDoS protection, and network segmentation to protect against external threats.',NULL,NULL,1,23,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(24,1,NULL,'CC6.7','Data Protection in Transit and Data Loss Prevention','The entity protects data during transmission using TLS/SSL encryption, VPN, and DLP controls to restrict unauthorized data movement.',NULL,NULL,1,24,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(25,1,NULL,'CC6.8','Malware Prevention and Endpoint Security','The entity deploys EPP/EDR, anti-malware, application whitelisting, and email security gateways to prevent and detect malicious software.',NULL,NULL,1,25,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(26,1,NULL,'CC7.1','Vulnerability Management and Configuration Monitoring','The entity implements vulnerability scanning, configuration assessment, and baseline monitoring to detect misconfigurations and unauthorized changes.',NULL,NULL,1,26,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(27,1,NULL,'CC7.2','Security Event Monitoring and SIEM','The entity monitors systems using SIEM, log aggregation, behavioral analytics, and anomaly detection to identify indicators of compromise.',NULL,NULL,1,27,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(28,1,NULL,'CC7.3','Security Event Triage and Incident Determination','The entity evaluates and triages security events including event correlation, threat intelligence enrichment, severity classification, and escalation.',NULL,NULL,1,28,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(29,1,NULL,'CC7.4','Incident Response Program and Execution','The entity maintains a formal incident response plan with defined roles, communication protocols, containment strategies, and forensic procedures.',NULL,NULL,1,29,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(30,1,NULL,'CC7.5','Incident Recovery and Post-Incident Analysis','The entity implements incident recovery procedures including root cause analysis, system restoration, evidence preservation, and corrective actions.',NULL,NULL,1,30,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(31,1,NULL,'CC8.1','Secure Change Management and SDLC','The entity implements secure change management including change authorization, impact assessment, security testing, code review, and environment segregation.',NULL,NULL,1,31,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(32,1,NULL,'CC9.1','Business Continuity and Disaster Recovery','The entity implements BCP/DRP, cyber insurance, redundant systems, and failover mechanisms for business disruption risk mitigation.',NULL,NULL,1,32,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(33,1,NULL,'CC9.2','Third-Party Risk Management and Vendor Security','The entity assesses vendor cybersecurity risks through due diligence, security assessments, contractual requirements, and ongoing monitoring.',NULL,NULL,1,33,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(34,1,NULL,'A1.1','Infrastructure Capacity and Availability Monitoring','The entity monitors processing capacity, system availability, and performance to ensure systems handle current and projected workloads.',NULL,NULL,1,34,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(35,1,NULL,'A1.2','Backup, Recovery, and Environmental Protections','The entity implements data backup processes, recovery infrastructure, and environmental protections to support RPO/RTO objectives.',NULL,NULL,1,35,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(36,1,NULL,'A1.3','Disaster Recovery Testing and Validation','The entity regularly tests DR/BCP procedures through tabletop exercises, failover tests, and full recovery simulations.',NULL,NULL,1,36,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(37,1,NULL,'C1.1','Data Classification and Confidentiality Controls','The entity identifies, classifies, and protects confidential information using data classification, encryption at rest, data masking, and monitoring.',NULL,NULL,1,37,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(38,1,NULL,'C1.2','Secure Data Disposal and Retention Management','The entity implements data retention policies and secure disposal procedures including cryptographic erasure and physical media destruction.',NULL,NULL,1,38,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(39,1,NULL,'PI1.1','Processing Integrity Policies and Quality Assurance','The entity defines processing specifications, data quality standards, and integrity requirements with QA verification procedures.',NULL,NULL,1,39,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(40,1,NULL,'PI1.2','Input Validation and Data Integrity Controls','The entity implements input validation including data type checking, range validation, completeness checks, and error handling.',NULL,NULL,1,40,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(41,1,NULL,'PI1.3','Accurate and Authorized Data Processing','The entity ensures processing is complete, accurate, timely, and authorized through automated validation and reconciliation.',NULL,NULL,1,41,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(42,1,NULL,'PI1.4','Output Completeness and Accuracy Verification','The entity verifies system outputs are complete, accurate, and distributed to authorized recipients with reconciliation controls.',NULL,NULL,1,42,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(43,1,NULL,'PI1.5','Data Storage Integrity and Retention Controls','The entity maintains stored data integrity using checksums, database constraints, audit trails, immutable logging, and retention policies.',NULL,NULL,1,43,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(44,1,NULL,'P1.1','Privacy Notice and Consent Management','The entity provides privacy notices to data subjects and obtains consent where required by regulation.',NULL,NULL,0,44,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(45,1,NULL,'P1.2','Personal Information Collection and Purpose Limitation','The entity collects personal information only for identified purposes with data minimization practices.',NULL,NULL,0,45,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(46,1,NULL,'P1.3','Personal Information Use, Retention, and Disposal','The entity limits use and retention of personal information to identified purposes and securely disposes when no longer needed.',NULL,NULL,0,46,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(47,1,NULL,'P1.4','Data Subject Access and Correction Rights','The entity provides data subjects with access to their personal information and handles access, correction, and deletion requests.',NULL,NULL,0,47,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(48,1,NULL,'P1.5','Personal Information Disclosure and Third-Party Sharing','The entity discloses personal information to third parties only for identified purposes with data processing agreements.',NULL,NULL,0,48,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(49,1,NULL,'P1.6','Privacy and Security of Personal Information','The entity implements technical and organizational safeguards including encryption and access controls to protect personal information.',NULL,NULL,0,49,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(50,1,NULL,'P1.7','Privacy Program Quality and Compliance Monitoring','The entity monitors privacy compliance through assessments, audits, and privacy impact assessments for new systems.',NULL,NULL,0,50,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(51,1,NULL,'P1.8','Privacy Breach Response and Notification','The entity implements privacy breach response procedures including detection, notification, and remediation.',NULL,NULL,0,51,'2026-03-09 13:56:08','2026-03-09 13:56:08'),
(52,7,NULL,'3.1.1','Limit system access to authorized users','Limit system access to authorized users, processes acting on behalf of authorized users, and devices (including other systems).',NULL,NULL,1,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(53,7,NULL,'3.1.2','Limit system access to authorized functions','Limit system access to the types of transactions and functions that authorized users are permitted to execute.',NULL,NULL,1,2,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(54,7,NULL,'3.1.3','Control CUI flow','Control the flow of CUI in accordance with approved authorizations.',NULL,NULL,1,3,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(55,7,NULL,'3.1.4','Separate duties of individuals','Separate the duties of individuals to reduce the risk of malevolent activity without collusion.',NULL,NULL,1,4,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(56,7,NULL,'3.1.5','Employ least privilege','Employ the principle of least privilege, including for specific security functions and privileged accounts.',NULL,NULL,1,5,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(57,7,NULL,'3.1.6','Use non-privileged accounts','Use non-privileged accounts or roles when accessing nonsecurity functions.',NULL,NULL,1,6,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(58,7,NULL,'3.1.7','Prevent non-privileged users from executing privileged functions','Prevent non-privileged users from executing privileged functions and capture the execution of such functions in audit logs.',NULL,NULL,1,7,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(59,7,NULL,'3.1.8','Limit unsuccessful logon attempts','Limit unsuccessful logon attempts.',NULL,NULL,1,8,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(60,7,NULL,'3.1.9','Provide privacy and security notices','Provide privacy and security notices consistent with applicable CUI rules.',NULL,NULL,1,9,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(61,7,NULL,'3.1.10','Use session lock','Use session lock with pattern-hiding displays to prevent access and viewing of data after a period of inactivity.',NULL,NULL,1,10,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(62,7,NULL,'3.1.11','Terminate sessions','Terminate (automatically) a user session after a defined condition.',NULL,NULL,1,11,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(63,7,NULL,'3.1.12','Control remote access','Monitor and control remote access sessions.',NULL,NULL,1,12,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(64,7,NULL,'3.1.13','Employ cryptographic mechanisms for remote access','Employ cryptographic mechanisms to protect the confidentiality of remote access sessions.',NULL,NULL,1,13,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(65,7,NULL,'3.1.14','Route remote access via managed access control points','Route remote access via managed access control points.',NULL,NULL,1,14,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(66,7,NULL,'3.1.15','Authorize remote execution','Authorize remote execution of privileged commands and remote access to security-relevant information.',NULL,NULL,1,15,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(67,7,NULL,'3.1.16','Authorize wireless access','Authorize wireless access prior to allowing such connections.',NULL,NULL,1,16,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(68,7,NULL,'3.1.17','Protect wireless access using authentication and encryption','Protect wireless access using authentication and encryption.',NULL,NULL,1,17,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(69,7,NULL,'3.1.18','Control connection of mobile devices','Control connection of mobile devices.',NULL,NULL,1,18,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(70,7,NULL,'3.1.19','Encrypt CUI on mobile devices','Encrypt CUI on mobile devices and mobile computing platforms.',NULL,NULL,1,19,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(71,7,NULL,'3.1.20','Verify and control connections to external systems','Verify and control/limit connections to and use of external systems.',NULL,NULL,1,20,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(72,7,NULL,'3.1.21','Limit use of portable storage devices','Limit use of portable storage devices on external systems.',NULL,NULL,1,21,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(73,7,NULL,'3.1.22','Control CUI posted on publicly accessible systems','Control CUI posted or processed on publicly accessible systems.',NULL,NULL,1,22,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(74,7,NULL,'3.2.1','Ensure security awareness','Ensure that managers, systems administrators, and users of organizational systems are made aware of the security risks associated with their activities and of the applicable policies, standards, and procedures related to the security of those systems.',NULL,NULL,1,23,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(75,7,NULL,'3.2.2','Ensure training on information security','Ensure that personnel are trained to carry out their assigned information security-related duties and responsibilities.',NULL,NULL,1,24,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(76,7,NULL,'3.2.3','Provide insider threat awareness','Provide security awareness training on recognizing and reporting potential indicators of insider threat.',NULL,NULL,1,25,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(77,7,NULL,'3.3.1','Create and retain system audit logs','Create and retain system audit logs and records to the extent needed to enable the monitoring, analysis, investigation, and reporting of unlawful or unauthorized system activity.',NULL,NULL,1,26,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(78,7,NULL,'3.3.2','Ensure actions are uniquely traced to users','Ensure that the actions of individual system users can be uniquely traced to those users so they can be held accountable for their actions.',NULL,NULL,1,27,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(79,7,NULL,'3.3.3','Review and update logged events','Review and update logged events.',NULL,NULL,1,28,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(80,7,NULL,'3.3.4','Alert on audit logging process failures','Alert in the event of an audit logging process failure.',NULL,NULL,1,29,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(81,7,NULL,'3.3.5','Correlate audit record review and reporting','Correlate audit record review, analysis, and reporting processes for investigation and response to indications of unlawful, unauthorized, suspicious, or unusual activity.',NULL,NULL,1,30,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(82,7,NULL,'3.3.6','Provide audit record reduction and report generation','Provide audit record reduction and report generation to support on-demand analysis and reporting.',NULL,NULL,1,31,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(83,7,NULL,'3.3.7','Provide system capability to compare and synchronize clocks','Provide a system capability that compares and synchronizes internal system clocks with an authoritative source to generate time stamps for audit records.',NULL,NULL,1,32,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(84,7,NULL,'3.3.8','Protect audit information','Protect audit information and audit logging tools from unauthorized access, modification, and deletion.',NULL,NULL,1,33,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(85,7,NULL,'3.3.9','Limit management of audit logging functionality','Limit management of audit logging functionality to a subset of privileged users.',NULL,NULL,1,34,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(86,7,NULL,'3.4.1','Establish and maintain baseline configurations','Establish and maintain baseline configurations and inventories of organizational systems (including hardware, software, firmware, and documentation) throughout the respective system development life cycles.',NULL,NULL,1,35,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(87,7,NULL,'3.4.2','Establish and enforce security configuration settings','Establish and enforce security configuration settings for information technology products employed in organizational systems.',NULL,NULL,1,36,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(88,7,NULL,'3.4.3','Track, review, and control changes','Track, review, approve or disapprove, and log changes to organizational systems.',NULL,NULL,1,37,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(89,7,NULL,'3.4.4','Analyze security impact of changes','Analyze the security impact of changes prior to implementation.',NULL,NULL,1,38,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(90,7,NULL,'3.4.5','Define and enforce physical and logical access restrictions','Define, document, approve, and enforce physical and logical access restrictions associated with changes to organizational systems.',NULL,NULL,1,39,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(91,7,NULL,'3.4.6','Employ least functionality','Employ the principle of least functionality by configuring organizational systems to provide only essential capabilities.',NULL,NULL,1,40,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(92,7,NULL,'3.4.7','Restrict, disable, or prevent nonessential programs','Restrict, disable, or prevent the use of nonessential programs, functions, ports, protocols, and services.',NULL,NULL,1,41,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(93,7,NULL,'3.4.8','Apply deny-by-exception policy','Apply deny-by-exception (blacklisting) policy to prevent the use of unauthorized software or deny-all, permit-by-exception (whitelisting) policy to allow the execution of authorized software.',NULL,NULL,1,42,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(94,7,NULL,'3.4.9','Control and monitor user-installed software','Control and monitor user-installed software.',NULL,NULL,1,43,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(95,7,NULL,'3.5.1','Identify system users and processes','Identify system users, processes acting on behalf of users, and devices.',NULL,NULL,1,44,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(96,7,NULL,'3.5.2','Authenticate users, processes, and devices','Authenticate (or verify) the identities of users, processes, or devices, as a prerequisite to allowing access to organizational systems.',NULL,NULL,1,45,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(97,7,NULL,'3.5.3','Use multifactor authentication','Use multifactor authentication for local and network access to privileged accounts and for network access to non-privileged accounts.',NULL,NULL,1,46,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(98,7,NULL,'3.5.4','Employ replay-resistant authentication','Employ replay-resistant authentication mechanisms for network access to privileged and non-privileged accounts.',NULL,NULL,1,47,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(99,7,NULL,'3.5.5','Prevent reuse of identifiers','Prevent reuse of identifiers for a defined period.',NULL,NULL,1,48,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(100,7,NULL,'3.5.6','Disable identifiers after inactivity','Disable identifiers after a defined period of inactivity.',NULL,NULL,1,49,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(101,7,NULL,'3.5.7','Enforce minimum password complexity','Enforce a minimum password complexity and change of characters when new passwords are created.',NULL,NULL,1,50,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(102,7,NULL,'3.5.8','Prohibit password reuse','Prohibit password reuse for a specified number of generations.',NULL,NULL,1,51,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(103,7,NULL,'3.5.9','Allow temporary password use for logons','Allow temporary password use for system logons with an immediate change to a permanent password.',NULL,NULL,1,52,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(104,7,NULL,'3.5.10','Store and transmit only cryptographically-protected passwords','Store and transmit only cryptographically-protected passwords.',NULL,NULL,1,53,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(105,7,NULL,'3.5.11','Obscure feedback of authentication information','Obscure feedback of authentication information.',NULL,NULL,1,54,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(106,7,NULL,'3.6.1','Establish incident handling capability','Establish an operational incident-handling capability for organizational systems that includes preparation, detection, analysis, containment, recovery, and user response activities.',NULL,NULL,1,55,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(107,7,NULL,'3.6.2','Track, document, and report incidents','Track, document, and report incidents to designated officials and/or authorities both internal and external to the organization.',NULL,NULL,1,56,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(108,7,NULL,'3.6.3','Test incident response capability','Test the organizational incident response capability.',NULL,NULL,1,57,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(109,7,NULL,'3.7.1','Perform maintenance on organizational systems','Perform maintenance on organizational systems.',NULL,NULL,1,58,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(110,7,NULL,'3.7.2','Provide controls on maintenance tools','Provide controls on the tools, techniques, mechanisms, and personnel used to conduct system maintenance.',NULL,NULL,1,59,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(111,7,NULL,'3.7.3','Ensure equipment removed for maintenance is sanitized','Ensure equipment removed for off-site maintenance is sanitized of any CUI.',NULL,NULL,1,60,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(112,7,NULL,'3.7.4','Check media containing diagnostic programs','Check media containing diagnostic and test programs for malicious code before the media are used in organizational systems.',NULL,NULL,1,61,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(113,7,NULL,'3.7.5','Require multifactor authentication for nonlocal maintenance','Require multifactor authentication to establish nonlocal maintenance sessions via external network connections and terminate such connections when nonlocal maintenance is complete.',NULL,NULL,1,62,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(114,7,NULL,'3.7.6','Supervise maintenance activities','Supervise the maintenance activities of maintenance personnel without required access authorization.',NULL,NULL,1,63,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(115,7,NULL,'3.8.1','Protect system media containing CUI','Protect (i.e., physically control and securely store) system media containing CUI, both paper and digital.',NULL,NULL,1,64,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(116,7,NULL,'3.8.2','Limit access to CUI on system media','Limit access to CUI on system media to authorized users.',NULL,NULL,1,65,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(117,7,NULL,'3.8.3','Sanitize or destroy system media before disposal','Sanitize or destroy system media containing CUI before disposal or release for reuse.',NULL,NULL,1,66,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(118,7,NULL,'3.8.4','Mark media with CUI markings','Mark media with necessary CUI markings and distribution limitations.',NULL,NULL,1,67,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(119,7,NULL,'3.8.5','Control access to media containing CUI','Control access to media containing CUI and maintain accountability for media during transport outside of controlled areas.',NULL,NULL,1,68,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(120,7,NULL,'3.8.6','Implement cryptographic mechanisms during transport','Implement cryptographic mechanisms to protect the confidentiality of CUI stored on digital media during transport unless otherwise protected by alternative physical safeguards.',NULL,NULL,1,69,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(121,7,NULL,'3.8.7','Control use of removable media','Control the use of removable media on system components.',NULL,NULL,1,70,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(122,7,NULL,'3.8.8','Prohibit use of portable storage without owner','Prohibit the use of portable storage devices when such devices have no identifiable owner.',NULL,NULL,1,71,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(123,7,NULL,'3.8.9','Protect backup CUI at storage locations','Protect the confidentiality of backup CUI at storage locations.',NULL,NULL,1,72,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(124,7,NULL,'3.9.1','Screen individuals prior to access','Screen individuals prior to authorizing access to organizational systems containing CUI.',NULL,NULL,1,73,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(125,7,NULL,'3.9.2','Protect CUI during personnel actions','Ensure that organizational systems containing CUI are protected during and after personnel actions such as terminations and transfers.',NULL,NULL,1,74,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(126,7,NULL,'3.10.1','Limit physical access to organizational systems','Limit physical access to organizational systems, equipment, and the respective operating environments to authorized individuals.',NULL,NULL,1,75,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(127,7,NULL,'3.10.2','Protect and monitor the physical facility','Protect and monitor the physical facility and support infrastructure for organizational systems.',NULL,NULL,1,76,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(128,7,NULL,'3.10.3','Escort visitors','Escort visitors and monitor visitor activity.',NULL,NULL,1,77,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(129,7,NULL,'3.10.4','Maintain audit logs of physical access','Maintain audit logs of physical access.',NULL,NULL,1,78,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(130,7,NULL,'3.10.5','Control and manage physical access devices','Control and manage physical access devices.',NULL,NULL,1,79,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(131,7,NULL,'3.10.6','Enforce safeguarding measures at alternate work sites','Enforce safeguarding measures for CUI at alternate work sites.',NULL,NULL,1,80,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(132,7,NULL,'3.11.1','Periodically assess risk','Periodically assess the risk to organizational operations (including mission, functions, image, or reputation), organizational assets, and individuals, resulting from the operation of organizational systems and the associated processing, storage, or transmission of CUI.',NULL,NULL,1,81,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(133,7,NULL,'3.11.2','Scan for vulnerabilities','Scan for vulnerabilities in organizational systems and applications periodically and when new vulnerabilities affecting those systems and applications are identified.',NULL,NULL,1,82,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(134,7,NULL,'3.11.3','Remediate vulnerabilities','Remediate vulnerabilities in accordance with risk assessments.',NULL,NULL,1,83,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(135,7,NULL,'3.12.1','Periodically assess security controls','Periodically assess the security controls in organizational systems to determine if the controls are effective in their application.',NULL,NULL,1,84,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(136,7,NULL,'3.12.2','Develop and implement plans of action','Develop and implement plans of action designed to correct deficiencies and reduce or eliminate vulnerabilities in organizational systems.',NULL,NULL,1,85,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(137,7,NULL,'3.12.3','Monitor security controls on an ongoing basis','Monitor security controls on an ongoing basis to ensure the continued effectiveness of the controls.',NULL,NULL,1,86,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(138,7,NULL,'3.12.4','Develop and update system security plans','Develop, document, and periodically update system security plans that describe system boundaries, system environments of operation, how security requirements are implemented, and the relationships with or connections to other systems.',NULL,NULL,1,87,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(139,7,NULL,'3.13.1','Monitor communications at external boundaries','Monitor, control, and protect communications (i.e., information transmitted or received by organizational systems) at the external boundaries and key internal boundaries of organizational systems.',NULL,NULL,1,88,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(140,7,NULL,'3.13.2','Employ architectural designs to promote security','Employ architectural designs, software development techniques, and systems engineering principles that promote effective information security within organizational systems.',NULL,NULL,1,89,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(141,7,NULL,'3.13.3','Separate user functionality from management functionality','Separate user functionality from system management functionality.',NULL,NULL,1,90,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(142,7,NULL,'3.13.4','Prevent unauthorized and unintended information transfer','Prevent unauthorized and unintended information transfer via shared system resources.',NULL,NULL,1,91,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(143,7,NULL,'3.13.5','Implement subnetworks for publicly accessible components','Implement subnetworks for publicly accessible system components that are physically or logically separated from internal networks.',NULL,NULL,1,92,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(144,7,NULL,'3.13.6','Deny network communications by default','Deny network communications traffic by default and allow network communications traffic by exception (i.e., deny all, permit by exception).',NULL,NULL,1,93,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(145,7,NULL,'3.13.7','Prevent remote activation of collaborative computing devices','Prevent remote devices from simultaneously establishing non-remote connections with organizational systems and communicating via some other connection to resources in external networks (i.e., split tunneling).',NULL,NULL,1,94,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(146,7,NULL,'3.13.8','Implement cryptographic mechanisms to prevent unauthorized disclosure','Implement cryptographic mechanisms to prevent unauthorized disclosure of CUI during transmission unless otherwise protected by alternative physical safeguards.',NULL,NULL,1,95,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(147,7,NULL,'3.13.9','Terminate network connections','Terminate network connections associated with communications sessions at the end of the sessions or after a defined period of inactivity.',NULL,NULL,1,96,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(148,7,NULL,'3.13.10','Establish and manage cryptographic keys','Establish and manage cryptographic keys for cryptography employed in organizational systems.',NULL,NULL,1,97,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(149,7,NULL,'3.13.11','Employ FIPS-validated cryptography','Employ FIPS-validated cryptography when used to protect the confidentiality of CUI.',NULL,NULL,1,98,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(150,7,NULL,'3.13.12','Prohibit remote activation of collaborative computing devices','Prohibit remote activation of collaborative computing devices and provide indication of devices in use to users present at the device.',NULL,NULL,1,99,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(151,7,NULL,'3.13.13','Control and monitor the use of mobile code','Control and monitor the use of mobile code.',NULL,NULL,1,100,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(152,7,NULL,'3.13.14','Control and monitor the use of VoIP','Control and monitor the use of Voice over Internet Protocol (VoIP) technologies.',NULL,NULL,1,101,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(153,7,NULL,'3.13.15','Protect authenticity of communications sessions','Protect the authenticity of communications sessions.',NULL,NULL,1,102,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(154,7,NULL,'3.13.16','Protect CUI at rest','Protect the confidentiality of CUI at rest.',NULL,NULL,1,103,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(155,7,NULL,'3.14.1','Identify, report, and correct flaws in a timely manner','Identify, report, and correct information and system flaws in a timely manner.',NULL,NULL,1,104,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(156,7,NULL,'3.14.2','Provide protection from malicious code','Provide protection from malicious code at appropriate locations within organizational systems.',NULL,NULL,1,105,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(157,7,NULL,'3.14.3','Monitor security alerts and advisories','Monitor system security alerts and advisories and take action in response.',NULL,NULL,1,106,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(158,7,NULL,'3.14.4','Update malicious code protection mechanisms','Update malicious code protection mechanisms when new releases are available.',NULL,NULL,1,107,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(159,7,NULL,'3.14.5','Perform periodic scans and real-time scans','Perform periodic scans of organizational systems and real-time scans of files from external sources as files are downloaded, opened, or executed.',NULL,NULL,1,108,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(160,7,NULL,'3.14.6','Monitor organizational systems','Monitor organizational systems, including inbound and outbound communications traffic, to detect attacks and indicators of potential attacks.',NULL,NULL,1,109,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(161,7,NULL,'3.14.7','Identify unauthorized use','Identify unauthorized use of organizational systems.',NULL,NULL,1,110,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(162,8,NULL,'GV.OC-01','Organizational mission is understood','The organizational mission is understood and informs cybersecurity risk management.',NULL,NULL,1,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(163,8,NULL,'GV.OC-02','Internal and external stakeholders are understood','Internal and external stakeholders are understood, and their needs and expectations regarding cybersecurity risk management are understood and considered.',NULL,NULL,1,2,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(164,8,NULL,'GV.OC-03','Legal, regulatory, and contractual requirements are understood','Legal, regulatory, and contractual requirements regarding cybersecurity — including privacy and civil liberties obligations — are understood and managed.',NULL,NULL,1,3,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(165,8,NULL,'GV.OC-04','Critical objectives and dependencies are understood','Critical objectives, capabilities, and services that external stakeholders depend on or expect from the organization are understood and communicated.',NULL,NULL,1,4,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(166,8,NULL,'GV.OC-05','Outcomes and priorities are established','Outcomes, capabilities, and services that the organization depends on are understood and communicated.',NULL,NULL,1,5,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(167,8,NULL,'GV.RM-01','Risk management objectives are established','Risk management objectives are established and agreed to by organizational stakeholders.',NULL,NULL,1,6,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(168,8,NULL,'GV.RM-02','Risk appetite and tolerance are established','Risk appetite and risk tolerance statements are established, communicated, and maintained.',NULL,NULL,1,7,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(169,8,NULL,'GV.RM-03','Risk management activities are integrated','Cybersecurity risk management activities and outcomes are included in enterprise risk management processes.',NULL,NULL,1,8,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(170,8,NULL,'GV.RM-04','Strategic direction for risk management is established','Strategic direction that describes appropriate risk response options is established and communicated.',NULL,NULL,1,9,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(171,8,NULL,'GV.RM-05','Lines of communication for risk are established','Lines of communication across the organization are established for cybersecurity risks, including risks from suppliers and other third parties.',NULL,NULL,1,10,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(172,8,NULL,'GV.RM-06','Standardized method for risk calculation is established','A standardized method for calculating, documenting, categorizing, and prioritizing cybersecurity risks is established and communicated.',NULL,NULL,1,11,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(173,8,NULL,'GV.RM-07','Strategic opportunities are characterized','Strategic opportunities (i.e., positive risks) are characterized and are included in organizational cybersecurity risk discussions.',NULL,NULL,1,12,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(174,8,NULL,'GV.RR-01','Organizational leadership is responsible for risk','Organizational leadership is responsible and accountable for cybersecurity risk and fosters a culture that is risk-aware, ethical, and continually improving.',NULL,NULL,1,13,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(175,8,NULL,'GV.RR-02','Roles and responsibilities are established','Roles, responsibilities, and authorities related to cybersecurity risk management are established, communicated, understood, and enforced.',NULL,NULL,1,14,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(176,8,NULL,'GV.RR-03','Adequate resources are allocated','Adequate resources are allocated commensurate with the cybersecurity risk strategy, roles, responsibilities, and policies.',NULL,NULL,1,15,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(177,8,NULL,'GV.RR-04','Cybersecurity is included in HR practices','Cybersecurity is included in human resources practices.',NULL,NULL,1,16,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(178,8,NULL,'GV.PO-01','Cybersecurity policy is established','A policy for managing cybersecurity risks is established based on organizational context, cybersecurity strategy, and priorities and is communicated and enforced.',NULL,NULL,1,17,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(179,8,NULL,'GV.PO-02','Policy is reviewed and updated','Policy for managing cybersecurity risks is reviewed, updated, communicated, and enforced to reflect changes in requirements, threats, technology, and organizational mission.',NULL,NULL,1,18,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(180,8,NULL,'GV.SC-01','Supply chain risk management program is established','A cybersecurity supply chain risk management program, strategy, objectives, policies, and processes are established and agreed to by organizational stakeholders.',NULL,NULL,1,19,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(181,8,NULL,'GV.SC-02','Supplier risk roles are established','Cybersecurity roles and responsibilities for suppliers, customers, and partners are established, communicated, and coordinated internally and externally.',NULL,NULL,1,20,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(182,8,NULL,'GV.SC-03','Supply chain risk management is integrated','Cybersecurity supply chain risk management is integrated into cybersecurity and enterprise risk management, risk assessment, and improvement processes.',NULL,NULL,1,21,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(183,8,NULL,'GV.SC-04','Suppliers are known and prioritized','Suppliers are known and prioritized by criticality.',NULL,NULL,1,22,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(184,8,NULL,'GV.SC-05','Supply chain requirements are established','Requirements to address cybersecurity risks in supply chains are established, prioritized, and integrated into contracts and other types of agreements with suppliers and other relevant third parties.',NULL,NULL,1,23,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(185,8,NULL,'GV.SC-06','Due diligence is performed on suppliers','Planning and due diligence are conducted to reduce risks before entering into formal supplier or other third-party relationships.',NULL,NULL,1,24,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(186,8,NULL,'GV.SC-07','Supply chain risk is managed throughout lifecycle','The risks posed by a supplier, their products and services, and other third parties are understood, recorded, prioritized, assessed, responded to, and monitored over the course of the relationship.',NULL,NULL,1,25,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(187,8,NULL,'GV.SC-08','Relevant suppliers are included in incident planning','Relevant suppliers and other third parties are included in incident planning, response, and recovery activities.',NULL,NULL,1,26,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(188,8,NULL,'GV.SC-09','Supply chain security practices are integrated','Supply chain security practices are integrated into cybersecurity and enterprise risk management programs, and their performance is monitored throughout the technology product and service life cycle.',NULL,NULL,1,27,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(189,8,NULL,'GV.SC-10','Supply chain risk management plans include provisions','Cybersecurity supply chain risk management plans include provisions for activities that occur after the conclusion of a partnership or service agreement.',NULL,NULL,1,28,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(190,8,NULL,'ID.AM-01','Hardware inventories are maintained','Inventories of hardware managed by the organization are maintained.',NULL,NULL,1,29,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(191,8,NULL,'ID.AM-02','Software inventories are maintained','Inventories of software, services, and systems managed by the organization are maintained.',NULL,NULL,1,30,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(192,8,NULL,'ID.AM-03','Representations of network communication are maintained','Representations of the organization\'s authorized network communication and internal and external network data flows are maintained.',NULL,NULL,1,31,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(193,8,NULL,'ID.AM-04','Inventories of services are maintained','Inventories of services provided by suppliers are maintained.',NULL,NULL,1,32,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(194,8,NULL,'ID.AM-05','Assets are prioritized','Assets are prioritized based on classification, criticality, resources, and impact on the mission.',NULL,NULL,1,33,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(195,8,NULL,'ID.AM-07','Data inventories are maintained','Inventories of data and corresponding metadata for designated data types are maintained.',NULL,NULL,1,34,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(196,8,NULL,'ID.AM-08','Systems and assets are managed throughout lifecycle','Systems, hardware, software, services, and data are managed throughout their life cycles.',NULL,NULL,1,35,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(197,8,NULL,'ID.RA-01','Vulnerabilities are identified and documented','Vulnerabilities in assets are identified, validated, and recorded.',NULL,NULL,1,36,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(198,8,NULL,'ID.RA-02','Threat intelligence is received','Cyber threat intelligence is received from information sharing forums and sources.',NULL,NULL,1,37,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(199,8,NULL,'ID.RA-03','Internal and external threats are identified','Internal and external threats to the organization are identified and recorded.',NULL,NULL,1,38,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(200,8,NULL,'ID.RA-04','Potential impacts are identified','Potential impacts and likelihoods of threats exploiting vulnerabilities are identified and recorded.',NULL,NULL,1,39,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(201,8,NULL,'ID.RA-05','Threats and risks are used to assess risk','Threats, vulnerabilities, likelihoods, and impacts are used to understand inherent risk and inform risk response prioritization.',NULL,NULL,1,40,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(202,8,NULL,'ID.RA-06','Risk responses are chosen and prioritized','Risk responses are chosen, prioritized, planned, tracked, and communicated.',NULL,NULL,1,41,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(203,8,NULL,'ID.RA-07','Changes and exceptions are managed','Changes and exceptions are managed, assessed for risk impact, recorded, and tracked.',NULL,NULL,1,42,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(204,8,NULL,'ID.RA-08','Vulnerability disclosure processes are established','Processes for receiving, analyzing, and responding to vulnerability disclosures are established.',NULL,NULL,1,43,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(205,8,NULL,'ID.RA-09','Integrity of hardware and software is assessed','The authenticity and integrity of hardware and software are assessed prior to acquisition and use.',NULL,NULL,1,44,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(206,8,NULL,'ID.RA-10','Critical suppliers are assessed prior to acquisition','Critical suppliers are assessed prior to acquisition.',NULL,NULL,1,45,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(207,8,NULL,'ID.IM-01','Improvements are identified from evaluations','Improvements are identified from evaluations of security tests, exercises, and assessments.',NULL,NULL,1,46,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(208,8,NULL,'ID.IM-02','Improvements are identified from security incidents','Improvements are identified from security tests and exercises, including those done in coordination with suppliers and relevant third parties.',NULL,NULL,1,47,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(209,8,NULL,'ID.IM-03','Improvements are identified from execution of processes','Improvements are identified from execution of operational processes, procedures, and activities.',NULL,NULL,1,48,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(210,8,NULL,'ID.IM-04','Incident response plans are improved','Cybersecurity plans that affect operations are communicated, maintained, and improved.',NULL,NULL,1,49,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(211,8,NULL,'PR.AA-01','Identities and credentials are managed','Identities and credentials for authorized users, services, and hardware are managed by the organization.',NULL,NULL,1,50,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(212,8,NULL,'PR.AA-02','Identities are proofed and bound','Identities are proofed and bound to credentials based on the context of interactions.',NULL,NULL,1,51,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(213,8,NULL,'PR.AA-03','Users and services are authenticated','Users, services, and hardware are authenticated.',NULL,NULL,1,52,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(214,8,NULL,'PR.AA-04','Identity assertions are protected','Identity assertions are protected, conveyed, and verified.',NULL,NULL,1,53,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(215,8,NULL,'PR.AA-05','Access permissions are managed','Access permissions, entitlements, and authorizations are defined in a policy, managed, enforced, and reviewed, and incorporate the principles of least privilege and separation of duties.',NULL,NULL,1,54,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(216,8,NULL,'PR.AA-06','Physical access is managed','Physical access to assets is managed, monitored, and enforced commensurate with risk.',NULL,NULL,1,55,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(217,8,NULL,'PR.AT-01','Personnel are provided awareness and training','Personnel are provided with awareness and training so that they possess the knowledge and skills to perform general tasks with cybersecurity risks in mind.',NULL,NULL,1,56,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(218,8,NULL,'PR.AT-02','Privileged users understand responsibilities','Individuals in specialized roles are provided with awareness and training so that they possess the knowledge and skills to perform relevant tasks with cybersecurity risks in mind.',NULL,NULL,1,57,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(219,8,NULL,'PR.DS-01','Data-at-rest is protected','The confidentiality, integrity, and availability of data-at-rest are protected.',NULL,NULL,1,58,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(220,8,NULL,'PR.DS-02','Data-in-transit is protected','The confidentiality, integrity, and availability of data-in-transit are protected.',NULL,NULL,1,59,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(221,8,NULL,'PR.DS-10','Data-in-use is protected','The confidentiality, integrity, and availability of data-in-use are protected.',NULL,NULL,1,60,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(222,8,NULL,'PR.DS-11','Backups are created and protected','Backups of data are created, protected, maintained, and tested.',NULL,NULL,1,61,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(223,8,NULL,'PR.PS-01','Configuration management practices are established','Configuration management practices are established and applied.',NULL,NULL,1,62,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(224,8,NULL,'PR.PS-02','Software is maintained and replaced','Software is maintained, replaced, and removed commensurate with risk.',NULL,NULL,1,63,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(225,8,NULL,'PR.PS-03','Hardware is maintained and replaced','Hardware is maintained, replaced, and removed commensurate with risk.',NULL,NULL,1,64,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(226,8,NULL,'PR.PS-04','Log records are generated','Log records are generated and made available for continuous monitoring.',NULL,NULL,1,65,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(227,8,NULL,'PR.PS-05','Installation and execution of unauthorized software is prevented','Installation and execution of unauthorized software is prevented.',NULL,NULL,1,66,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(228,8,NULL,'PR.PS-06','Secure software development practices are integrated','Secure software development practices are integrated, and their performance is monitored throughout the software development life cycle.',NULL,NULL,1,67,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(229,8,NULL,'PR.IR-01','Networks and environments are protected','Networks and environments are protected from unauthorized logical access and usage.',NULL,NULL,1,68,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(230,8,NULL,'PR.IR-02','Technology assets are protected from environmental threats','The organization\'s technology assets are protected from environmental threats.',NULL,NULL,1,69,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(231,8,NULL,'PR.IR-03','Mechanisms for resilience are implemented','Mechanisms are implemented to achieve resilience requirements in normal and adverse situations.',NULL,NULL,1,70,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(232,8,NULL,'PR.IR-04','Adequate resource capacity is maintained','Adequate resource capacity to ensure availability is maintained.',NULL,NULL,1,71,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(233,8,NULL,'DE.CM-01','Networks are monitored','Networks and network services are monitored to find potentially adverse events.',NULL,NULL,1,72,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(234,8,NULL,'DE.CM-02','Physical environment is monitored','The physical environment is monitored to find potentially adverse events.',NULL,NULL,1,73,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(235,8,NULL,'DE.CM-03','Personnel activity is monitored','Personnel activity and technology usage are monitored to find potentially adverse events.',NULL,NULL,1,74,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(236,8,NULL,'DE.CM-06','External service provider activity is monitored','External service provider activities and services are monitored to find potentially adverse events.',NULL,NULL,1,75,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(237,8,NULL,'DE.CM-09','Computing hardware and software are monitored','Computing hardware and software, runtime environments, and their data are monitored to find potentially adverse events.',NULL,NULL,1,76,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(238,8,NULL,'DE.AE-02','Potentially adverse events are analyzed','Potentially adverse events are analyzed to better understand associated activities.',NULL,NULL,1,77,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(239,8,NULL,'DE.AE-03','Event information is correlated','Information is correlated from multiple sources.',NULL,NULL,1,78,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(240,8,NULL,'DE.AE-04','Estimated impact of events is understood','The estimated impact and scope of adverse events are understood.',NULL,NULL,1,79,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(241,8,NULL,'DE.AE-06','Information on adverse events is provided','Information on adverse events is provided to authorized staff and tools.',NULL,NULL,1,80,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(242,8,NULL,'DE.AE-07','Cyber threat intelligence is integrated','Cyber threat intelligence and other contextual information are integrated into the analysis.',NULL,NULL,1,81,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(243,8,NULL,'DE.AE-08','Incidents are declared','Incidents are declared when adverse events meet the defined incident criteria.',NULL,NULL,1,82,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(244,8,NULL,'RS.MA-01','Incident response plan is executed','The incident response plan is executed in coordination with relevant third parties once an incident is declared.',NULL,NULL,1,83,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(245,8,NULL,'RS.MA-02','Incident reports are triaged','Incident reports are triaged and validated.',NULL,NULL,1,84,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(246,8,NULL,'RS.MA-03','Incidents are categorized and prioritized','Incidents are categorized and prioritized.',NULL,NULL,1,85,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(247,8,NULL,'RS.MA-04','Incidents are escalated or elevated','Incidents are escalated or elevated as needed.',NULL,NULL,1,86,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(248,8,NULL,'RS.MA-05','Criteria for initiating incident recovery are applied','The criteria for initiating incident recovery are applied.',NULL,NULL,1,87,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(249,8,NULL,'RS.AN-03','Analysis is performed to establish cause and scope','Analysis is performed to establish what has taken place during an incident and the root cause of the incident.',NULL,NULL,1,88,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(250,8,NULL,'RS.AN-06','Actions performed during investigation are recorded','Actions performed during an investigation are recorded, and the records\' integrity and provenance are preserved.',NULL,NULL,1,89,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(251,8,NULL,'RS.AN-07','Incident data and metadata are collected','Incident data and metadata are collected, and their integrity and provenance are preserved.',NULL,NULL,1,90,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(252,8,NULL,'RS.AN-08','Incident scope is estimated','An incident\'s magnitude is estimated and validated.',NULL,NULL,1,91,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(253,8,NULL,'RS.CO-02','Internal and external stakeholders are notified','Internal and external stakeholders are notified of incidents.',NULL,NULL,1,92,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(254,8,NULL,'RS.CO-03','Information is shared with designated parties','Information is shared with designated internal and external stakeholders.',NULL,NULL,1,93,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(255,8,NULL,'RS.MI-01','Incidents are contained','Incidents are contained.',NULL,NULL,1,94,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(256,8,NULL,'RS.MI-02','Incidents are eradicated','Incidents are eradicated.',NULL,NULL,1,95,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(257,8,NULL,'RC.RP-01','Recovery plan is executed','The recovery portion of the incident response plan is executed once initiated from the incident response process.',NULL,NULL,1,96,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(258,8,NULL,'RC.RP-02','Recovery actions are selected and performed','Recovery actions are selected, scoped, prioritized, and performed.',NULL,NULL,1,97,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(259,8,NULL,'RC.RP-03','Integrity of backups is verified','The integrity of backups and other restoration assets is verified before using them for restoration.',NULL,NULL,1,98,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(260,8,NULL,'RC.RP-04','Critical functions and systems are restored','Critical mission functions and cybersecurity risk management are considered to establish post-incident operational norms.',NULL,NULL,1,99,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(261,8,NULL,'RC.RP-05','Integrity of restored assets is verified','The integrity of restored assets is verified, systems and services are restored, and normal operating status is confirmed.',NULL,NULL,1,100,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(262,8,NULL,'RC.RP-06','End of incident recovery is declared','The end of incident recovery is declared based on criteria, and incident-related documentation is completed.',NULL,NULL,1,101,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(263,8,NULL,'RC.CO-03','Recovery activities are communicated to stakeholders','Recovery activities and progress in restoring operational capabilities are communicated to designated internal and external stakeholders.',NULL,NULL,1,102,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(264,8,NULL,'RC.CO-04','Public updates on recovery are shared','Public updates on incident recovery are shared using approved methods and messaging.',NULL,NULL,1,103,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(265,9,NULL,'1.1','Processes and mechanisms for installing and maintaining network security controls are defined and understood','Ensure that all security policies and operational procedures related to network security controls are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,1,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(266,9,NULL,'1.1.1','All security policies and operational procedures are documented and kept up to date','All security policies and operational procedures identified in Requirement 1 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,2,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(267,9,NULL,'1.1.2','Roles and responsibilities for Requirement 1 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 1 are documented, assigned, and understood by personnel.',NULL,NULL,1,3,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(268,9,NULL,'1.2','Network security controls are configured and maintained','Network security controls (NSCs) such as firewalls and other network filtering technologies are configured and maintained to protect the cardholder data environment.',NULL,NULL,1,4,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(269,9,NULL,'1.2.1','Configuration standards for NSC rulesets are defined and implemented','Configuration standards for network security control rulesets are defined, implemented, and maintained to restrict inbound and outbound traffic to only that which is necessary.',NULL,NULL,1,5,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(270,9,NULL,'1.2.2','Changes to network connections and NSC configurations are approved and managed','All changes to network connections and to configurations of network security controls are approved and managed in accordance with the change control process defined in Requirement 6.5.1.',NULL,NULL,1,6,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(271,9,NULL,'1.2.3','Accurate network diagram(s) maintained showing all connections to cardholder data','An accurate network diagram is maintained that shows all connections between the CDE and other networks, including wireless networks.',NULL,NULL,1,7,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(272,9,NULL,'1.2.4','Accurate data-flow diagram(s) maintained','An accurate data-flow diagram is maintained that shows all account data flows across systems and networks.',NULL,NULL,1,8,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(273,9,NULL,'1.2.5','All services, protocols, and ports allowed are identified and approved','All services, protocols, and ports allowed are identified, approved, and have a defined business need with authorization documented for each.',NULL,NULL,1,9,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(274,9,NULL,'1.2.6','Security features are defined and implemented for insecure services, protocols, or ports','Security features are defined and implemented for all services, protocols, and ports that are in use and considered to be insecure.',NULL,NULL,1,10,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(275,9,NULL,'1.2.7','Configurations of NSCs are reviewed at least every six months','Configurations of network security controls are reviewed at least once every six months to confirm they are relevant and effective.',NULL,NULL,1,11,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(276,9,NULL,'1.3','Network access to and from the cardholder data environment is restricted','Network access to and from the cardholder data environment is restricted and managed to prevent unauthorized access.',NULL,NULL,1,12,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(277,9,NULL,'1.3.1','Inbound traffic to the CDE is restricted to only necessary traffic','Inbound traffic to the cardholder data environment is restricted to only that traffic which is necessary, and all other traffic is specifically denied.',NULL,NULL,1,13,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(278,9,NULL,'1.3.2','Outbound traffic from the CDE is restricted to only necessary traffic','Outbound traffic from the cardholder data environment is restricted to only that traffic which is necessary, and all other traffic is specifically denied.',NULL,NULL,1,14,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(279,9,NULL,'1.3.3','NSCs are installed between all wireless networks and the CDE','Network security controls are installed between all wireless networks and the cardholder data environment regardless of whether the wireless network is a CDE network, and traffic is denied by default and only permitted via authorized rules.',NULL,NULL,1,15,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(280,9,NULL,'1.4','Network connections between trusted and untrusted networks are controlled','Network connections between trusted and untrusted networks are controlled and managed to protect cardholder data.',NULL,NULL,1,16,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(281,9,NULL,'1.4.1','NSCs are implemented between trusted and untrusted networks','Network security controls are implemented between trusted and untrusted networks to control traffic at the boundary.',NULL,NULL,1,17,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(282,9,NULL,'1.4.2','Inbound traffic from untrusted networks is restricted to system components providing authorized services','Inbound traffic from untrusted networks to trusted networks is restricted to communications with system components that are authorized to provide publicly accessible services, protocols, and ports.',NULL,NULL,1,18,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(283,9,NULL,'1.5','Risks to the CDE from computing devices able to connect to both untrusted networks and the CDE are mitigated','Risks to the CDE from computing devices that are able to connect to both untrusted networks and the CDE are mitigated.',NULL,NULL,1,19,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(284,9,NULL,'1.5.1','Security controls are implemented on devices that connect to both untrusted and trusted networks','Security controls are implemented on any computing devices, including company- and employee-owned devices, that connect to both untrusted networks and the CDE, to prevent threats from being introduced to the entity\'s network.',NULL,NULL,1,20,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(285,9,NULL,'2.1','Processes and mechanisms for applying secure configurations are defined and understood','Ensure that all security policies and operational procedures related to applying secure configurations to all system components are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,21,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(286,9,NULL,'2.1.1','All security policies and operational procedures for Requirement 2 are documented and maintained','All security policies and operational procedures identified in Requirement 2 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,22,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(287,9,NULL,'2.1.2','Roles and responsibilities for Requirement 2 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 2 are documented, assigned, and understood by personnel.',NULL,NULL,1,23,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(288,9,NULL,'2.2','System components are configured and managed securely','System components are configured and managed securely using industry-accepted hardening standards.',NULL,NULL,1,24,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(289,9,NULL,'2.2.1','Configuration standards are developed covering all system components and address all known security vulnerabilities','Configuration standards are developed, implemented, and maintained to cover all system components, address all known security vulnerabilities, and be consistent with industry-accepted system hardening standards.',NULL,NULL,1,25,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(290,9,NULL,'2.2.2','Vendor default accounts are managed or removed','Vendor default accounts are managed as follows: if default accounts will be used, the default password is changed. If default accounts are not used, they are removed or disabled.',NULL,NULL,1,26,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(291,9,NULL,'2.2.3','Primary functions requiring different security levels are managed separately','Primary functions requiring different security levels are managed to ensure that functions with different security needs exist on separate system components or are properly isolated.',NULL,NULL,1,27,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(292,9,NULL,'2.2.4','Only necessary services, protocols, daemons, and functions are enabled','Only necessary services, protocols, daemons, and functions are enabled, and all unnecessary functionality is removed or disabled.',NULL,NULL,1,28,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(293,9,NULL,'2.2.5','Insecure services, protocols, or daemons are secured with appropriate security features','If any insecure services, protocols, or daemons are present, appropriate security features are documented and implemented to reduce the risk of exploitation.',NULL,NULL,1,29,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(294,9,NULL,'2.2.6','System security parameters are configured to prevent misuse','System security parameters are configured to prevent misuse and are consistent with the configuration standards.',NULL,NULL,1,30,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(295,9,NULL,'2.2.7','All non-console administrative access is encrypted using strong cryptography','All non-console administrative access is encrypted using strong cryptography with appropriate key management.',NULL,NULL,1,31,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(296,9,NULL,'2.3','Wireless environments are configured and managed securely','Wireless environments are configured and managed securely to prevent unauthorized access.',NULL,NULL,1,32,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(297,9,NULL,'2.3.1','Wireless vendor defaults are not used for encryption keys, passwords, or SNMP community strings','For wireless environments connected to the CDE or transmitting account data, all wireless vendor defaults are changed at installation or are confirmed to be secure, including wireless encryption keys, passwords, and SNMP community strings.',NULL,NULL,1,33,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(298,9,NULL,'2.3.2','Wireless encryption keys are changed when personnel with knowledge depart','For wireless environments connected to the CDE or transmitting account data, wireless encryption keys are changed whenever personnel with knowledge of the key leave the company or move to a different role.',NULL,NULL,1,34,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(299,9,NULL,'3.1','Processes and mechanisms for protecting stored account data are defined and understood','All security policies and operational procedures related to protection of stored account data are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,35,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(300,9,NULL,'3.1.1','All security policies and operational procedures for Requirement 3 are documented and maintained','All security policies and operational procedures identified in Requirement 3 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,36,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(301,9,NULL,'3.1.2','Roles and responsibilities for Requirement 3 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 3 are documented, assigned, and understood by personnel.',NULL,NULL,1,37,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(302,9,NULL,'3.2','Storage of account data is kept to a minimum','Storage of account data is kept to a minimum through implementation of data retention and disposal policies, procedures, and processes.',NULL,NULL,1,38,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(303,9,NULL,'3.2.1','Account data storage is kept to a minimum through data retention and disposal policies','Account data storage amount and retention time are limited to that which is required for business, legal, and/or regulatory purposes. A data retention policy defines storage needs and retention periods with processes for secure deletion.',NULL,NULL,1,39,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(304,9,NULL,'3.3','Sensitive authentication data (SAD) is not stored after authorization','Sensitive authentication data is not retained after authorization, even if encrypted. All sensitive authentication data received is rendered unrecoverable upon completion of the authorization process.',NULL,NULL,1,40,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(305,9,NULL,'3.3.1','SAD is not retained after authorization, even if encrypted','Sensitive authentication data (full track data, card verification codes, PINs and PIN blocks) is not retained after authorization regardless of encryption status.',NULL,NULL,1,41,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(306,9,NULL,'3.3.2','SAD stored electronically prior to completion of authorization is encrypted using strong cryptography','Sensitive authentication data that is stored electronically prior to completion of authorization is encrypted using strong cryptography.',NULL,NULL,1,42,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(307,9,NULL,'3.3.3','Additional requirement for issuers: SAD is stored only if justified and secured','Additional requirement for issuers and companies that support issuing services: any storage of sensitive authentication data is limited to that which is needed for a legitimate issuing business need and is secured.',NULL,NULL,1,43,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(308,9,NULL,'3.4','Access to displays of full PAN and ability to copy cardholder data are restricted','Access to displays of full PAN and ability to copy PAN are restricted to those with a legitimate business need.',NULL,NULL,1,44,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(309,9,NULL,'3.4.1','PAN is masked when displayed so that only authorized personnel can see full PAN','PAN is masked when displayed (the first six and last four digits are the maximum number of digits to be displayed), such that only personnel with a legitimate business need can see more than the first six/last four digits of the PAN.',NULL,NULL,1,45,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(310,9,NULL,'3.4.2','PAN is secured with strong cryptography when stored','When stored, PAN is secured using strong cryptography methods including one-way hashes, truncation, index tokens, or strong cryptography with associated key-management processes and procedures.',NULL,NULL,1,46,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(311,9,NULL,'3.5','Primary account number (PAN) is secured wherever it is stored','PAN is rendered unreadable anywhere it is stored by any of the following: one-way hashes, truncation, index tokens, or strong cryptography.',NULL,NULL,1,47,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(312,9,NULL,'3.5.1','PAN is rendered unreadable anywhere it is stored','PAN is rendered unreadable anywhere it is stored using any of the following approaches: one-way hashes based on strong cryptography, truncation, index tokens with securely stored pads, or strong cryptography with associated key-management processes.',NULL,NULL,1,48,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(313,9,NULL,'3.6','Cryptographic keys used to protect stored account data are secured','Cryptographic keys used to protect stored cardholder data are secured against disclosure and misuse.',NULL,NULL,1,49,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(314,9,NULL,'3.6.1','Procedures for protecting cryptographic keys are defined and implemented','Procedures are defined and implemented to protect cryptographic keys used to protect stored account data against disclosure and misuse, including restricting access to the fewest number of custodians necessary.',NULL,NULL,1,50,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(315,9,NULL,'3.7','Where cryptography is used to protect stored account data, key management processes and procedures are defined and implemented','Key management processes and procedures covering the full lifecycle are defined and implemented for cryptographic keys used to protect stored account data.',NULL,NULL,1,51,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(316,9,NULL,'3.7.1','Key-management policies and procedures are implemented for cryptographic keys','Key-management policies and procedures are implemented to include generation of strong cryptographic keys, secure distribution, secure storage, periodic key changes, retirement, and destruction of keys.',NULL,NULL,1,52,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(317,9,NULL,'4.1','Processes and mechanisms for protecting cardholder data with strong cryptography during transmission are defined and understood','All security policies and operational procedures related to protecting cardholder data during transmission over open, public networks are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,53,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(318,9,NULL,'4.1.1','All security policies and operational procedures for Requirement 4 are documented and maintained','All security policies and operational procedures identified in Requirement 4 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,54,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(319,9,NULL,'4.1.2','Roles and responsibilities for Requirement 4 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 4 are documented, assigned, and understood by personnel.',NULL,NULL,1,55,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(320,9,NULL,'4.2','PAN is protected with strong cryptography during transmission','PAN is protected with strong cryptography during transmission over open, public networks. Trusted keys and certificates are managed to ensure integrity of the secure communication.',NULL,NULL,1,56,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(321,9,NULL,'4.2.1','Strong cryptography and security protocols are implemented to safeguard PAN during transmission','Strong cryptography and security protocols are implemented to safeguard PAN during transmission over open, public networks. Only trusted keys and certificates are accepted. The protocol in use supports only secure versions or configurations and does not support fallback to insecure versions.',NULL,NULL,1,57,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(322,9,NULL,'4.2.2','PAN is secured with strong cryptography whenever sent via end-user messaging technologies','PAN is secured with strong cryptography whenever it is sent via end-user messaging technologies such as email, instant messaging, SMS, and chat.',NULL,NULL,1,58,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(323,9,NULL,'5.1','Processes and mechanisms for protecting systems and networks from malicious software are defined and understood','All security policies and operational procedures related to protecting all systems and networks from malicious software are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,59,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(324,9,NULL,'5.1.1','All security policies and operational procedures for Requirement 5 are documented and maintained','All security policies and operational procedures identified in Requirement 5 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,60,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(325,9,NULL,'5.1.2','Roles and responsibilities for Requirement 5 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 5 are documented, assigned, and understood by personnel.',NULL,NULL,1,61,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(326,9,NULL,'5.2','Malicious software is prevented or detected and addressed','An anti-malware solution is deployed on all systems commonly affected by malicious software to prevent, detect, and address malicious software.',NULL,NULL,1,62,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(327,9,NULL,'5.2.1','An anti-malware solution is deployed on all system components except those identified as not at risk','An anti-malware solution is deployed on all system components, except for those system components identified in periodic evaluations that concludes the components are not at risk from malware.',NULL,NULL,1,63,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(328,9,NULL,'5.2.2','The anti-malware solution detects all known types of malware and removes, blocks, or contains them','The deployed anti-malware solution detects all known types of malware and removes, blocks, or contains all known types of malware.',NULL,NULL,1,64,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(329,9,NULL,'5.2.3','System components not at risk for malware are evaluated periodically to confirm they remain not at risk','Any system components that are not at risk for malware are evaluated periodically to include a documented list of components, ongoing reevaluation, and confirmation that anti-malware is not needed.',NULL,NULL,1,65,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(330,9,NULL,'5.3','Anti-malware mechanisms and processes are active, maintained, and monitored','Anti-malware mechanisms and processes are active, maintained, and monitored on all systems to ensure effectiveness.',NULL,NULL,1,66,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(331,9,NULL,'5.3.1','Anti-malware solution is kept current via automatic updates','The anti-malware solution is kept current via automatic updates.',NULL,NULL,1,67,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(332,9,NULL,'5.3.2','The anti-malware solution performs periodic scans and active real-time scans','The anti-malware solution performs periodic scans and active or real-time scans, or continuous behavioral analysis of systems or processes.',NULL,NULL,1,68,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(333,9,NULL,'5.3.3','Anti-malware solution for removable media performs automatic scans when media is inserted','For removable electronic media, the anti-malware solution performs automatic scans when the media is inserted, connected, or logically mounted.',NULL,NULL,1,69,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(334,9,NULL,'5.3.4','Audit logs for the anti-malware solution are enabled and retained','Audit logs for the anti-malware solution are enabled and retained in accordance with Requirement 10.5.1.',NULL,NULL,1,70,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(335,9,NULL,'5.3.5','Anti-malware mechanisms cannot be disabled or altered by users unless documented and authorized','Anti-malware mechanisms cannot be disabled or altered by users, unless specifically documented, and authorized by management on a case-by-case basis for a limited time period.',NULL,NULL,1,71,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(336,9,NULL,'5.4','Anti-phishing mechanisms protect users against phishing attacks','Anti-phishing mechanisms protect users against phishing attacks by implementing technical controls and/or processes.',NULL,NULL,1,72,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(337,9,NULL,'5.4.1','Processes and automated mechanisms are in place to detect and protect personnel against phishing attacks','Processes and automated mechanisms detect and protect personnel against phishing attacks including training, email filtering, anti-spoofing measures, and URL filtering.',NULL,NULL,1,73,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(338,9,NULL,'6.1','Processes and mechanisms for developing and maintaining secure systems and software are defined and understood','All security policies and operational procedures related to developing and maintaining secure systems and software are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,74,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(339,9,NULL,'6.1.1','All security policies and operational procedures for Requirement 6 are documented and maintained','All security policies and operational procedures identified in Requirement 6 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,75,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(340,9,NULL,'6.1.2','Roles and responsibilities for Requirement 6 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 6 are documented, assigned, and understood by personnel.',NULL,NULL,1,76,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(341,9,NULL,'6.2','Bespoke and custom software are developed securely','Bespoke and custom software is developed securely using secure development lifecycle practices.',NULL,NULL,1,77,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(342,9,NULL,'6.2.1','Bespoke and custom software is developed securely following industry standards and/or best practices','Bespoke and custom software are developed securely including processes that incorporate security throughout the software development lifecycle.',NULL,NULL,1,78,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(343,9,NULL,'6.2.2','Software development personnel are trained in secure coding and vulnerability identification','Software development personnel working on bespoke and custom software are trained at least once every 12 months on software security relevant to their job function including secure design, secure coding techniques, and testing tools.',NULL,NULL,1,79,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(344,9,NULL,'6.2.3','Bespoke and custom software is reviewed prior to release to identify potential coding vulnerabilities','Bespoke and custom software is reviewed prior to being released into production to identify and correct potential coding vulnerabilities. Reviews include manual or automated code review processes.',NULL,NULL,1,80,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(345,9,NULL,'6.2.4','Software engineering techniques prevent or mitigate common software attacks','Software engineering techniques or other methods are defined and in use by software development personnel to prevent or mitigate common software attacks and related vulnerabilities, including injection, buffer overflow, insecure cryptographic storage, and cross-site scripting.',NULL,NULL,1,81,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(346,9,NULL,'6.3','Security vulnerabilities are identified and addressed','Security vulnerabilities are identified and addressed through a formal vulnerability management process.',NULL,NULL,1,82,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(347,9,NULL,'6.3.1','Security vulnerabilities are identified and managed using reputable outside sources for vulnerability information','Security vulnerabilities are identified and managed by monitoring reputable outside sources for security vulnerability information and ranking each vulnerability by risk.',NULL,NULL,1,83,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(348,9,NULL,'6.3.2','An inventory of bespoke and custom software and third-party components is maintained','An inventory of bespoke and custom software, and third-party software components incorporated into bespoke and custom software, is maintained to facilitate vulnerability and patch management.',NULL,NULL,1,84,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(349,9,NULL,'6.3.3','All system components are protected from known vulnerabilities by installing applicable patches/updates','All system components are protected from known vulnerabilities by installing applicable security patches/updates. Critical and high security patches are installed within one month of release.',NULL,NULL,1,85,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(350,9,NULL,'6.4','Public-facing web applications are protected against attacks','Public-facing web applications are protected against attacks through application-level firewalls, code reviews, or other measures.',NULL,NULL,1,86,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(351,9,NULL,'6.4.1','Public-facing web applications are protected using automated vulnerability security assessment tools or manual review','For public-facing web applications, new threats and vulnerabilities are addressed on an ongoing basis using automated technical security assessment tools or processes and applications are reviewed at least annually.',NULL,NULL,1,87,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(352,9,NULL,'6.4.2','Public-facing web applications are protected by a web application firewall (WAF)','For public-facing web applications, an automated technical solution is deployed that continually detects and prevents web-based attacks, including at minimum a web application firewall (WAF) in front of public-facing web applications.',NULL,NULL,1,88,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(353,9,NULL,'6.5','Changes to all system components are managed securely','Changes to all system components in the production environment are managed securely through formal change control procedures.',NULL,NULL,1,89,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(354,9,NULL,'6.5.1','Changes to system components in production are made according to established change control procedures','Changes to all system components in the production environment are made according to established procedures that include documentation of impact, management approval, testing, and back-out procedures.',NULL,NULL,1,90,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(355,9,NULL,'7.1','Processes and mechanisms for restricting access to system components and cardholder data are defined and understood','All security policies and operational procedures related to restricting access to cardholder data by business need to know are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,91,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(356,9,NULL,'7.1.1','All security policies and operational procedures for Requirement 7 are documented and maintained','All security policies and operational procedures identified in Requirement 7 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,92,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(357,9,NULL,'7.1.2','Roles and responsibilities for Requirement 7 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 7 are documented, assigned, and understood by personnel.',NULL,NULL,1,93,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(358,9,NULL,'7.2','Access to system components and data is appropriately defined and assigned','Access to system components and data is restricted to only those individuals whose job requires such access based on least privilege and need-to-know.',NULL,NULL,1,94,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(359,9,NULL,'7.2.1','An access control model is defined that grants access based on business need to know and least privilege','An access control model is defined and includes granting access as follows: appropriate access depending on the entity\'s business and access needs, access to system components and data resources based on users\' job classification and function, and least privileges required.',NULL,NULL,1,95,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(360,9,NULL,'7.2.2','Access is assigned to users based on job classification and function','Access is assigned to users, including privileged users, based on job classification and function using role-based access control (RBAC) or similar mechanism.',NULL,NULL,1,96,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(361,9,NULL,'7.2.3','Required privileges are approved by authorized personnel','Required privileges are approved by authorized personnel and implemented using role-based access controls.',NULL,NULL,1,97,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(362,9,NULL,'7.2.4','All user accounts and related access privileges are reviewed at least every six months','All user accounts and related access privileges, including third-party/vendor accounts, are reviewed at least once every six months to ensure they remain appropriate based on job function.',NULL,NULL,1,98,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(363,9,NULL,'7.2.5','All application and system accounts and related access privileges are assigned and managed appropriately','All application and system accounts and related access privileges are assigned and managed based on least privilege and are periodically reviewed.',NULL,NULL,1,99,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(364,9,NULL,'7.3','Access to system components and data is managed via an access control system','Access to system components and data is managed via an access control system(s) that restricts access based on the user\'s need to know and covers all system components.',NULL,NULL,1,100,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(365,9,NULL,'7.3.1','An access control system is in place that restricts access based on need to know','An access control system is in place that restricts access based on a user\'s need to know and is set to deny all unless specifically allowed.',NULL,NULL,1,101,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(366,9,NULL,'8.1','Processes and mechanisms for identifying users and authenticating access are defined and understood','All security policies and operational procedures related to identification and authentication are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,102,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(367,9,NULL,'8.1.1','All security policies and operational procedures for Requirement 8 are documented and maintained','All security policies and operational procedures identified in Requirement 8 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,103,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(368,9,NULL,'8.1.2','Roles and responsibilities for Requirement 8 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 8 are documented, assigned, and understood by personnel.',NULL,NULL,1,104,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(369,9,NULL,'8.2','User identification and related accounts are strictly managed throughout the lifecycle','User identification and related accounts for users and administrators are strictly managed throughout the account lifecycle.',NULL,NULL,1,105,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(370,9,NULL,'8.2.1','All users are assigned a unique ID before access to system components or cardholder data is allowed','All users are assigned a unique ID before being allowed to access system components or cardholder data.',NULL,NULL,1,106,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(371,9,NULL,'8.2.2','Group, shared, or generic accounts are not used unless necessary and managed with additional controls','Group, shared, or generic accounts, or other shared authentication credentials are only used when necessary on an exception basis and are managed with additional controls including individual accountability.',NULL,NULL,1,107,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(372,9,NULL,'8.2.3','Additional requirement for service providers: unique authentication factors for customer premises remote access','Additional requirement for service providers only: service providers with remote access to customer premises use unique authentication factors for each customer premises.',NULL,NULL,1,108,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(373,9,NULL,'8.2.4','Addition, deletion, and modification of user IDs and authentication factors are managed appropriately','Addition, deletion, and modification of user IDs, authentication factors, and other identifier objects are managed and controlled, including revoking access immediately upon termination.',NULL,NULL,1,109,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(374,9,NULL,'8.2.5','Access for terminated users is immediately revoked','Access for terminated users is immediately revoked and all physical authentication factors (tokens, smart cards, etc.) are returned or deactivated.',NULL,NULL,1,110,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(375,9,NULL,'8.2.6','Inactive user accounts are removed or disabled within 90 days of inactivity','Inactive user accounts are removed or disabled within 90 days of inactivity.',NULL,NULL,1,111,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(376,9,NULL,'8.2.7','Accounts used by third parties for remote access are managed and monitored','Accounts used by third parties to access, support, or maintain system components via remote access are managed: enabled only during the time period needed, disabled when not in use, and monitored.',NULL,NULL,1,112,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(377,9,NULL,'8.2.8','If a user session has been idle for more than 15 minutes, the user is required to re-authenticate','If a user session has been idle for more than 15 minutes, the user is required to re-authenticate to re-activate the terminal or session.',NULL,NULL,1,113,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(378,9,NULL,'8.3','Strong authentication for users and administrators is established and managed','Strong authentication for users and administrators is established and managed, including password complexity, multi-factor authentication, and protection of authentication factors.',NULL,NULL,1,114,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(379,9,NULL,'8.3.1','All user access to system components is authenticated via at least one authentication factor','All user access to system components for users and administrators is authenticated via at least one of the following authentication factors: something you know, something you have, or something you are.',NULL,NULL,1,115,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(380,9,NULL,'8.3.2','Strong cryptography is used to render all authentication factors unreadable during storage and transmission','Strong cryptography is used to render all authentication factors unreadable during storage and transmission on all system components.',NULL,NULL,1,116,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(381,9,NULL,'8.3.5','Passwords/passphrases meet minimum complexity and length requirements','If passwords/passphrases are used as authentication factors, they are set and reset with minimum length of 12 characters (or 8 if system cannot support 12) containing both numeric and alphabetic characters.',NULL,NULL,1,117,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(382,9,NULL,'8.3.6','Passwords/passphrases are changed at least once every 90 days or security posture is dynamically analyzed','If passwords/passphrases are used as authentication factors, they are changed at least once every 90 days, or the security posture of accounts is dynamically analyzed and access is automatically determined accordingly.',NULL,NULL,1,118,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(383,9,NULL,'8.3.9','Passwords/passphrases for single-use are unique for each use and not reusable','If passwords/passphrases are used as the only authentication factor for user access, then either passwords/passphrases are changed at least once every 90 days or access to resources is automatically determined by dynamically analyzing account security posture.',NULL,NULL,1,119,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(384,9,NULL,'8.4','Multi-factor authentication (MFA) is implemented to secure access into the CDE','Multi-factor authentication is implemented to secure access into the cardholder data environment and for all non-console administrative access.',NULL,NULL,1,120,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(385,9,NULL,'8.4.1','MFA is implemented for all non-console access into the CDE for personnel with administrative access','Multi-factor authentication is implemented for all non-console access into the CDE for personnel with administrative access.',NULL,NULL,1,121,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(386,9,NULL,'8.4.2','MFA is implemented for all access into the CDE','Multi-factor authentication is implemented for all access into the CDE.',NULL,NULL,1,122,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(387,9,NULL,'8.4.3','MFA is implemented for all remote network access originating from outside the entity\'s network','Multi-factor authentication is implemented for all remote network access originating from outside the entity\'s network that could access or impact the CDE.',NULL,NULL,1,123,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(388,9,NULL,'8.5','Multi-factor authentication systems are configured to prevent misuse','Multi-factor authentication (MFA) systems are configured to prevent misuse including replay attacks and bypass attempts.',NULL,NULL,1,124,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(389,9,NULL,'8.5.1','MFA systems are implemented to be resistant to replay attacks and cannot be bypassed','MFA systems are implemented such that MFA factors are independent, access to any MFA factor does not provide access to any other factor, and approval of at least two factors is required for access.',NULL,NULL,1,125,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(390,9,NULL,'8.6','Use of application and system accounts and associated authentication factors is strictly managed','Use of application and system accounts and associated authentication factors is strictly managed.',NULL,NULL,1,126,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(391,9,NULL,'8.6.1','System and application accounts that can be used for interactive login are managed appropriately','If accounts used by systems or applications can be used for interactive login, they are managed as follows: interactive use is prevented unless needed, interactive use is limited to exceptional circumstances, and account access is restricted.',NULL,NULL,1,127,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(392,9,NULL,'9.1','Processes and mechanisms for restricting physical access to cardholder data are defined and understood','All security policies and operational procedures related to restricting physical access to cardholder data are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,128,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(393,9,NULL,'9.1.1','All security policies and operational procedures for Requirement 9 are documented and maintained','All security policies and operational procedures identified in Requirement 9 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,129,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(394,9,NULL,'9.1.2','Roles and responsibilities for Requirement 9 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 9 are documented, assigned, and understood by personnel.',NULL,NULL,1,130,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(395,9,NULL,'9.2','Physical access controls manage entry into facilities and systems containing cardholder data','Physical access controls manage entry into facilities and systems containing cardholder data to ensure only authorized personnel have access.',NULL,NULL,1,131,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(396,9,NULL,'9.2.1','Appropriate facility entry controls are in place to restrict physical access to systems in the CDE','Appropriate facility entry controls are in place to limit and monitor physical access to systems in the cardholder data environment using badge readers, locks, or other mechanisms.',NULL,NULL,1,132,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(397,9,NULL,'9.2.2','Physical and/or logical controls are implemented to restrict use of publicly accessible network jacks','Physical and/or logical controls are implemented to restrict use of publicly accessible network jacks within the facility.',NULL,NULL,1,133,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(398,9,NULL,'9.2.3','Physical access to wireless access points, gateways, and networking/communication hardware is restricted','Physical access to wireless access points, gateways, networking/communications hardware, and telecommunication lines within the facility is restricted.',NULL,NULL,1,134,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(399,9,NULL,'9.2.4','Access to consoles in sensitive areas is restricted via locking when not in use','Access to consoles in sensitive areas is restricted via locking when not in use.',NULL,NULL,1,135,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(400,9,NULL,'9.3','Physical access for personnel and visitors is authorized and managed','Physical access for personnel and visitors is authorized and managed using identification and authentication mechanisms.',NULL,NULL,1,136,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(401,9,NULL,'9.3.1','Procedures for authorizing and managing physical access of personnel to the CDE are implemented','Procedures are implemented for authorizing and managing physical access of personnel to the CDE including identifying and distinguishing between onsite personnel and visitors.',NULL,NULL,1,137,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(402,9,NULL,'9.4','Media with cardholder data is securely stored, accessed, distributed, and destroyed','Media with cardholder data — whether electronic or paper — is securely stored, accessed, distributed, and destroyed when no longer needed.',NULL,NULL,1,138,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(403,9,NULL,'9.4.1','All media with cardholder data is physically secured','All media with cardholder data is physically secured and inventoried, stored in a secure location, and access is restricted to authorized personnel.',NULL,NULL,1,139,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(404,9,NULL,'9.5','Point-of-interaction (POI) devices are protected from tampering and unauthorized substitution','Point of interaction (POI) devices that capture payment card data via direct physical interaction are protected from tampering and unauthorized substitution.',NULL,NULL,1,140,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(405,9,NULL,'9.5.1','POI devices that capture payment card data are protected from tampering and unauthorized substitution','POI devices that capture payment card data via direct physical interaction with the payment card form factor are protected from tampering and unauthorized substitution including maintaining a list of devices, periodic inspections, and training personnel.',NULL,NULL,1,141,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(406,9,NULL,'10.1','Processes and mechanisms for logging and monitoring all access are defined and understood','All security policies and operational procedures related to logging and monitoring all access to system components and cardholder data are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,142,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(407,9,NULL,'10.1.1','All security policies and operational procedures for Requirement 10 are documented and maintained','All security policies and operational procedures identified in Requirement 10 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,143,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(408,9,NULL,'10.1.2','Roles and responsibilities for Requirement 10 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 10 are documented, assigned, and understood by personnel.',NULL,NULL,1,144,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(409,9,NULL,'10.2','Audit logs are implemented to support the detection of anomalies and suspicious activity','Audit logs are implemented to support the detection of anomalies and suspicious activity, and the forensic analysis of events.',NULL,NULL,1,145,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(410,9,NULL,'10.2.1','Audit logs are enabled and active for all system components and cardholder data','Audit logs are enabled and active for all system components and cardholder data. Logs capture all individual user access to cardholder data, actions by anyone with administrative access, access to audit trails, invalid logical access attempts, use of identification and authentication mechanisms, initialization of audit logs, and creation and deletion of system-level objects.',NULL,NULL,1,146,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(411,9,NULL,'10.2.2','Audit logs record sufficient information for each auditable event','Audit logs record the following details for each auditable event: user identification, type of event, date and time, success or failure indication, origination of event, and identity or name of affected data, system component, resource, or service.',NULL,NULL,1,147,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(412,9,NULL,'10.3','Audit logs are protected from destruction and unauthorized modifications','Audit logs are protected from destruction and unauthorized modifications to ensure their integrity.',NULL,NULL,1,148,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(413,9,NULL,'10.3.1','Read access to audit logs files is limited to those with a job-related need','Read access to audit logs files is limited to those with a job-related need.',NULL,NULL,1,149,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(414,9,NULL,'10.3.2','Audit log files are protected from unauthorized modifications','Audit log files are protected to prevent modifications by individuals, and access to audit logs is limited using access control mechanisms, physical segregation, and/or network segregation.',NULL,NULL,1,150,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(415,9,NULL,'10.3.3','Audit log files are promptly backed up to a centralized log server or other media difficult to alter','Audit log files, including those for external-facing technologies, are promptly backed up to a secure, central, internal log server(s) or other media that is difficult to alter.',NULL,NULL,1,151,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(416,9,NULL,'10.3.4','File integrity monitoring or change-detection mechanisms are used on audit logs','File integrity monitoring or change-detection mechanisms are used on audit logs to ensure that existing log data cannot be changed without generating alerts.',NULL,NULL,1,152,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(417,9,NULL,'10.4','Audit logs are reviewed to identify anomalies or suspicious activity','Audit logs are reviewed to identify anomalies or suspicious activity.',NULL,NULL,1,153,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(418,9,NULL,'10.4.1','Audit logs are reviewed at least once daily for security events','The following audit logs are reviewed at least once daily: all security events, logs of all system components that store, process, or transmit CHD/SAD, logs of all critical system components, and logs of all servers and system components that perform security functions.',NULL,NULL,1,154,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(419,9,NULL,'10.4.2','Logs of all other system components are reviewed periodically','Logs of all other system components (those not specified in 10.4.1) are reviewed periodically based on the organization\'s risk assessment.',NULL,NULL,1,155,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(420,9,NULL,'10.5','Audit log history is retained and available for analysis','Audit log history is retained and available for analysis.',NULL,NULL,1,156,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(421,9,NULL,'10.5.1','Retain audit log history for at least 12 months with at least three months immediately available','Retain audit log history for at least 12 months, with at least the most recent three months immediately available for analysis.',NULL,NULL,1,157,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(422,9,NULL,'10.6','Time-synchronization mechanisms support consistent time settings across all systems','Time-synchronization mechanisms support consistent time settings across all systems.',NULL,NULL,1,158,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(423,9,NULL,'10.6.1','System clocks and time are synchronized using time-synchronization technology','System clocks and time are synchronized using time-synchronization technology and kept current. The time synchronization technology is configured to receive time from industry-accepted time sources.',NULL,NULL,1,159,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(424,9,NULL,'10.7','Failures of critical security control systems are detected, reported, and responded to promptly','Failures of critical security control systems are detected, alerted, and addressed promptly.',NULL,NULL,1,160,'2026-03-09 13:56:17','2026-03-09 13:56:17'),
(425,9,NULL,'10.7.1','Additional requirement for service providers: failures of critical security control systems are detected and responded to promptly','Additional requirement for service providers only: failures of critical security control systems are detected, alerted, and addressed promptly including but not limited to failure of firewalls, IDS/IPS, FIM, anti-malware, physical access controls, logical access controls, audit logging mechanisms, and segmentation controls.',NULL,NULL,1,161,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(426,9,NULL,'11.1','Processes and mechanisms for regularly testing security of systems and networks are defined and understood','All security policies and operational procedures related to regularly testing security of systems and networks are documented, kept up to date, in active use, and known to all affected parties.',NULL,NULL,1,162,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(427,9,NULL,'11.1.1','All security policies and operational procedures for Requirement 11 are documented and maintained','All security policies and operational procedures identified in Requirement 11 are documented, kept up to date, in use, and known to all affected parties.',NULL,NULL,1,163,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(428,9,NULL,'11.1.2','Roles and responsibilities for Requirement 11 activities are documented and understood','Roles and responsibilities for performing activities in Requirement 11 are documented, assigned, and understood by personnel.',NULL,NULL,1,164,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(429,9,NULL,'11.2','Wireless access points are identified and monitored, and unauthorized access points are addressed','Wireless access points are identified and monitored, and unauthorized wireless access points are addressed.',NULL,NULL,1,165,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(430,9,NULL,'11.2.1','Authorized and unauthorized wireless access points are managed by testing for the presence of wireless APs quarterly','Authorized and unauthorized wireless access points are managed by testing for the presence of wireless (Wi-Fi) access points on a quarterly basis and identifying and addressing unauthorized wireless access points.',NULL,NULL,1,166,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(431,9,NULL,'11.3','External and internal vulnerabilities are regularly identified, prioritized, and addressed','External and internal vulnerabilities are regularly identified, prioritized, and addressed via vulnerability scanning.',NULL,NULL,1,167,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(432,9,NULL,'11.3.1','Internal vulnerability scans are performed at least once every three months','Internal vulnerability scans are performed at least once every three months and after any significant change. High-risk and critical vulnerabilities are resolved and rescans confirm resolution.',NULL,NULL,1,168,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(433,9,NULL,'11.3.2','External vulnerability scans are performed at least once every three months','External vulnerability scans are performed at least once every three months and after any significant change by a PCI SSC Approved Scanning Vendor (ASV). Scans achieve passing results.',NULL,NULL,1,169,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(434,9,NULL,'11.4','External and internal penetration testing is regularly performed','External and internal penetration testing is regularly performed, and exploitable vulnerabilities and security weaknesses are corrected.',NULL,NULL,1,170,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(435,9,NULL,'11.4.1','A penetration testing methodology is defined, documented, and implemented','A penetration testing methodology is defined, documented, and implemented by the entity, including industry-accepted approaches, coverage of the entire CDE perimeter and critical systems, testing from both inside and outside the network, and application-layer and network-layer tests.',NULL,NULL,1,171,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(436,9,NULL,'11.4.2','Internal penetration testing is performed at least once every 12 months and after significant changes','Internal penetration testing is performed at least once every 12 months and after any significant infrastructure or application upgrade or change.',NULL,NULL,1,172,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(437,9,NULL,'11.4.3','External penetration testing is performed at least once every 12 months and after significant changes','External penetration testing is performed at least once every 12 months and after any significant infrastructure or application upgrade or change.',NULL,NULL,1,173,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(438,9,NULL,'11.5','Network intrusions and unexpected file changes are detected and responded to','Network intrusions and unexpected file changes are detected and responded to.',NULL,NULL,1,174,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(439,9,NULL,'11.5.1','Intrusion-detection and/or intrusion-prevention techniques are used to detect and/or prevent intrusions','Intrusion-detection and/or intrusion-prevention techniques are used to detect and/or prevent intrusions into the network. All traffic at the perimeter of the CDE and at critical points within the CDE is monitored and personnel are alerted to suspected compromises.',NULL,NULL,1,175,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(440,9,NULL,'11.5.2','A change-detection mechanism (file integrity monitoring) is deployed to alert personnel to unauthorized modification of critical files','A change-detection mechanism (for example, file integrity monitoring tools) is deployed to alert personnel to unauthorized modification of critical system files, configuration files, or content files. The tools are configured to perform critical file comparisons at least weekly.',NULL,NULL,1,176,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(441,9,NULL,'11.6','Unauthorized changes on payment pages are detected and responded to','Unauthorized changes on payment pages are detected and responded to.',NULL,NULL,1,177,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(442,9,NULL,'11.6.1','A change- and tamper-detection mechanism is deployed on payment pages to detect unauthorized modifications','A change- and tamper-detection mechanism is deployed to alert personnel to unauthorized modification of the HTTP headers and the contents of payment pages as received by the consumer browser.',NULL,NULL,1,178,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(443,9,NULL,'12.1','A comprehensive information security policy governing protection of cardholder data is known and maintained','A comprehensive information security policy that governs and provides direction for protection of the entity\'s information assets is published, maintained, and disseminated to all relevant personnel and vendors.',NULL,NULL,1,179,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(444,9,NULL,'12.1.1','An overall information security policy is established, published, maintained, and disseminated','An overall information security policy is established, published, maintained, and disseminated to all relevant personnel, as well as relevant vendors and business partners.',NULL,NULL,1,180,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(445,9,NULL,'12.1.2','The information security policy is reviewed at least once every 12 months and updated as needed','The information security policy is reviewed at least once every 12 months and updated as needed to reflect changes to business objectives or the risk environment.',NULL,NULL,1,181,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(446,9,NULL,'12.1.3','The security policy clearly defines information security roles and responsibilities for all personnel','The security policy clearly defines information security roles and responsibilities for all personnel, and all personnel are aware of and acknowledge their information security responsibilities.',NULL,NULL,1,182,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(447,9,NULL,'12.1.4','Responsibility for information security is formally assigned to a CISO or equivalent','Responsibility for information security is formally assigned to a Chief Information Security Officer or other information security knowledgeable member of senior management.',NULL,NULL,1,183,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(448,9,NULL,'12.2','Acceptable use policies for end-user technologies are defined and implemented','Acceptable use policies for end-user technologies are defined and implemented, covering explicit approval, acceptable uses, and an authenticated list of company-approved products.',NULL,NULL,1,184,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(449,9,NULL,'12.3','Risks to the cardholder data environment are formally identified, evaluated, and managed','Risks to the cardholder data environment are formally identified, evaluated, and managed through a targeted risk analysis process.',NULL,NULL,1,185,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(450,9,NULL,'12.3.1','A targeted risk analysis is performed for each PCI DSS requirement providing flexibility in how frequently the activity is performed','Each PCI DSS requirement that provides flexibility for how frequently it is performed is supported by a targeted risk analysis that is documented, includes justification, is reviewed at least annually, and is performed by responsible personnel.',NULL,NULL,1,186,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(451,9,NULL,'12.4','PCI DSS compliance is managed throughout the year','PCI DSS compliance is managed throughout the year by establishing a program for continuous monitoring and testing of security controls.',NULL,NULL,1,187,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(452,9,NULL,'12.5','PCI DSS scope is documented and validated','PCI DSS scope is documented and validated by the entity at least once every 12 months and upon significant change to the in-scope environment.',NULL,NULL,1,188,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(453,9,NULL,'12.5.1','An inventory of system components in scope for PCI DSS is maintained and kept current','An inventory of system components that are in scope for PCI DSS, including a description of function/use for each, is maintained and kept current.',NULL,NULL,1,189,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(454,9,NULL,'12.5.2','PCI DSS scope is documented and confirmed at least once every 12 months and upon significant change','PCI DSS scope is documented and confirmed by the entity at least once every 12 months and upon significant change to the in-scope environment. At a minimum, the scoping validation includes identifying all locations and flows of account data, identifying all systems connected to or that could impact the CDE, and validating that the scope is properly documented.',NULL,NULL,1,190,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(455,9,NULL,'12.6','Security awareness education is an ongoing activity','Security awareness education is an ongoing activity for all personnel.',NULL,NULL,1,191,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(456,9,NULL,'12.6.1','A formal security awareness program is implemented for all personnel','A formal security awareness program is implemented to make all personnel aware of the entity\'s cardholder data security policy and procedures.',NULL,NULL,1,192,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(457,9,NULL,'12.6.2','The security awareness program is reviewed at least once every 12 months and updated','The security awareness program is reviewed at least once every 12 months and updated as needed to address any new threats and vulnerabilities.',NULL,NULL,1,193,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(458,9,NULL,'12.6.3','Personnel receive security awareness training upon hire and at least once every 12 months','Personnel receive security awareness training upon hire and at least once every 12 months, and acknowledge at least once every 12 months that they have read and understood the information security policy and procedures.',NULL,NULL,1,194,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(459,9,NULL,'12.7','Personnel are screened to reduce risks from insider threats','Personnel are screened prior to hire to reduce risks from insider threats.',NULL,NULL,1,195,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(460,9,NULL,'12.8','Risk to information assets associated with third-party service provider (TPSP) relationships is managed','Risk to information assets associated with third-party service provider relationships is managed.',NULL,NULL,1,196,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(461,9,NULL,'12.8.1','A list of all third-party service providers with which account data is shared is maintained','A list of all third-party service providers (TPSPs) with which account data is shared or that could affect the security of account data is maintained, including a description of the services provided.',NULL,NULL,1,197,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(462,9,NULL,'12.8.2','Written agreements/contracts are maintained with all TPSPs','Written agreements/contracts are maintained with all TPSPs with which account data is shared or that could affect the security of the CDE.',NULL,NULL,1,198,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(463,9,NULL,'12.8.3','An established process for engaging TPSPs includes proper due diligence prior to engagement','An established process is implemented for engaging TPSPs, including proper due diligence prior to engagement.',NULL,NULL,1,199,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(464,9,NULL,'12.8.4','A program is implemented to monitor TPSPs PCI DSS compliance status at least once every 12 months','A program is implemented to monitor TPSPs\' PCI DSS compliance status at least once every 12 months.',NULL,NULL,1,200,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(465,9,NULL,'12.8.5','Information is maintained about which PCI DSS requirements are managed by each TPSP and which by the entity','Information is maintained about which PCI DSS requirements are managed by each TPSP, which are managed by the entity, and any that are shared between the TPSP and the entity.',NULL,NULL,1,201,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(466,9,NULL,'12.9','Third-party service providers support their customers\' PCI DSS compliance','Third-party service providers (TPSPs) support their customers\' PCI DSS compliance through written agreements and compliance validation.',NULL,NULL,1,202,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(467,9,NULL,'12.9.1','Additional requirement for TPSPs: TPSPs acknowledge in writing to customers their responsibility for cardholder data security','Additional requirement for TPSPs only: TPSPs provide written acknowledgement to customers that the TPSP is responsible for the security of account data the TPSP possesses or otherwise stores, processes, or transmits on behalf of the customer.',NULL,NULL,1,203,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(468,9,NULL,'12.10','Suspected and confirmed security incidents that could impact the CDE are responded to immediately','Suspected and confirmed security incidents that could impact the CDE are responded to immediately.',NULL,NULL,1,204,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(469,9,NULL,'12.10.1','An incident response plan exists and is ready to be activated in the event of a suspected or confirmed security incident','An incident response plan exists and is ready to be activated in the event of a suspected or confirmed security incident. The plan addresses: roles, responsibilities, and communication; incident response procedures; business recovery and continuity procedures; data backup processes; legal requirements for reporting compromises; and coverage for all critical system components.',NULL,NULL,1,205,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(470,9,NULL,'12.10.2','The security incident response plan is reviewed and tested at least annually','The security incident response plan is reviewed and tested at least once every 12 months, including all elements listed in Requirement 12.10.1.',NULL,NULL,1,206,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(471,9,NULL,'12.10.3','Specific personnel are designated to be available on a 24/7 basis to respond to suspected or confirmed security incidents','Specific personnel are designated to be available on a 24/7 basis to respond to suspected or confirmed security incidents.',NULL,NULL,1,207,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(472,9,NULL,'12.10.4','Personnel responsible for responding to security incidents are appropriately and periodically trained','Personnel responsible for responding to suspected and confirmed security incidents are appropriately and periodically trained on their incident response responsibilities.',NULL,NULL,1,208,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(473,9,NULL,'12.10.5','The security incident response plan includes monitoring and responding to alerts from security monitoring systems','The security incident response plan includes monitoring and responding to alerts from security monitoring systems, including but not limited to intrusion-detection and intrusion-prevention systems, firewalls, file integrity monitoring systems, and change-detection mechanisms for payment pages.',NULL,NULL,1,209,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(474,9,NULL,'12.10.6','The security incident response plan is modified and evolved according to lessons learned','The security incident response plan is modified and evolved according to lessons learned and to incorporate industry developments.',NULL,NULL,1,210,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(475,2,NULL,'A.5.1','Policies for Information Security','Information security policy and topic-specific policies shall be defined, approved by management, published, communicated to and acknowledged by relevant personnel and relevant interested parties, and reviewed at planned intervals and if significant changes occur. This ensures a consistent management direction and support for information security in accordance with business requirements and relevant laws and regulations.',NULL,NULL,1,0,'2026-03-09 13:56:22','2026-03-09 14:01:26'),
(476,5,NULL,'GV.PO-01','Cybersecurity Policy Establishment','A policy for managing cybersecurity risks is established based on organizational context, cybersecurity strategy, and priorities and is communicated and enforced. The policy reflects the organization\'s risk appetite, mission objectives, and regulatory requirements. It is reviewed and updated at defined intervals or when significant changes occur.',NULL,NULL,1,0,'2026-03-09 13:56:22','2026-03-09 14:01:11'),
(477,6,NULL,'CA.L2-3.12.1','Security Control Assessment','Periodically assess the security controls in organizational systems to determine if the controls are effective in their application. Assessments must evaluate whether controls are implemented correctly, operating as intended, and producing the desired outcome with respect to meeting security requirements.',NULL,NULL,1,0,'2026-03-09 13:56:22','2026-03-09 14:01:17'),
(478,10,NULL,'164.308(a)(1)(i)','Security Management Process','Implement policies and procedures to prevent, detect, contain, and correct security violations. This standard requires a covered entity to establish a security management process that encompasses the full lifecycle of information security governance for electronic protected health information (ePHI).',NULL,NULL,1,0,'2026-03-09 13:56:22','2026-03-09 14:01:22'),
(479,11,NULL,'1.1','Establish and Maintain Detailed Enterprise Asset Inventory','Establish and maintain an accurate, detailed, and up-to-date inventory of all enterprise assets with the potential to store or process data, to include end-user devices, network devices, IoT devices, and servers. Ensure the inventory records the network address, hardware address, machine name, enterprise asset owner, department, and whether the asset is approved to connect to the network. Review and update the inventory of all enterprise assets bi-annually, or more frequently.',NULL,NULL,1,0,'2026-03-09 13:56:22','2026-03-09 14:01:04'),
(480,2,NULL,'A.5.2','Information Security Roles and Responsibilities','Information security roles and responsibilities shall be defined and allocated. All information security responsibilities shall be clearly assigned and communicated to ensure that security activities are carried out effectively and that accountability is established across the organization.',NULL,NULL,1,0,'2026-03-09 13:56:22','2026-03-09 14:01:26'),
(481,5,NULL,'GV.RR-01','Organizational Leadership Accountability','Organizational leadership is responsible and accountable for cybersecurity risk and fosters a culture of cybersecurity risk awareness. Senior leaders demonstrate commitment through resource allocation, policy endorsement, and active participation in risk governance. Accountability is clearly defined and communicated throughout the organization.',NULL,NULL,1,0,'2026-03-09 13:56:22','2026-03-09 14:01:11'),
(482,10,NULL,'164.308(a)(2)','Assigned Security Responsibility','Identify the security official who is responsible for the development and implementation of the policies and procedures required by the Security Rule for the covered entity or business associate. This designated security official has overall accountability for ensuring the organization\'s compliance with the HIPAA Security Rule.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:22'),
(484,2,NULL,'A.5.4','Management Responsibilities','Management shall require all personnel to apply information security in accordance with the established information security policy, topic-specific policies, and procedures of the organization. This ensures leadership commitment and that security obligations are understood and fulfilled throughout the workforce.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:26'),
(485,5,NULL,'GV.RR-02','Roles and Responsibilities Establishment','Roles, responsibilities, and authorities related to cybersecurity risk management are established, communicated, understood, and enforced. Clear assignment of cybersecurity duties ensures accountability and prevents gaps in coverage. Responsibilities are documented and aligned with organizational structures and reporting lines.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:11'),
(486,5,NULL,'GV.OC-03','Legal, Regulatory, and Contractual Requirements','Legal, regulatory, and contractual requirements regarding cybersecurity, including privacy and civil liberties obligations, are understood and managed. The organization identifies applicable laws, regulations, standards, and contractual commitments and incorporates them into the cybersecurity program. Compliance obligations are tracked and reviewed periodically.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:11'),
(487,5,NULL,'GV.OC-01','Organizational Mission Understanding','The organizational mission is understood and informs cybersecurity risk management. Leadership ensures that the mission, stakeholder expectations, and legal and regulatory requirements are considered when establishing and refining the cybersecurity program. This alignment ensures cybersecurity decisions support overall business objectives.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:11'),
(488,6,NULL,'CA.L2-3.12.4','System Security Plan','Develop, document, and periodically update system security plans that describe system boundaries, system environments of operation, how security requirements are implemented, and the relationships with or connections to other systems. The system security plan serves as the principal document for the organization\'s security authorization process.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:17'),
(489,2,NULL,'A.5.7','Threat Intelligence','Information relating to information security threats shall be collected and analyzed to produce threat intelligence. This intelligence shall be used to inform decision-making on security controls, risk assessment, and organizational preparedness against current and emerging threats.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:26'),
(490,5,NULL,'GV.RM-01','Risk Management Objectives','Risk management objectives are established and agreed to by organizational stakeholders. The objectives articulate the organization\'s risk tolerance, appetite, and capacity, guiding how cybersecurity risks are identified, assessed, and treated. These objectives are aligned with the broader enterprise risk management strategy.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:11'),
(491,5,NULL,'GV.RM-02','Risk Appetite and Tolerance Statements','Risk appetite and risk tolerance statements are established, communicated, and maintained. These statements define the level and types of risk the organization is willing to accept in pursuit of its objectives. They are used to guide risk response decisions and ensure consistent risk management across the organization.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:11'),
(492,6,NULL,'RA.L2-3.11.1','Risk Assessments','Periodically assess the risk to organizational operations (including mission, functions, image, or reputation), organizational assets, and individuals, resulting from the operation of organizational systems and the associated processing, storage, or transmission of CUI. Risk assessments must identify threats, vulnerabilities, and potential impacts to support informed risk management decisions.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:17'),
(493,10,NULL,'164.308(a)(1)(ii)(A)','Risk Analysis','Conduct an accurate and thorough assessment of the potential risks and vulnerabilities to the confidentiality, integrity, and availability of electronic protected health information held by the covered entity or business associate. The risk analysis must be an ongoing process that identifies and evaluates reasonably anticipated threats to ePHI.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:22'),
(494,2,NULL,'A.5.31','Legal, Statutory, Regulatory and Contractual Requirements','Legal, statutory, regulatory, and contractual requirements relevant to information security and the organization\'s approach to meet these requirements shall be identified, documented, and kept up to date. This ensures ongoing compliance and reduces the risk of legal or regulatory penalties.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:27'),
(495,5,NULL,'GV.OC-02','Internal and External Stakeholders Understanding','Internal and external stakeholders are identified and their needs and expectations regarding cybersecurity risk management are understood. The organization maintains awareness of stakeholder dependencies, contractual obligations, and expectations. This understanding informs the prioritization of cybersecurity activities and resource allocation.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:11'),
(497,10,NULL,'164.316(a)','Policies and Procedures','Implement reasonable and appropriate policies and procedures to comply with the standards, implementation specifications, and other requirements of the Security Rule. Policies and procedures must be maintained in written or electronic form and must be reviewed and updated periodically in response to environmental or operational changes.',NULL,NULL,1,0,'2026-03-09 13:56:23','2026-03-09 14:01:23'),
(498,5,NULL,'GV.RR-03','Adequate Resources for Cybersecurity','Adequate resources are allocated commensurate with the cybersecurity risk strategy, roles, responsibilities, and policies. The organization ensures sufficient budget, staffing, tools, and training to execute cybersecurity functions effectively. Resource allocation is reviewed periodically and adjusted based on evolving risks and organizational changes.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:11'),
(499,5,NULL,'GV.RR-04','Cybersecurity Workforce Planning','Cybersecurity is included in human resources practices to establish, maintain, and improve organizational cybersecurity. Recruitment, retention, training, and professional development programs support a skilled cybersecurity workforce. Background checks, role-based access, and separation of duties are integrated into personnel management.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:11'),
(500,5,NULL,'GV.OC-04','Critical Objectives and Services','Critical objectives, capabilities, and services that stakeholders depend on or expect are understood and communicated. The organization identifies and prioritizes its most critical business functions, services, and associated assets. This prioritization guides cybersecurity risk management decisions and resource allocation.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:11'),
(501,2,NULL,'A.5.35','Independent Review of Information Security','The organization\'s approach to managing information security and its implementation, including people, processes, and technologies, shall be reviewed independently at planned intervals or when significant changes occur. This provides an objective assessment of the effectiveness and suitability of the information security management system.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:27'),
(502,5,NULL,'ID.IM-01','Cybersecurity Program Improvement Identification','Improvements are identified from evaluations of existing cybersecurity practices, including through security assessments, audits, and reviews. The organization systematically evaluates the effectiveness of its cybersecurity controls and processes. Identified gaps and weaknesses are documented and tracked through remediation to drive continuous improvement.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:12'),
(504,5,NULL,'GV.PO-02','Cybersecurity Policy Review','Policy for managing cybersecurity risks is reviewed, updated, communicated, and enforced to reflect changes in requirements, threats, technology, and organizational mission. The review process involves relevant stakeholders and considers lessons learned from incidents, audits, and assessments. Updated policies are disseminated to all affected parties.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:11'),
(505,10,NULL,'164.308(a)(1)(ii)(C)','Sanction Policy','Apply appropriate sanctions against workforce members who fail to comply with the security policies and procedures of the covered entity or business associate. The sanction policy must define the consequences for noncompliance with security policies, including disciplinary actions up to and including termination of employment.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:22'),
(506,5,NULL,'GV.RM-07','Strategic Opportunities from Risk Management','Strategic opportunities, including positive risks, are characterized and included in organizational cybersecurity risk discussions. The organization considers how cybersecurity investments can enable business opportunities, competitive advantage, and innovation. Risk management processes account for both threats and opportunities in decision-making.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:11'),
(507,2,NULL,'A.5.8','Information Security in Project Management','Information security shall be integrated into project management regardless of the type of project. This ensures that information security risks are identified and addressed as part of project planning and execution throughout the project lifecycle.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:26'),
(508,5,NULL,'GV.OC-05','Outcomes and Dependencies for Critical Services','Outcomes, capabilities, and services that the organization depends on are understood and communicated. Dependencies on external parties, supply chain elements, and shared infrastructure are identified and documented. Risk associated with these dependencies is assessed and managed to ensure continuity of critical services.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:11'),
(509,2,NULL,'A.5.18','Access Rights','Access rights to information and other associated assets shall be provisioned, reviewed, modified, and removed in accordance with the organization\'s topic-specific policy on and rules for access control. This ensures the principle of least privilege is maintained and access is revoked when no longer required.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:26'),
(510,5,NULL,'PR.AA-01','Identity and Credential Management','Identities and credentials for authorized users, services, and hardware are managed by the organization. Identity lifecycle management processes cover provisioning, maintenance, and deprovisioning of accounts and credentials. Strong credential policies including complexity, rotation, and secure storage are enforced across the enterprise.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:12'),
(511,6,NULL,'AC.L2-3.1.1','Authorized Access Control','Limit information system access to authorized users, processes acting on behalf of authorized users, or devices (including other information systems). Organizations must define and enforce access policies that restrict system access to only those entities with a legitimate need.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:17'),
(512,10,NULL,'164.312(a)(2)(i)','Unique User Identification','Assign a unique name and/or number for identifying and tracking user identity. Each workforce member who accesses electronic protected health information must have a unique user identifier to ensure that system activity can be attributed to a specific individual and to support audit trail and accountability requirements.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:22'),
(513,11,NULL,'5.1','Establish and Maintain an Inventory of Accounts','Establish and maintain an inventory of all accounts managed in the enterprise. The inventory must include both user and administrator accounts. The inventory, at a minimum, should contain the person\'s name, username, start/stop dates, and department. Validate that all active accounts are authorized on a recurring schedule, at a minimum quarterly.',NULL,NULL,1,0,'2026-03-09 13:56:24','2026-03-09 14:01:05'),
(514,2,NULL,'A.5.15','Access Control','Rules to control physical and logical access to information and other associated assets shall be established and implemented based on business and information security requirements. Access control policies shall ensure that only authorized individuals can access resources appropriate to their role.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:26'),
(515,5,NULL,'PR.AA-05','Access Permissions and Authorizations','Access permissions, entitlements, and authorizations are defined in a policy, managed, enforced, and reviewed, and incorporate the principles of least privilege and separation of duties. Role-based or attribute-based access control mechanisms ensure that users only access resources necessary for their job functions. Access reviews are conducted periodically to identify and remediate excessive privileges.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:12'),
(516,6,NULL,'AC.L2-3.1.5','Least Privilege','Employ the principle of least privilege, including for specific security functions and privileged accounts. Users and processes must be granted only the minimum access rights and permissions necessary to perform their assigned duties and functions.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:17'),
(517,10,NULL,'164.312(a)(1)','Access Control','Implement technical policies and procedures for electronic information systems that maintain electronic protected health information to allow access only to those persons or software programs that have been granted access rights as specified in the information access management standard. Access controls must enforce authorized access at the system and application levels.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:22'),
(518,11,NULL,'6.1','Establish an Access Granting Process','Establish and follow a process, preferably automated, for granting access to enterprise assets upon new hire, rights grant, or role change of a user. The process should include a formal request and approval workflow to ensure access is granted based on legitimate business need and appropriate authorization.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:05'),
(520,2,NULL,'A.8.5','Secure Authentication','Secure authentication technologies and procedures shall be established and implemented based on information access restrictions and the topic-specific policy on access control. Authentication mechanisms shall include multi-factor authentication where appropriate and protection against brute force and credential-based attacks.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:27'),
(521,5,NULL,'PR.AA-03','Multi-Factor Authentication','Users, services, and hardware are authenticated. Multi-factor authentication is implemented for access to critical systems and sensitive data. Authentication mechanisms are selected based on risk assessment and are resistant to common attack techniques including phishing, credential stuffing, and replay attacks.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:12'),
(522,6,NULL,'IA.L2-3.5.3','Multifactor Authentication','Use multifactor authentication for local and network access to privileged accounts and for network access to non-privileged accounts. Multifactor authentication requires two or more different factors such as something you know, something you have, or something you are to verify user identity.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:17'),
(523,10,NULL,'164.312(d)','Person or Entity Authentication','Implement procedures to verify that a person or entity seeking access to electronic protected health information is the one claimed. Authentication mechanisms may include passwords, biometrics, tokens, smart cards, or other multi-factor methods to ensure that the identity of users accessing ePHI is properly validated.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:23'),
(524,11,NULL,'6.3','Require MFA for Externally-Exposed Applications','Require all externally-exposed enterprise or third-party applications to enforce multi-factor authentication (MFA). Enforcing MFA through a directory service or SSO provider is a satisfactory implementation of this safeguard. This significantly reduces the risk of credential-based attacks against internet-facing systems.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:05'),
(525,2,NULL,'A.8.2','Privileged Access Rights','The allocation and use of privileged access rights shall be restricted and managed. Privileged accounts shall be controlled through separate authentication, time-limited access, and enhanced monitoring to minimize the risk of unauthorized use of elevated system permissions.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:27'),
(526,6,NULL,'AC.L2-3.1.7','Privileged Functions','Prevent non-privileged users from executing privileged functions and capture the execution of such functions in audit logs. Organizations must enforce role-based restrictions that limit privileged operations to authorized administrators and maintain an auditable record of all privileged activity.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:17'),
(527,11,NULL,'6.5','Require MFA for Administrative Access','Require MFA for all administrative access accounts, where supported, on all enterprise assets, whether managed on-site or through a third-party provider. Administrative accounts with elevated privileges are high-value targets for attackers, making MFA a critical safeguard for these accounts.',NULL,NULL,1,0,'2026-03-09 13:56:25','2026-03-09 14:01:05'),
(530,2,NULL,'A.5.17','Authentication Information','Allocation and management of authentication information shall be controlled by a management process, including advising personnel on the appropriate handling of authentication information. This ensures that passwords, tokens, and other authentication credentials are securely managed throughout their lifecycle.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:26'),
(531,5,NULL,'PR.AA-04','Identity Assertions Protection','Identity assertions are protected, conveyed, and verified. The organization ensures that identity tokens, assertions, and federation protocols are implemented securely and validated appropriately. Assertions are protected against tampering, replay, and unauthorized disclosure throughout their lifecycle.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:12'),
(532,6,NULL,'IA.L2-3.5.7','Password Complexity','Enforce a minimum password complexity and change of characters when new passwords are created. Password policies must require a combination of upper-case, lower-case, numeric, and special characters with minimum length requirements to resist brute-force and dictionary attacks.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:17'),
(533,11,NULL,'5.2','Use Unique Passwords','Use unique passwords for all enterprise assets. Best practice implementation includes, at a minimum, an 8-character password for accounts using MFA and a 14-character password for accounts not using MFA. Passwords must not be reused across multiple systems or accounts within the enterprise environment.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:05'),
(534,6,NULL,'IA.L2-3.5.1','Identification','Identify information system users, processes acting on behalf of users, or devices. Organizations must uniquely identify all entities that access organizational systems to ensure accountability and enable effective access control enforcement.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:17'),
(535,11,NULL,'6.7','Centralize Access Control','Centralize access control for all enterprise assets through a directory service or SSO provider, where supported. Centralized access control enables consistent enforcement of authentication policies, simplifies access management, and provides comprehensive audit trails for access-related events.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:05'),
(536,11,NULL,'5.4','Restrict Administrator Privileges to Dedicated Administrator Accounts','Restrict administrator privileges to dedicated administrator accounts on enterprise assets. Conduct general computing activities, such as internet browsing, email, and productivity suite use, from the user\'s primary, non-privileged account. This separation reduces the attack surface for privilege escalation attacks.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:05'),
(537,2,NULL,'A.8.1','User Endpoint Devices','Information stored on, processed by, or accessible via user endpoint devices shall be protected. Security measures shall include device configuration management, encryption, malware protection, and remote wipe capabilities to safeguard information on laptops, smartphones, tablets, and other endpoint devices.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:27'),
(538,5,NULL,'PR.AA-02','Identity Proofing','Identities are proofed and bound to credentials based on the context of interactions. The organization verifies the identity of users, devices, and services before issuing credentials and granting access. Identity proofing strength is commensurate with the risk level of the resources being accessed.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:12'),
(539,6,NULL,'AC.L2-3.1.12','Control Remote Access','Monitor and control remote access sessions. Organizations must establish usage restrictions, configuration requirements, and connection requirements for each type of remote access, and must authorize remote access prior to allowing such connections.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:17'),
(540,10,NULL,'164.312(e)(1)','Transmission Security','Implement technical security measures to guard against unauthorized access to electronic protected health information that is being transmitted over an electronic communications network. Transmission security controls must address the protection of ePHI in transit, including the use of encryption and integrity controls for data transmitted over public or untrusted networks.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:23'),
(541,11,NULL,'6.4','Require MFA for Remote Network Access','Require MFA for remote network access. Multi-factor authentication provides an additional layer of security for users connecting to the enterprise network remotely, such as through VPN or other remote access solutions. This safeguard mitigates the risk of compromised credentials being used for unauthorized remote access.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:05'),
(542,2,NULL,'A.5.16','Identity Management','The full lifecycle of identities shall be managed. This includes the creation, verification, provisioning, suspension, and removal of user identities to ensure that only legitimate and authorized individuals are granted access to organizational systems and information.',NULL,NULL,1,0,'2026-03-09 13:56:26','2026-03-09 14:01:26'),
(543,6,NULL,'AC.L2-3.1.2','Transaction & Function Control','Limit information system access to the types of transactions and functions that authorized users are permitted to execute. Access controls must enforce authorized operations at the application and system levels to prevent users from exceeding their assigned privileges.',NULL,NULL,1,0,'2026-03-09 13:56:27','2026-03-09 14:01:17'),
(544,5,NULL,'PR.AA-06','Physical Access Management','Physical access to assets is managed, monitored, and enforced commensurate with risk. The organization controls entry to facilities, data centers, and sensitive areas using appropriate mechanisms such as badges, biometrics, and security guards. Physical access logs are maintained and reviewed to detect unauthorized access attempts.',NULL,NULL,1,0,'2026-03-09 13:56:27','2026-03-09 14:01:12'),
(545,6,NULL,'AC.L2-3.1.10','Session Lock','Use session lock with pattern-hiding displays to prevent access and viewing of data after a period of inactivity. Systems must automatically activate a session lock after a defined idle timeout, requiring the user to re-authenticate before regaining access.',NULL,NULL,1,0,'2026-03-09 13:56:27','2026-03-09 14:01:17'),
(546,11,NULL,'5.6','Centralize Account Management','Centralize account management through a directory or identity service. This safeguard ensures consistent enforcement of account policies across the enterprise and simplifies the processes of provisioning, deprovisioning, and auditing user accounts and their access privileges.',NULL,NULL,1,0,'2026-03-09 13:56:27','2026-03-09 14:01:05'),
(547,6,NULL,'IA.L2-3.5.2','Authentication','Authenticate (or verify) the identities of those users, processes, or devices, as a prerequisite to allowing access to organizational information systems. Authentication mechanisms must verify claimed identities before granting access to system resources containing CUI.',NULL,NULL,1,0,'2026-03-09 13:56:27','2026-03-09 14:01:17'),
(548,11,NULL,'5.3','Disable Dormant Accounts','Delete or disable any dormant accounts after a period of 45 days of inactivity, where supported. Dormant accounts represent a significant security risk as they may be targeted by attackers for unauthorized access without being noticed by their legitimate owners.',NULL,NULL,1,0,'2026-03-09 13:56:27','2026-03-09 14:01:05'),
(549,2,NULL,'A.5.12','Classification of Information','Information shall be classified according to the information security needs of the organization based on confidentiality, integrity, availability, and relevant interested party requirements. A consistent classification scheme shall be applied to ensure appropriate levels of protection are provided.',NULL,NULL,1,0,'2026-03-09 13:56:27','2026-03-09 14:01:26'),
(550,5,NULL,'ID.AM-07','Asset Prioritization by Criticality','Inventories of data and corresponding metadata for designated data types are maintained. Assets are prioritized based on their classification, criticality, and business value. This prioritization informs protection strategies, recovery priorities, and resource allocation for cybersecurity risk management.',NULL,NULL,1,0,'2026-03-09 13:56:27','2026-03-09 14:01:12'),
(551,6,NULL,'MP.L2-3.8.1','Media Protection','Protect (i.e., physically control and securely store) system media containing CUI, both paper and digital. Organizations must implement physical and logical safeguards to prevent unauthorized access to, modification of, or destruction of media containing controlled unclassified information.',NULL,NULL,1,0,'2026-03-09 13:56:27','2026-03-09 14:01:17'),
(552,11,NULL,'3.1','Establish and Maintain a Data Management Process','Establish and maintain a data management process. In the process, address data sensitivity, data owner, handling of data, data retention limits, and disposal requirements based on sensitivity and retention standards for the enterprise. Review and update documentation annually, or when significant enterprise changes occur that could impact this safeguard.',NULL,NULL,1,0,'2026-03-09 13:56:27','2026-03-09 14:01:05'),
(553,2,NULL,'A.8.24','Use of Cryptography','Rules for the effective use of cryptography, including cryptographic key management, shall be defined and implemented. Cryptographic controls shall protect the confidentiality, authenticity, and integrity of information using encryption, digital signatures, and other cryptographic techniques appropriate to the risk.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:28'),
(554,5,NULL,'PR.DS-01','Data-at-Rest Protection','The confidentiality, integrity, and availability of data-at-rest are protected. The organization implements encryption, access controls, and integrity verification mechanisms for stored data. Data classification drives the selection and strength of protection measures applied to data in databases, file systems, and storage media.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:12'),
(555,6,NULL,'SC.L2-3.13.16','Data at Rest','Protect the confidentiality of CUI at rest. Organizations must implement encryption or other approved mechanisms to safeguard CUI stored on digital media, in databases, and on file systems to prevent unauthorized access to data when it is not being actively transmitted or processed.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:18'),
(556,10,NULL,'164.312(a)(2)(iv)','Encryption and Decryption','Implement a mechanism to encrypt and decrypt electronic protected health information. Encryption converts ePHI into a form that cannot be read without the use of a confidential process or key, providing protection for data at rest and ensuring that unauthorized access to storage media does not result in a breach of ePHI.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:22'),
(557,11,NULL,'3.6','Encrypt Data on End-User Devices','Encrypt data on end-user devices containing sensitive data. Example implementations can include Windows BitLocker, Apple FileVault, Linux dm-crypt, or similar encryption mechanisms. This safeguard protects data at rest on enterprise endpoints against unauthorized physical access or theft.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:05'),
(558,5,NULL,'PR.DS-02','Data-in-Transit Protection','The confidentiality, integrity, and availability of data-in-transit are protected. The organization uses encryption protocols such as TLS, IPsec, and SSH to protect data during transmission across networks. Data-in-transit protections are applied to both internal and external communications based on data sensitivity and risk assessment.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:12'),
(559,6,NULL,'SC.L2-3.13.8','Data in Transit','Implement cryptographic mechanisms to prevent unauthorized disclosure of CUI during transmission unless otherwise protected by alternative physical safeguards. All CUI transmitted across networks must be encrypted using FIPS-validated cryptographic modules and protocols such as TLS 1.2 or higher.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:18'),
(560,11,NULL,'3.10','Encrypt Sensitive Data in Transit','Encrypt sensitive data in transit using up-to-date and trusted cryptographic protocols. Example implementations can include Transport Layer Security (TLS) and Open Secure Shell (OpenSSH). This safeguard ensures that sensitive information cannot be intercepted or tampered with during transmission across networks.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:05'),
(561,2,NULL,'A.8.12','Data Leakage Prevention','Data leakage prevention measures shall be applied to systems, networks, and any other devices that process, store, or transmit sensitive information. These measures shall detect and prevent the unauthorized extraction or disclosure of information through monitoring, blocking, and alerting mechanisms.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:28'),
(562,5,NULL,'PR.DS-10','Data-in-Use Protection','The confidentiality, integrity, and availability of data-in-use are protected. The organization implements controls to protect data while it is being processed or accessed in memory, including protections against unauthorized access and data leakage. Technologies such as secure enclaves, memory encryption, and access controls safeguard data during active processing.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:12'),
(563,6,NULL,'MP.L2-3.8.3','Media Disposal','Sanitize or destroy information system media containing Federal Contract Information before disposal or release for reuse. Organizations must use approved sanitization methods such as clearing, purging, or physical destruction to ensure that data cannot be recovered from decommissioned media.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:17'),
(564,10,NULL,'164.312(c)(1)','Integrity','Implement policies and procedures to protect electronic protected health information from improper alteration or destruction. Integrity controls must ensure that ePHI is not altered or destroyed in an unauthorized manner, and must include mechanisms to detect unauthorized modifications to data at rest and in transit.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:23'),
(565,11,NULL,'3.13','Deploy a Data Loss Prevention Solution','Implement an automated tool, such as a host-based Data Loss Prevention (DLP) solution, to identify all sensitive data stored, processed, or transmitted through enterprise assets. This includes monitoring and blocking unauthorized transfers of sensitive data across network boundaries and endpoint devices.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:05'),
(566,2,NULL,'A.5.34','Privacy and Protection of Personal Identifiable Information (PII)','The organization shall identify and meet the requirements regarding the preservation of privacy and protection of PII as required by applicable laws, regulations, and contractual requirements. Appropriate technical and organizational measures shall be implemented to safeguard personal data.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:27'),
(567,2,NULL,'A.5.33','Protection of Records','Records shall be protected from loss, destruction, falsification, unauthorized access, and unauthorized release in accordance with legal, statutory, regulatory, contractual, and business requirements. Appropriate storage, handling, and retention controls shall be implemented for all organizational records.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:27'),
(568,10,NULL,'164.310(d)(2)(i)','Disposal','Implement policies and procedures to address the final disposition of electronic protected health information and the hardware or electronic media on which it is stored. Disposal methods must render ePHI unusable, unreadable, or indecipherable to unauthorized individuals, including through degaussing, overwriting, physical destruction, or secure wiping.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:22'),
(569,11,NULL,'3.3','Configure Data Access Control Lists','Configure data access control lists based on a user\'s need to know. Apply data access control lists, also known as access permissions, to local and remote file systems, databases, and applications. Ensure that only authorized users have access to data based on their role and responsibilities.',NULL,NULL,1,0,'2026-03-09 13:56:28','2026-03-09 14:01:05'),
(570,10,NULL,'164.530(c)','Administrative Requirements - Safeguards','A covered entity must have in place appropriate administrative, technical, and physical safeguards to protect the privacy of protected health information. The safeguards must reasonably protect PHI from any intentional or unintentional use or disclosure that is in violation of the Privacy Rule, including limiting incidental uses and disclosures.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:23'),
(571,2,NULL,'A.8.11','Data Masking','Data masking shall be used in accordance with the organization\'s topic-specific policy on access control and other related topic-specific policies, and business requirements, taking into consideration applicable legislation. Data masking techniques shall protect sensitive information by obscuring original data values in non-production environments.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:28'),
(572,10,NULL,'164.514(a)','De-identification of Protected Health Information','Health information that does not identify an individual and with respect to which there is no reasonable basis to believe that the information can be used to identify an individual is not individually identifiable health information. De-identification may be achieved through expert determination or the safe harbor method by removing specified identifiers.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:23'),
(573,11,NULL,'3.12','Segment Data Processing and Storage Based on Sensitivity','Segment data processing and storage based on the sensitivity of the data. Do not process sensitive data on enterprise assets intended for lower sensitivity data. Network segmentation and access controls should be used to isolate systems that handle sensitive data from the rest of the enterprise network.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:05'),
(574,2,NULL,'A.8.3','Information Access Restriction','Access to information and other associated assets shall be restricted in accordance with the established topic-specific policy on access control. Application-level controls shall enforce authorized access and prevent unauthorized disclosure, modification, or deletion of information.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:27'),
(575,6,NULL,'AC.L2-3.1.3','Control CUI Flow','Control the flow of CUI in accordance with approved authorizations. Organizations must enforce policies that govern how controlled unclassified information moves between systems, network segments, and organizational boundaries to prevent unauthorized disclosure.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:17'),
(576,2,NULL,'A.8.13','Information Backup','Backup copies of information, software, and systems shall be maintained and regularly tested in accordance with the agreed topic-specific policy on backup. Backup procedures shall ensure the availability and integrity of critical information and support timely restoration after incidents or disasters.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:28'),
(577,5,NULL,'PR.DS-09','Data Backup and Recovery','Backups of data are created, protected, maintained, and tested consistent with the organization\'s data protection strategy. Backup processes ensure data can be restored to meet recovery time and recovery point objectives. Backups are encrypted, stored securely, and tested periodically to verify their integrity and recoverability.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:12'),
(578,6,NULL,'MP.L2-3.8.9','Protect Backups','Protect the confidentiality of backup CUI at storage locations. Organizations must apply the same level of protection to CUI stored in backups as is applied to the primary storage, including encryption, access controls, and physical security at backup storage facilities.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:17'),
(579,10,NULL,'164.308(a)(7)(ii)(A)','Data Backup Plan','Establish and implement procedures to create and maintain retrievable exact copies of electronic protected health information. The data backup plan must define backup frequency, storage locations, and retention periods, and must ensure that backup copies are available to restore ePHI in the event of data loss or system failure.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:22'),
(580,11,NULL,'11.1','Establish and Maintain a Data Recovery Process','Establish and maintain a data recovery process. In the process, address the scope of data recovery activities, recovery prioritization, and the security of backup data. Review and update documentation annually, or when significant enterprise changes occur that could impact this safeguard.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:05'),
(581,10,NULL,'164.316(b)(2)(i)','Time Limit','Retain the documentation required by the Security Rule for six years from the date of its creation or the date when it last was in effect, whichever is later. This retention requirement applies to all policies, procedures, actions, activities, and assessments that the Security Rule requires to be documented.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:23'),
(582,2,NULL,'A.8.7','Protection Against Malware','Protection against malware shall be implemented and supported by appropriate user awareness. Malware detection, prevention, and recovery controls shall be combined with security awareness training to defend against viruses, ransomware, trojans, and other malicious software threats.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:28'),
(583,5,NULL,'DE.CM-01','Network Monitoring for Cybersecurity Events','Networks and network services are monitored to find potentially adverse events. The organization deploys network monitoring tools including intrusion detection systems, flow analysis, and packet capture to detect suspicious activity. Monitoring covers both north-south and east-west traffic patterns to identify lateral movement and data exfiltration attempts.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:12'),
(584,6,NULL,'SI.L1-b.1.xi','Identify, Report, and Correct Flaws','Identify, report, and correct information and information system flaws in a timely manner. Organizations must establish processes for discovering software vulnerabilities, reporting them to appropriate personnel, and applying patches or mitigations within defined remediation timeframes.',NULL,NULL,1,0,'2026-03-09 13:56:29','2026-03-09 14:01:18'),
(585,10,NULL,'164.308(a)(5)(ii)(B)','Protection from Malicious Software','Implement procedures for guarding against, detecting, and reporting malicious software. The organization must deploy and maintain anti-malware solutions, keep them current with the latest definitions, and train workforce members to recognize and report potential malware threats to electronic protected health information.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:22'),
(586,11,NULL,'10.1','Deploy and Maintain Anti-Malware Software','Deploy and maintain anti-malware software on all enterprise assets. Anti-malware software should be capable of detecting, preventing, and remediating known malware threats. Ensure that the anti-malware solution is configured for automatic updates and real-time scanning of files and processes.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:05'),
(587,2,NULL,'A.8.9','Configuration Management','Configurations, including security configurations, of hardware, software, services, and networks shall be established, documented, implemented, monitored, and reviewed. Secure configuration baselines shall be defined and maintained to ensure systems are hardened against known threats and vulnerabilities.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:28'),
(588,5,NULL,'PR.PS-01','Configuration Management Practices','The configuration management practices for organizational systems are established and applied. Secure baseline configurations are defined, implemented, and maintained for all technology assets. Configuration changes are controlled through a formal change management process that includes security impact analysis and approval workflows.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:12'),
(589,6,NULL,'CM.L2-3.4.1','System Baselining','Establish and maintain baseline configurations and inventories of organizational systems (including hardware, software, firmware, and documentation) throughout the respective system development life cycles. Baselines provide a known-good reference for detecting unauthorized changes and maintaining system integrity.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:17'),
(590,11,NULL,'4.1','Establish and Maintain a Secure Configuration Process','Establish and maintain a secure configuration process for enterprise assets (end-user devices, including portable and mobile; non-computing/IoT devices; and servers) and software (operating systems and applications). Review and update documentation annually, or when significant enterprise changes occur that could impact this safeguard.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:05'),
(591,2,NULL,'A.8.8','Management of Technical Vulnerabilities','Information about technical vulnerabilities of information systems in use shall be obtained, the organization\'s exposure to such vulnerabilities shall be evaluated, and appropriate measures shall be taken. A systematic approach to vulnerability management including timely patching shall be maintained.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:28'),
(592,5,NULL,'PR.PS-02','Software Maintenance and Updates','Software is maintained, replaced, and removed commensurate with risk. The organization manages the software lifecycle including installation, patching, updating, and decommissioning. Patch management processes prioritize critical and high-severity vulnerabilities and ensure timely application of security updates across all managed systems.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:12'),
(593,6,NULL,'SI.L2-3.14.1','Flaw Remediation','Identify, report, and correct information and information system flaws in a timely manner. Organizations must implement a vulnerability management program that includes regular scanning, patch management, and remediation tracking to ensure system flaws are addressed before they can be exploited.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:18'),
(594,11,NULL,'7.1','Establish and Maintain a Vulnerability Management Process','Establish and maintain a documented vulnerability management process for enterprise assets. Review and update documentation annually, or when significant enterprise changes occur that could impact this safeguard. The process should include asset discovery, vulnerability scanning, risk assessment, remediation prioritization, and verification of remediation.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:05'),
(595,6,NULL,'AC.L2-3.1.18','Mobile Device Connection','Control connection of mobile devices. Organizations must establish usage restrictions, configuration requirements, connection requirements, and implementation guidance for organization-controlled mobile devices, and must authorize the connection of mobile devices to organizational systems.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:17'),
(596,11,NULL,'4.3','Configure Automatic Session Locking on Enterprise Assets','Configure automatic session locking on enterprise assets after a defined period of inactivity. For general purpose operating systems, the period must not exceed 15 minutes. For mobile end-user devices, the period must not exceed 2 minutes. This safeguard reduces the risk of unauthorized access to unattended workstations and devices.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:05'),
(597,2,NULL,'A.7.10','Storage Media','Storage media shall be managed through their lifecycle of acquisition, use, transportation, and disposal in accordance with the organization\'s classification scheme and handling requirements. Appropriate procedures shall ensure that sensitive data on storage media is protected from unauthorized disclosure or modification.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:27'),
(598,6,NULL,'MP.L2-3.8.7','Removable Media','Control the use of removable media on system components. Organizations must restrict, disable, or otherwise control the use of removable storage devices such as USB drives, external hard drives, and optical media to prevent unauthorized data exfiltration or introduction of malicious code.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:17'),
(599,11,NULL,'10.3','Disable Autorun and Autoplay for Removable Media','Disable autorun and autoplay auto-execute functionality for removable media. These features can be exploited by malware to automatically execute malicious code when removable media such as USB drives are connected to enterprise assets, bypassing user awareness and security controls.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:05'),
(600,2,NULL,'A.5.9','Inventory of Information and Other Associated Assets','An inventory of information and other associated assets, including owners, shall be developed and maintained. The inventory shall be accurate, up to date, consistent, and aligned with other inventories to support effective asset management and protection.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:26'),
(601,5,NULL,'ID.AM-01','Hardware Asset Inventory','Inventories of hardware managed by the organization are maintained. The organization identifies, documents, and tracks all physical devices and systems within its environment, including servers, workstations, mobile devices, and network equipment. Hardware inventories are kept current and reconciled periodically to ensure accuracy and completeness.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:12'),
(602,5,NULL,'ID.AM-02','Software Asset Inventory','Inventories of software, services, and systems managed by the organization are maintained. The organization catalogs all software platforms, applications, firmware, and cloud services used within its environment. Software inventories support vulnerability management, license compliance, and configuration control activities.',NULL,NULL,1,0,'2026-03-09 13:56:30','2026-03-09 14:01:12'),
(603,9,NULL,'2.4','Maintain Inventory of In-Scope System Components','Organizations must maintain an inventory of all system components that are in scope for PCI DSS. This inventory must be kept current and include a description of function and use for each component.',NULL,NULL,1,0,'2026-03-09 13:56:31','2026-03-09 14:01:16'),
(604,10,NULL,'164.310(d)(1)','Device and Media Controls','Implement policies and procedures that govern the receipt and removal of hardware and electronic media that contain electronic protected health information into and out of a facility, and the movement of these items within the facility. These controls must address the proper disposal, reuse, and tracking of devices and media containing ePHI.',NULL,NULL,1,0,'2026-03-09 13:56:31','2026-03-09 14:01:22'),
(605,2,NULL,'A.8.19','Installation of Software on Operational Systems','Procedures and measures shall be implemented to securely manage software installation on operational systems. Only authorized and tested software shall be installed, and controls shall be in place to prevent the installation of unauthorized or potentially harmful software.',NULL,NULL,1,0,'2026-03-09 13:56:31','2026-03-09 14:01:28'),
(606,6,NULL,'CM.L2-3.4.8','Application Execution Policy','Apply deny-by-exception (blacklisting) policy to prevent the use of unauthorized software or deny-all, permit-by-exception (whitelisting) policy to allow the execution of authorized software. Organizations must maintain and enforce application control policies to prevent unauthorized programs from running on organizational systems.',NULL,NULL,1,0,'2026-03-09 13:56:31','2026-03-09 14:01:17'),
(607,11,NULL,'2.5','Allowlist Authorized Software','Use technical controls, such as application allowlisting, to ensure that only authorized software can execute or be accessed. Reassess bi-annually, or more frequently. This safeguard helps prevent unauthorized or malicious software from running on enterprise assets.',NULL,NULL,1,0,'2026-03-09 13:56:31','2026-03-09 14:01:04'),
(608,6,NULL,'CM.L2-3.4.2','Security Configuration Enforcement','Establish and enforce security configuration settings for information technology products employed in organizational systems. Configuration settings must reflect the most restrictive mode consistent with operational requirements and must be documented, approved, and monitored for compliance.',NULL,NULL,1,0,'2026-03-09 13:56:31','2026-03-09 14:01:17'),
(609,2,NULL,'A.8.22','Web Filtering','Access to external websites shall be managed to reduce exposure to malicious content. Web filtering controls shall block access to known malicious websites, restrict access to inappropriate content categories, and help prevent malware infections and data exfiltration through web-based channels.',NULL,NULL,1,0,'2026-03-09 13:56:31','2026-03-09 14:01:28'),
(610,5,NULL,'PR.IR-01','Network Security and Resilience','Networks and environments are protected from unauthorized logical access and usage. The organization implements network segmentation, firewalls, intrusion prevention systems, and other controls to restrict unauthorized network access. Network architecture is designed to limit the blast radius of security incidents and support resilient operations.',NULL,NULL,1,0,'2026-03-09 13:56:31','2026-03-09 14:01:12'),
(611,6,NULL,'SC.L2-3.13.5','Public-Access System Separation','Implement subnetworks for publicly accessible system components that are physically or logically separated from internal networks. DMZ architectures and network segmentation must isolate public-facing services from internal systems that process, store, or transmit CUI.',NULL,NULL,1,0,'2026-03-09 13:56:31','2026-03-09 14:01:18'),
(612,11,NULL,'12.2','Establish and Maintain a Secure Network Architecture','Establish and maintain a secure network architecture. A secure network architecture must address segmentation, least privilege, and availability, at a minimum. Review and update documentation annually, or when significant enterprise changes occur that could impact this safeguard.',NULL,NULL,1,0,'2026-03-09 13:56:31','2026-03-09 14:01:05'),
(613,2,NULL,'A.8.20','Networks Security','Networks and network devices shall be secured, managed, and controlled to protect information in systems and applications. Network security controls shall include segmentation, access controls, encryption of data in transit, and monitoring to defend against network-based attacks and unauthorized access.',NULL,NULL,1,0,'2026-03-09 13:56:32','2026-03-09 14:01:28'),
(614,6,NULL,'SC.L2-3.13.1','Boundary Protection','Monitor, control, and protect organizational communications at the external boundaries and key internal boundaries of the information systems. Organizations must deploy boundary protection mechanisms such as firewalls, intrusion detection systems, and content filtering to manage information flow between security domains.',NULL,NULL,1,0,'2026-03-09 13:56:32','2026-03-09 14:01:18'),
(615,11,NULL,'4.4','Implement and Manage a Firewall on Servers','Implement and manage a firewall on servers, where supported. Example implementations include a virtual firewall, operating system firewall, or a third-party firewall agent. Firewall rules should restrict inbound and outbound traffic to only what is necessary for the server\'s business function.',NULL,NULL,1,0,'2026-03-09 13:56:32','2026-03-09 14:01:05'),
(616,2,NULL,'A.8.16','Monitoring Activities','Networks, systems, and applications shall be monitored for anomalous behavior and appropriate actions taken to evaluate potential information security incidents. Continuous monitoring shall enable early detection of security events, unauthorized access attempts, and deviations from expected operational baselines.',NULL,NULL,1,0,'2026-03-09 13:56:32','2026-03-09 14:01:28'),
(617,6,NULL,'SI.L2-3.14.6','Monitor Communications','Monitor organizational systems, including inbound and outbound communications traffic, to detect attacks and indicators of potential attacks. Organizations must deploy network monitoring tools such as intrusion detection and prevention systems to identify malicious activity and anomalous traffic patterns in real time.',NULL,NULL,1,0,'2026-03-09 13:56:32','2026-03-09 14:01:18'),
(618,11,NULL,'13.3','Deploy a Network Intrusion Detection Solution','Deploy a network intrusion detection solution on enterprise assets, where appropriate. Network intrusion detection systems (NIDS) monitor network traffic for suspicious patterns, known attack signatures, and anomalous behavior that may indicate an active intrusion or attempted compromise of enterprise systems.',NULL,NULL,1,0,'2026-03-09 13:56:32','2026-03-09 14:01:05'),
(619,6,NULL,'AC.L2-3.1.16','Wireless Access Authorization','Authorize wireless access prior to allowing such connections. Organizations must establish usage restrictions, configuration requirements, and implementation guidance for wireless access, and must authorize wireless access before permitting connections to the network.',NULL,NULL,1,0,'2026-03-09 13:56:32','2026-03-09 14:01:17'),
(620,11,NULL,'12.6','Use of Secure Network Management and Communication Protocols','Use secure network management and communication protocols such as 802.1X, Wi-Fi Protected Access 2 (WPA2) Enterprise or greater, and Transport Layer Security (TLS). Insecure protocols such as Telnet, HTTP, and unencrypted SNMP should be replaced with their secure counterparts to protect data in transit.',NULL,NULL,1,0,'2026-03-09 13:56:33','2026-03-09 14:01:05'),
(621,11,NULL,'9.2','Ensure Use of Only Fully Supported Browsers and Email Clients','Ensure only fully supported browsers and email clients are allowed to execute in the enterprise, only using the latest version of browsers and email clients provided through the vendor. Unsupported browsers and email clients may contain unpatched vulnerabilities that can be exploited by attackers to compromise enterprise systems.',NULL,NULL,1,0,'2026-03-09 13:56:33','2026-03-09 14:01:05'),
(622,11,NULL,'13.1','Centralize Security Event Alerting','Centralize security event alerting across enterprise assets for log correlation and analysis. Best practice implementation requires the use of a SIEM, which includes vendor-defined event correlation alerts. A log analytics platform configured with security-relevant correlation alerts also satisfies this safeguard.',NULL,NULL,1,0,'2026-03-09 13:56:33','2026-03-09 14:01:05'),
(623,11,NULL,'13.10','Perform Application Layer Filtering','Perform application layer filtering. Example implementations include a filtering proxy, application layer firewall, or gateway. Application layer filtering inspects traffic content at the application level to detect and block threats that network-level controls may miss, such as malicious payloads within allowed protocols.',NULL,NULL,1,0,'2026-03-09 13:56:33','2026-03-09 14:01:05'),
(624,11,NULL,'13.7','Deploy a Host-Based Intrusion Prevention Solution','Deploy a host-based intrusion prevention solution on enterprise assets, where appropriate and supported. Host-based intrusion prevention systems (HIPS) actively block or contain detected threats on individual hosts, providing real-time protection against malware, exploits, and unauthorized system changes.',NULL,NULL,1,0,'2026-03-09 13:56:33','2026-03-09 14:01:05'),
(625,2,NULL,'A.5.37','Documented Operating Procedures','Operating procedures for information processing facilities shall be documented and made available to personnel who need them. These procedures shall be maintained as controlled documents and updated whenever operational changes occur that affect information security.',NULL,NULL,1,0,'2026-03-09 13:56:33','2026-03-09 14:01:27'),
(626,5,NULL,'ID.AM-04','External Service Provider Inventories','Inventories of services provided by suppliers are maintained. The organization tracks external service providers, cloud services, managed services, and other third-party technology dependencies. Service inventories include information about the criticality of each service and the data it processes or accesses.',NULL,NULL,1,0,'2026-03-09 13:56:33','2026-03-09 14:01:12'),
(627,11,NULL,'12.4','Establish and Maintain Architecture Diagram(s)','Establish and maintain architecture diagram(s) and/or other network system documentation. Review and update documentation annually, or when significant enterprise changes occur that could impact this safeguard. Architecture diagrams should include network boundaries, device locations, data flows, and security controls.',NULL,NULL,1,0,'2026-03-09 13:56:33','2026-03-09 14:01:05'),
(628,2,NULL,'A.8.25','Secure Development Life Cycle','Rules for the secure development of software and systems shall be established and applied. Security requirements shall be integrated into all phases of the development lifecycle, including design, coding, testing, and deployment, to produce software that is resilient to known threats and vulnerabilities.',NULL,NULL,1,0,'2026-03-09 13:56:33','2026-03-09 14:01:28'),
(629,5,NULL,'PR.PS-06','Secure Software Development Practices','Secure software development practices are integrated, and their performance is monitored throughout the software development lifecycle. The organization implements security requirements, secure coding standards, code reviews, and security testing as part of the development process. Security is embedded into DevOps and CI/CD pipelines to identify and remediate vulnerabilities early.',NULL,NULL,1,0,'2026-03-09 13:56:33','2026-03-09 14:01:12'),
(630,6,NULL,'SA.L2-3.13.2','Security Engineering','Employ architectural designs, software development techniques, and systems engineering principles that promote effective information security within organizational systems. Security must be integrated into the system development lifecycle from initial design through deployment and maintenance.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:18'),
(631,11,NULL,'16.1','Establish and Maintain a Secure Application Development Process','Establish and maintain a secure application development process. In the process, address such items as secure application design standards, secure coding practices, developer training, vulnerability management, security of third-party code, and application security testing procedures. Review and update documentation annually.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:06'),
(632,2,NULL,'A.8.28','Secure Coding','Secure coding principles shall be applied to software development. Developers shall follow established secure coding standards to prevent common vulnerabilities such as injection flaws, buffer overflows, cross-site scripting, and other weaknesses identified in industry vulnerability taxonomies.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:28'),
(633,11,NULL,'16.4','Establish and Manage an Inventory of Third-Party Software Components','Establish and manage an updated inventory of third-party components used in development, often referred to as a Software Bill of Materials (SBOM). The inventory should include all third-party libraries, frameworks, and dependencies, along with their versions, licenses, and known vulnerabilities.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:06'),
(634,2,NULL,'A.8.29','Security Testing in Development and Acceptance','Security testing processes shall be defined and implemented in the development lifecycle. Testing shall include static and dynamic analysis, vulnerability scanning, penetration testing, and code review to validate that security requirements are met before systems are deployed to production.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:28'),
(635,11,NULL,'16.5','Use Up-to-Date and Trusted Third-Party Software Components','Use up-to-date and trusted third-party software components. When possible, choose established and proven frameworks and libraries that provide adequate security. Use the components from trusted sources and check for known vulnerabilities before integrating them into enterprise applications.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:06'),
(636,5,NULL,'DE.CM-06','External Service Provider Activity Monitoring','External service provider activities and services are monitored to find potentially adverse events. The organization monitors third-party connections, service performance, and security-relevant events from managed service providers and cloud platforms. Monitoring ensures that externally delivered services maintain agreed-upon security levels and detect anomalous behavior.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:12'),
(637,2,NULL,'A.8.26','Application Security Requirements','Information security requirements shall be identified, specified, and approved when developing or acquiring applications. Security requirements shall address authentication, authorization, input validation, encryption, error handling, and logging to ensure that applications are designed with security built in from inception.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:28'),
(638,11,NULL,'16.8','Separate Production and Non-Production Systems','Maintain separate environments for production and non-production systems. Developers should not have unmonitored access to production environments. Separating environments prevents untested code changes from impacting production systems and reduces the risk of sensitive production data being exposed in development or testing environments.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:06'),
(639,5,NULL,'GV.SC-09','Supply Chain Security Practices Oversight','Supply chain security practices are integrated into cybersecurity and enterprise risk management programs, and their performance is monitored throughout the technology product and service life cycle. The organization tracks supplier security posture changes, contract compliance, and emerging risks. Continuous improvement of supply chain security is driven by metrics and lessons learned.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:11'),
(640,11,NULL,'16.2','Establish and Maintain a Process to Accept and Address Software Vulnerabilities','Establish and maintain a process to accept and address reports of software vulnerabilities, including providing a means for external entities to report. The process is to include such items as a vulnerability handling policy that identifies reporting process, responsible party for handling vulnerability reports, and a process for intake, assignment, remediation, and remediation testing.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:06'),
(641,2,NULL,'A.8.32','Change Management','Changes to information processing facilities and information systems shall be subject to change management procedures. A formal change management process shall ensure that changes are assessed for security impact, authorized, tested, documented, and implemented in a controlled manner to prevent disruptions and security weaknesses.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:28'),
(642,5,NULL,'PR.PS-05','Installation and Execution of Authorized Software','Installation and execution of unauthorized software is prevented. The organization implements application whitelisting, software restriction policies, and other controls to ensure only approved software operates in its environment. Unauthorized software is detected, blocked, and reported through automated monitoring and enforcement mechanisms.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:12'),
(643,6,NULL,'CM.L2-3.4.3','System Change Management','Track, review, approve or disapprove, and log changes to organizational systems. Organizations must implement a formal change management process that documents all modifications, requires appropriate authorization, and maintains an auditable record of system changes.',NULL,NULL,1,0,'2026-03-09 13:56:34','2026-03-09 14:01:17'),
(645,11,NULL,'2.4','Establish and Maintain a Software Inventory','Establish and maintain a detailed inventory of all licensed software installed on enterprise assets. The software inventory must document the title, publisher, initial install/use date, and business purpose for each entry. Where appropriate, include the Uniform Resource Locator (URL), app store(s), version(s), deployment mechanism, and decommission date. Review and update the software inventory bi-annually, or more frequently.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:04'),
(646,2,NULL,'A.8.31','Separation of Development, Test and Production Environments','Development, testing, and production environments shall be separated and secured to reduce the risks of unauthorized access or changes to the production environment. Separation controls shall prevent development and test activities from adversely affecting production operations and data integrity.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:28'),
(647,6,NULL,'CM.L2-3.4.4','Security Impact Analysis','Analyze the security impact of changes prior to implementation. Organizations must evaluate proposed modifications to determine their potential effects on existing security controls and the overall security posture before authorizing changes to production systems.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:17'),
(649,11,NULL,'16.9','Train Developers in Application Security Concepts and Secure Coding','Ensure all software development personnel receive training in writing secure code for their specific development environment and responsibilities. Training can include general security principles and application security standard practices. Conduct training at least annually and track completion to ensure developer competency in security practices.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:06'),
(650,5,NULL,'ID.RA-01','Vulnerability Identification and Documentation','Vulnerabilities in assets are identified, validated, and recorded. The organization uses vulnerability scanning, penetration testing, code reviews, and threat intelligence to discover weaknesses in its systems and applications. Identified vulnerabilities are documented with severity ratings and tracked through remediation or risk acceptance.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:12'),
(651,6,NULL,'RA.L2-3.11.2','Vulnerability Scan','Scan for vulnerabilities in organizational systems and applications periodically and when new vulnerabilities affecting those systems and applications are identified. Vulnerability scanning must cover all system components, apply updated vulnerability signatures, and generate actionable remediation reports.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:18'),
(652,2,NULL,'A.8.15','Logging','Logs that record activities, exceptions, faults, and other relevant events shall be produced, stored, protected, and analyzed. Logging shall capture sufficient detail to support security monitoring, incident investigation, and audit activities while protecting log integrity from unauthorized modification.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:28'),
(653,5,NULL,'DE.AE-02','Anomalous Activity Detection and Analysis','Estimated impact and scope of adverse events are understood. Anomalous activities are detected, analyzed, and correlated to determine whether they constitute cybersecurity events. Analysis includes determining the affected assets, the nature of the anomaly, and the potential business impact to support prioritized response actions.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:12'),
(654,6,NULL,'AU.L2-3.3.1','System Auditing','Create and retain system audit logs and records to the extent needed to enable the monitoring, analysis, investigation, and reporting of unlawful or unauthorized system activity. Audit records must capture sufficient detail to establish what events occurred, when they occurred, where they occurred, the source, and the outcome.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:17'),
(655,10,NULL,'164.312(b)','Audit Controls','Implement hardware, software, and/or procedural mechanisms that record and examine activity in information systems that contain or use electronic protected health information. Audit controls must capture sufficient detail to support after-the-fact investigation of security incidents and to verify compliance with access policies.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:23'),
(656,11,NULL,'8.2','Collect Audit Logs','Collect audit logs. Ensure that logging, per the enterprise\'s audit log management process, has been enabled across enterprise assets. Audit logs should capture sufficient detail including timestamps, event types, user identities, source and destination addresses, and success or failure indicators for security-relevant events.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:05'),
(657,11,NULL,'8.11','Conduct Audit Log Reviews','Conduct reviews of audit logs to detect anomalies or abnormal events that could indicate a potential threat. Conduct reviews on a weekly, or more frequent, basis. Regular audit log reviews help identify security incidents, policy violations, and operational issues before they escalate into significant problems.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:05'),
(658,5,NULL,'DE.AE-05','Cybersecurity Event Correlation and Enrichment','Information from multiple sources is correlated and enriched to create actionable cyber threat intelligence. The organization aggregates and analyzes security events from diverse sources including logs, network traffic, endpoint telemetry, and threat intelligence feeds. Correlation enables detection of sophisticated attacks that may not be apparent from individual data sources.',NULL,NULL,1,0,'2026-03-09 13:56:35','2026-03-09 14:01:12'),
(659,6,NULL,'AU.L2-3.3.8','Audit Protection','Protect audit information and audit logging tools from unauthorized access, modification, and deletion. Organizations must implement controls to ensure the integrity and availability of audit records, preventing tampering that could conceal evidence of unauthorized or malicious activity.',NULL,NULL,1,0,'2026-03-09 13:56:36','2026-03-09 14:01:17'),
(660,11,NULL,'8.1','Establish and Maintain an Audit Log Management Process','Establish and maintain an audit log management process that defines the enterprise\'s logging requirements. At a minimum, address the collection, review, and retention of audit logs for enterprise assets. Review and update documentation annually, or when significant enterprise changes occur that could impact this safeguard.',NULL,NULL,1,0,'2026-03-09 13:56:36','2026-03-09 14:01:05'),
(661,11,NULL,'7.4','Perform Automated Application Patch Management','Perform application updates on enterprise assets through automated patch management on a monthly, or more frequent, basis. Automated patch management ensures timely deployment of security patches, reducing the window of exposure to known vulnerabilities across the enterprise application landscape.',NULL,NULL,1,0,'2026-03-09 13:56:36','2026-03-09 14:01:05'),
(662,5,NULL,'ID.RA-02','Threat Intelligence Reception and Analysis','Cyber threat intelligence is received from information sharing forums and sources. The organization consumes, analyzes, and correlates threat intelligence from ISACs, government sources, commercial feeds, and industry peers. Threat intelligence is used to inform risk assessments, detection capabilities, and proactive defense measures.',NULL,NULL,1,0,'2026-03-09 13:56:36','2026-03-09 14:01:12'),
(663,11,NULL,'13.2','Deploy a Host-Based Intrusion Detection Solution','Deploy a host-based intrusion detection solution on enterprise assets, where appropriate and supported. Host-based intrusion detection systems (HIDS) monitor system activities, file integrity, and log events on individual hosts to detect suspicious behavior, policy violations, and potential security incidents.',NULL,NULL,1,0,'2026-03-09 13:56:36','2026-03-09 14:01:05'),
(664,2,NULL,'A.8.6','Capacity Management','The use of resources shall be monitored and adjusted in line with current and expected capacity requirements. Capacity planning and monitoring shall ensure that adequate processing power, storage, and network bandwidth are available to maintain system performance and availability.',NULL,NULL,1,0,'2026-03-09 13:56:36','2026-03-09 14:01:28'),
(665,5,NULL,'ID.AM-03','Network Communication and Data Flow Mapping','Representations of the organization\'s authorized network communication and internal and external network data flows are maintained. Network architecture diagrams and data flow maps document how information moves within and outside the organization. These representations support security analysis, incident response, and change management processes.',NULL,NULL,1,0,'2026-03-09 13:56:36','2026-03-09 14:01:12'),
(666,2,NULL,'A.8.17','Clock Synchronization','The clocks of information processing systems used by the organization shall be synchronized to approved time sources. Accurate and consistent timestamps across all systems are essential for event correlation, forensic analysis, and compliance with logging requirements.',NULL,NULL,1,0,'2026-03-09 13:56:36','2026-03-09 14:01:28'),
(667,6,NULL,'AU.L2-3.3.7','Authoritative Time Source','Provide a system capability that compares and synchronizes internal system clocks with an authoritative source to generate time stamps for audit records. Accurate time synchronization is essential for correlating audit events across multiple systems and supporting forensic investigations.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:17'),
(668,11,NULL,'8.4','Standardize Time Synchronization','Standardize time synchronization. Configure at least two synchronized time sources across enterprise assets, where supported. Time synchronization is critical for accurate audit log correlation, incident investigation, and ensuring the integrity of timestamps across all enterprise systems and applications.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:05'),
(669,2,NULL,'A.8.34','Test Information','Test information shall be appropriately selected, protected, and managed. When production data is used for testing, sensitive information shall be anonymized or pseudonymized, and appropriate controls shall be applied to prevent unauthorized access to and disclosure of test data.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:28'),
(670,5,NULL,'ID.RA-06','Risk Response Prioritization and Execution','Risk responses are chosen, prioritized, planned, tracked, and communicated. The organization selects appropriate risk treatment options including mitigation, transfer, acceptance, or avoidance based on risk analysis results. Risk response plans are documented with clear ownership, timelines, and success criteria, and progress is reported to stakeholders.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:12'),
(671,11,NULL,'18.1','Establish and Maintain a Penetration Testing Program','Establish and maintain a penetration testing program appropriate to the size, complexity, and maturity of the enterprise. Penetration testing program characteristics include scope, such as network, web application, API, hosted services, and physical premise controls; frequency; limitations, such as acceptable hours and excluded attack types; and remediation requirements.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:06'),
(672,11,NULL,'18.3','Remediate Penetration Test Findings','Remediate penetration test findings based on the enterprise\'s policy for remediation scope and prioritization. Findings should be categorized by severity and remediated within defined timeframes. Verify remediations through re-testing to confirm that identified vulnerabilities have been effectively addressed and no new issues have been introduced.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:06'),
(673,2,NULL,'A.5.24','Information Security Incident Management Planning and Preparation','The organization shall plan and prepare for managing information security incidents by defining, establishing, and communicating information security incident management processes, roles, and responsibilities. This ensures a rapid, effective, and orderly response to incidents.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:27'),
(674,5,NULL,'RS.MA-01','Incident Response Plan Execution','The incident response plan is executed in coordination with relevant third parties once an incident is declared. The organization activates its response procedures, assembles the incident response team, and coordinates with external parties including law enforcement, regulators, and service providers as needed. Response actions are documented and tracked throughout the incident lifecycle.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:12'),
(675,6,NULL,'IR.L2-3.6.1','Incident Handling','Establish an operational incident-handling capability for organizational systems that includes preparation, detection, analysis, containment, recovery, and user response activities. The incident response capability must address the full lifecycle from preparation through lessons learned.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:17'),
(676,10,NULL,'164.308(a)(6)(i)','Security Incident Procedures','Implement policies and procedures to address security incidents. The organization must establish formal incident response capabilities that define what constitutes a security incident, outline response and reporting procedures, and ensure that incidents affecting the confidentiality, integrity, or availability of ePHI are properly managed.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:22'),
(677,11,NULL,'17.1','Designate Personnel to Manage Incident Handling','Designate one key person, and at least one backup, who will manage the enterprise\'s incident handling process. Management personnel are responsible for the coordination and documentation of incident response and recovery efforts. They serve as the primary points of contact during security incidents and ensure proper escalation procedures are followed.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:06'),
(678,2,NULL,'A.5.25','Assessment and Decision on Information Security Events','The organization shall assess information security events and decide if they are to be categorized as information security incidents. Each event shall be evaluated against agreed criteria to determine its severity, impact, and the appropriate response actions required.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:27'),
(679,5,NULL,'RS.MA-02','Incident Triage and Prioritization','Incidents are triaged and validated to determine the appropriate level of response. The organization categorizes and prioritizes incidents based on severity, scope, and potential impact to ensure efficient allocation of response resources. Triage processes help distinguish between true incidents and false positives and determine escalation requirements.',NULL,NULL,1,0,'2026-03-09 13:56:37','2026-03-09 14:01:12'),
(680,11,NULL,'17.2','Establish and Maintain Contact Information for Reporting Security Incidents','Establish and maintain contact information for parties that need to be informed of security incidents. Contacts may include internal staff, third-party vendors, law enforcement, cyber insurance providers, relevant government agencies, Information Sharing and Analysis Center (ISAC) partners, or other stakeholders. Review contacts annually.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:06'),
(681,6,NULL,'IR.L2-3.6.2','Incident Reporting','Track, document, and report incidents to designated officials and/or authorities both internal and external to the organization. Organizations must maintain incident records that capture sufficient detail for analysis and must report incidents to appropriate stakeholders within defined timeframes.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:17'),
(682,11,NULL,'17.3','Establish and Maintain an Enterprise Process for Reporting Incidents','Establish and maintain an enterprise process for the workforce to report security incidents. The process includes reporting timeframe, personnel to report to, mechanism for reporting, and the minimum information to be reported. Ensure the process is publicly available to all of the workforce and review annually.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:06'),
(683,2,NULL,'A.5.26','Response to Information Security Incidents','Information security incidents shall be responded to in accordance with the documented procedures. The response shall include containment, eradication, and recovery activities, with clear escalation paths and communication to relevant stakeholders as defined in the incident management plan.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:27'),
(684,5,NULL,'RS.MI-01','Incident Containment','Incidents are contained to prevent further damage. The organization implements containment strategies to limit the spread and impact of cybersecurity incidents, including network isolation, account disabling, and blocking malicious communications. Containment actions are selected based on the nature and scope of the incident while minimizing disruption to business operations.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:13'),
(685,11,NULL,'17.4','Establish and Maintain an Incident Response Process','Establish and maintain an incident response process that addresses roles and responsibilities, compliance requirements, and a communication plan. Review annually, or when significant enterprise changes occur that could impact this safeguard. The process should cover the full incident lifecycle: preparation, detection, analysis, containment, eradication, and recovery.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:06'),
(686,2,NULL,'A.5.28','Collection of Evidence','The organization shall establish and implement procedures for the identification, collection, acquisition, and preservation of evidence related to information security events. Evidence handling procedures shall ensure admissibility, integrity, and reliability for potential legal or disciplinary proceedings.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:27'),
(687,5,NULL,'RS.AN-03','Incident Root Cause Analysis','The cause of an incident is investigated by analyzing the available evidence. Root cause analysis examines technical indicators, logs, forensic artifacts, and contextual information to determine how the incident occurred. Understanding the root cause enables the organization to implement effective corrective actions and prevent recurrence.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:12'),
(688,11,NULL,'17.6','Define Mechanisms for Communicating During Incident Response','Determine which primary and secondary mechanisms will be used to communicate and report during a security incident. Mechanisms can include phone calls, email, or letters. Keep in mind that certain mechanisms, such as email, can be affected by a security incident. Review annually, or when significant enterprise changes occur.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:06'),
(689,5,NULL,'RS.CO-02','Incident Reporting and Stakeholder Communication','Incident reports are provided to designated stakeholders in accordance with established reporting procedures. Internal and external notification requirements are followed, including regulatory reporting obligations and contractual notification commitments. Communication is timely, accurate, and appropriately scoped for each stakeholder audience.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:12'),
(690,10,NULL,'164.308(a)(6)(ii)','Response and Reporting','Identify and respond to suspected or known security incidents, mitigate to the extent practicable harmful effects of security incidents that are known to the covered entity or business associate, and document security incidents and their outcomes. Incident response activities must include containment, eradication, and recovery procedures.',NULL,NULL,1,0,'2026-03-09 13:56:38','2026-03-09 14:01:22'),
(691,2,NULL,'A.5.27','Learning from Information Security Incidents','Knowledge gained from information security incidents shall be used to strengthen and improve the information security controls. Post-incident reviews shall be conducted to identify root causes, lessons learned, and necessary improvements to prevent recurrence of similar incidents.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:27'),
(692,5,NULL,'RS.AN-08','Incident Severity and Scope Estimation','The magnitude of an incident is estimated and validated. The organization assesses the scope, severity, and potential business impact of cybersecurity incidents to guide response prioritization and resource allocation. Severity classifications are aligned with organizational impact criteria and communicated to stakeholders to ensure appropriate response escalation.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:12'),
(693,6,NULL,'IR.L2-3.6.3','Incident Response Testing','Test the organizational incident response capability. Organizations must periodically test their incident response plans and procedures using tabletop exercises, simulations, or operational exercises to validate effectiveness and identify areas for improvement.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:17'),
(694,9,NULL,'12.10.7','Incident Response Procedures for Unauthorized Wireless Access Points','Incident response procedures must be in place for the detection of and response to unauthorized wireless access points. Organizations must define processes to identify rogue wireless devices and respond to confirmed unauthorized access points in a timely manner.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:16'),
(695,11,NULL,'17.8','Conduct Post-Incident Reviews','Conduct post-incident reviews. Post-incident reviews help the organization understand root causes, identify gaps in the incident response process, and drive continuous improvement. Reviews should document findings, corrective actions, and lessons learned, and be shared with relevant stakeholders to prevent recurrence.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:06'),
(696,5,NULL,'RS.MA-03','Incident Eradication and Recovery','Incidents are eradicated by eliminating the root cause and recovering affected systems and services. The organization removes malicious artifacts, patches vulnerabilities, restores systems from clean backups, and validates the integrity of recovered assets. Eradication and recovery activities are documented and verified before returning systems to normal operations.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:12'),
(697,5,NULL,'RS.MA-04','Incident Escalation and Forensic Preservation','Incidents are escalated or elevated as needed, and forensic evidence is preserved. The organization follows defined escalation criteria and procedures to engage senior leadership, specialized resources, or external partners when incident severity warrants. Digital forensic evidence is collected, preserved, and documented following chain-of-custody procedures to support investigation and potential legal proceedings.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:12'),
(698,11,NULL,'17.7','Conduct Routine Incident Response Exercises','Plan and conduct routine incident response exercises and scenarios for key personnel involved in the incident response process to prepare for handling real-world incidents. Exercises are to test communication channels, decision making, and workflows. Conduct testing on an annual basis, at a minimum, and incorporate lessons learned.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:06'),
(699,2,NULL,'A.5.19','Information Security in Supplier Relationships','Processes and procedures shall be defined and implemented to manage the information security risks associated with the use of supplier\'s products or services. This includes establishing requirements for protecting the organization\'s information that is accessible by suppliers.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:26'),
(700,5,NULL,'GV.SC-03','Supply Chain Risk Management Integration','Cybersecurity supply chain risk management is integrated into cybersecurity and enterprise risk management, risk assessment, and improvement processes. Supply chain risk considerations are woven into existing governance structures rather than treated as a separate activity. This integration ensures comprehensive risk visibility across the organization and its suppliers.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:11'),
(701,10,NULL,'164.308(b)(1)','Business Associate Contracts and Other Arrangements','A covered entity may permit a business associate to create, receive, maintain, or transmit electronic protected health information on the covered entity\'s behalf only if the covered entity obtains satisfactory assurances that the business associate will appropriately safeguard the information. These assurances must be documented through a written contract or other arrangement.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:22'),
(702,11,NULL,'15.1','Establish and Maintain an Inventory of Service Providers','Establish and maintain an inventory of service providers. The inventory is to list all known service providers, include classification(s), and designate an enterprise contact for each service provider. Review and update the inventory annually, or when significant enterprise changes occur that could impact this safeguard.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:05'),
(703,2,NULL,'A.5.20','Addressing Information Security Within Supplier Agreements','Relevant information security requirements shall be established and agreed with each supplier based on the type of supplier relationship. Agreements shall clearly define both parties\' obligations regarding information security to mitigate risks associated with third-party access and services.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:26'),
(704,5,NULL,'GV.SC-06','Supply Chain Due Diligence','Planning and due diligence are conducted to reduce risks before entering into formal supplier or other third-party relationships. The organization evaluates potential suppliers\' cybersecurity posture, track record, and capability to meet security requirements before engagement. Due diligence findings inform contract negotiations and risk acceptance decisions.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:11'),
(705,10,NULL,'164.308(b)(4)','Written Contract or Other Arrangement','Document the satisfactory assurances required by the business associate contract or other arrangement through a written contract or other arrangement with the business associate that meets the applicable requirements. The contract must specify the business associate\'s obligations to implement appropriate safeguards for ePHI and report security incidents.',NULL,NULL,1,0,'2026-03-09 13:56:39','2026-03-09 14:01:22'),
(706,11,NULL,'15.2','Establish and Maintain a Service Provider Management Policy','Establish and maintain a service provider management policy. Ensure the policy addresses the classification, inventory, assessment, monitoring, and decommissioning of service providers. Review and update the policy annually, or when significant enterprise changes occur that could impact this safeguard.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:06'),
(707,2,NULL,'A.5.22','Monitoring, Review and Change Management of Supplier Services','The organization shall regularly monitor, review, evaluate, and manage change in supplier information security practices and service delivery. This ensures that the agreed level of information security and service delivery is maintained and that changes do not adversely impact security.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:26'),
(708,5,NULL,'GV.SC-07','Supply Chain Risk Management Activities','The risks posed by a supplier, their products and services, and other third parties are identified, recorded, prioritized, assessed, responded to, and monitored over the course of the relationship. Ongoing monitoring includes periodic reassessment, performance reviews, and tracking of supply chain threat intelligence. Risk treatment actions are documented and tracked to completion.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:11'),
(709,11,NULL,'15.4','Ensure Service Provider Contracts Include Security Requirements','Ensure service provider contracts include security requirements. Example requirements may include minimum security program requirements, security incident and/or data breach notification and response, data encryption requirements, and data disposal commitments. These requirements help establish accountability and minimum security standards for third parties.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:06'),
(710,2,NULL,'A.5.21','Managing Information Security in the ICT Supply Chain','Processes and procedures shall be defined and implemented to manage the information security risks associated with the ICT products and services supply chain. This includes requirements for addressing security throughout the supply chain for technology components and services.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:26'),
(711,5,NULL,'GV.SC-04','Supplier Assessment and Prioritization','Suppliers are known and prioritized by criticality. The organization identifies, documents, and categorizes its suppliers and third-party service providers based on the criticality of the products and services they provide. Prioritization informs the rigor of due diligence, monitoring, and risk management applied to each supplier.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:11'),
(712,5,NULL,'GV.SC-05','Supply Chain Security Requirements','Requirements to address cybersecurity risks in supply chains are established, prioritized, and integrated into contracts and other agreements with suppliers and other relevant third parties. Contractual provisions include security controls, incident notification obligations, audit rights, and compliance verification mechanisms. These requirements are periodically reviewed and updated.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:11'),
(713,11,NULL,'15.3','Classify Service Providers','Classify service providers. Classification consideration may include one or more characteristics, such as data sensitivity, data volume, availability requirements, applicable regulations, inherent risk, and mitigated risk. Update and review classifications annually, or when significant enterprise changes occur that could impact this safeguard.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:06'),
(714,5,NULL,'GV.SC-08','Supply Chain Incident Management','Relevant suppliers and other third parties are included in incident planning, response, and recovery activities. The organization establishes communication channels, escalation procedures, and coordination mechanisms with critical suppliers for cybersecurity incidents. Joint exercises and tabletop scenarios are conducted to validate supply chain incident response readiness.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:11'),
(715,11,NULL,'15.5','Assess Service Providers','Assess service providers consistent with the enterprise\'s service provider management policy. Assessment scope may vary based on classification and may include review of standardized assessment reports, such as SOC 2 and ISO 27001, or custom questionnaires. Reassess service providers annually, or when significant changes to the service provider occur.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:06'),
(716,2,NULL,'A.5.23','Information Security for Use of Cloud Services','Processes for acquisition, use, management, and exit from cloud services shall be established in accordance with the organization\'s information security requirements. This includes defining responsibilities, security controls, and procedures specific to cloud computing environments.',NULL,NULL,1,0,'2026-03-09 13:56:40','2026-03-09 14:01:26'),
(717,2,NULL,'A.7.1','Physical Security Perimeters','Security perimeters shall be defined and used to protect areas that contain information and other associated assets. Physical barriers and entry controls shall be implemented to prevent unauthorized physical access, damage, and interference to the organization\'s information processing facilities.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:27'),
(718,6,NULL,'PE.L1-b.1.ix','Limit Physical Access','Limit physical access to organizational information systems, equipment, and the respective operating environments to authorized individuals. Organizations must implement physical access controls such as guards, locks, badges, and monitoring systems to prevent unauthorized physical access to facilities containing CUI.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:17'),
(719,10,NULL,'164.310(a)(1)','Facility Access Controls','Implement policies and procedures to limit physical access to electronic information systems and the facility or facilities in which they are housed, while ensuring that properly authorized access is allowed. Physical access controls must address facility security plans, access control procedures, and visitor management.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:22'),
(720,2,NULL,'A.7.5','Protecting Against Physical and Environmental Threats','Protection against physical and environmental threats such as natural disasters, deliberate attacks, or accidents shall be designed and implemented. Appropriate safeguards shall be applied to reduce the risk of disruption from environmental events including fire, flood, earthquake, and power failure.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:27'),
(721,5,NULL,'PR.IR-02','Technology Assets Resilience','The organization\'s technology assets are protected from environmental threats and are designed to be resilient. Infrastructure components are configured for high availability, redundancy, and failover to ensure continuity of operations. Environmental controls including power, cooling, and physical protections are implemented to safeguard technology assets.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:12'),
(722,6,NULL,'PE.L2-3.10.2','Monitor Physical Facility','Protect and monitor the physical facility and support infrastructure for organizational systems. Organizations must employ physical security measures such as surveillance cameras, intrusion detection systems, and environmental controls to safeguard facilities and supporting utilities against unauthorized access and environmental threats.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:17'),
(723,10,NULL,'164.310(a)(2)(ii)','Facility Security Plan','Implement policies and procedures to safeguard the facility and the equipment therein from unauthorized physical access, tampering, and theft. The facility security plan must document the physical security measures in place, including locks, surveillance systems, access badges, and environmental controls to protect systems that store or process ePHI.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:22'),
(724,6,NULL,'PE.L2-3.10.3','Escort Visitors','Escort visitors and monitor visitor activity. Organizations must maintain control over visitors in areas where CUI is processed, stored, or transmitted by requiring escorts, monitoring visitor movements, and maintaining visitor access logs.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:17'),
(725,2,NULL,'A.7.2','Physical Entry','Secure areas shall be protected by appropriate entry controls and access points to ensure that only authorized personnel are allowed access. Entry controls shall include authentication mechanisms and visitor management procedures to maintain the security of physical facilities.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:27'),
(726,10,NULL,'164.310(a)(2)(iii)','Access Control and Validation Procedures','Implement procedures to control and validate a person\'s access to facilities based on their role or function, including visitor control, and control of access to software programs for testing and revision. These procedures must ensure that only authorized individuals have physical access to areas where ePHI is accessible.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:22'),
(727,2,NULL,'A.7.4','Physical Security Monitoring','Premises shall be continuously monitored for unauthorized physical access. Monitoring methods such as surveillance cameras, intrusion detection systems, and security guards shall be implemented to detect and deter unauthorized access to sensitive areas.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:27'),
(728,5,NULL,'DE.CM-02','Physical Environment Monitoring','The physical environment is monitored to find potentially adverse events. Surveillance systems, environmental sensors, and physical intrusion detection mechanisms are deployed to protect facilities. Monitoring covers unauthorized physical access, environmental conditions such as temperature and humidity, and other threats to physical infrastructure.',NULL,NULL,1,0,'2026-03-09 13:56:41','2026-03-09 14:01:12'),
(729,2,NULL,'A.7.14','Secure Disposal or Re-Use of Equipment','Items of equipment containing storage media shall be verified to ensure that any sensitive data and licensed software has been removed or securely overwritten prior to disposal or re-use. This prevents unauthorized disclosure of information when equipment leaves organizational control.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:27'),
(730,11,NULL,'3.5','Securely Dispose of Data','Securely dispose of data as outlined in the enterprise\'s data management process. Ensure the disposal process and method are commensurate with the data sensitivity. This includes ensuring that data on enterprise assets is securely erased when the asset is decommissioned or repurposed.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:05'),
(731,2,NULL,'A.7.7','Clear Desk and Clear Screen','Clear desk rules for papers and removable storage media and clear screen rules for information processing facilities shall be defined and appropriately enforced. These rules reduce the risk of unauthorized access to, loss of, and damage to information during and outside normal working hours.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:27'),
(732,2,NULL,'A.7.12','Cabling Security','Cables carrying power, data, or supporting information services shall be protected from interception, interference, or damage. Cabling security measures shall include physical protection of cable routes and separation of power and network cabling to prevent unauthorized access or signal interference.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:27'),
(733,2,NULL,'A.6.1','Screening','Background verification checks on all candidates to become personnel shall be carried out prior to joining the organization and on an ongoing basis taking into consideration applicable laws, regulations, and ethics. These checks shall be proportional to the business requirements, classification of information to be accessed, and the perceived risks.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:27'),
(734,6,NULL,'PS.L2-3.9.1','Screen Individuals','Screen individuals prior to authorizing access to organizational systems containing CUI. Personnel screening must include background investigations and vetting procedures appropriate to the sensitivity of the information and the role, conducted before granting access to systems processing CUI.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:17'),
(735,10,NULL,'164.308(a)(3)(ii)(B)','Termination Procedures','Implement procedures for terminating access to electronic protected health information when the employment of, or other arrangement with, a workforce member ends. These procedures must also address the return of all property, including keys, tokens, and access cards, and the deactivation of accounts and access rights.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:22'),
(736,11,NULL,'14.1','Establish and Maintain a Security Awareness Program','Establish and maintain a security awareness program. The purpose of a security awareness program is to educate the enterprise\'s workforce on how to interact with enterprise assets and data in a secure manner. Conduct security awareness training at hire and at least annually thereafter. Review and update content annually.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:05'),
(737,2,NULL,'A.6.3','Information Security Awareness, Education and Training','Personnel of the organization and relevant interested parties shall receive appropriate information security awareness, education, and training and regular updates of the organization\'s information security policy, topic-specific policies, and procedures as relevant for their job function. This builds a security-aware culture and reduces human-factor risks.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:27'),
(738,5,NULL,'PR.AT-01','Cybersecurity Awareness and Training','Personnel are provided with awareness and training so that they possess the knowledge and skills to perform general tasks with cybersecurity risks in mind. Training covers organizational policies, common threats, social engineering, phishing recognition, and reporting procedures. Awareness programs are updated regularly to reflect current threat trends and organizational changes.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:12'),
(739,6,NULL,'AT.L2-3.2.1','Role-Based Risk Awareness','Ensure that managers, systems administrators, and users of organizational systems are made aware of the security risks associated with their activities and of the applicable policies, standards, and procedures related to the security of those systems. Security awareness content must be updated to address current threats and vulnerabilities.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:17'),
(740,10,NULL,'164.308(a)(5)(i)','Security Awareness and Training','Implement a security awareness and training program for all members of the covered entity\'s or business associate\'s workforce, including management. The program must address security awareness on an ongoing basis and provide periodic training updates to address new threats and reinforce organizational security policies.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:22'),
(741,5,NULL,'PR.AT-02','Privileged User Training','Individuals in specialized roles are provided with awareness and training so that they possess the knowledge and skills to perform relevant tasks with cybersecurity risks in mind. Role-based training is provided to administrators, developers, incident responders, and other specialized personnel. Training content addresses role-specific threats, tools, and responsibilities.',NULL,NULL,1,0,'2026-03-09 13:56:42','2026-03-09 14:01:12'),
(742,6,NULL,'AT.L2-3.2.2','Role-Based Training','Ensure that personnel are trained to carry out their assigned information security-related duties and responsibilities. Training must be provided before authorizing access to the system or performing assigned duties, and must be updated periodically to address evolving threats and system changes.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:17'),
(743,11,NULL,'14.9','Conduct Role-Specific Security Awareness and Skills Training','Conduct role-specific security awareness and skills training. Example implementations include secure system administration courses for IT professionals, OWASP Top 10 vulnerability awareness and prevention training for application developers, and advanced social engineering awareness training for high-profile roles.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:05'),
(744,10,NULL,'164.308(a)(3)(ii)(A)','Workforce Clearance Procedure','Implement procedures to determine that the access of a workforce member to electronic protected health information is appropriate. The clearance procedure should verify that a workforce member\'s access to ePHI is authorized and commensurate with their role and responsibilities within the organization.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:22'),
(745,2,NULL,'A.6.5','Responsibilities After Termination or Change of Employment','Information security responsibilities and duties that remain valid after termination or change of employment shall be defined, enforced, and communicated to relevant personnel and other interested parties. This ensures that ongoing confidentiality obligations and return of assets are properly managed.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:27'),
(746,6,NULL,'PS.L2-3.9.2','Personnel Actions','Ensure that organizational systems containing CUI are protected during and after personnel actions such as terminations and transfers. Organizations must promptly revoke access, retrieve credentials and equipment, and conduct exit procedures when personnel depart or change roles.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:17'),
(747,10,NULL,'164.308(a)(3)(ii)(C)','Access Authorization','Implement procedures for granting access to electronic protected health information based on the access authorization policies established by the covered entity. Access must be authorized on a need-to-know basis consistent with the workforce member\'s job responsibilities and the organization\'s access control policies.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:22'),
(748,2,NULL,'A.5.10','Acceptable Use of Information and Other Associated Assets','Rules for the acceptable use and procedures for handling information and other associated assets shall be identified, documented, and implemented. Personnel and external users shall be made aware of and comply with these requirements for the proper use of organizational assets.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:26'),
(749,10,NULL,'164.310(b)','Workstation Use','Implement policies and procedures that specify the proper functions to be performed, the manner in which those functions are to be performed, and the physical attributes of the surroundings of a specific workstation or class of workstation that can access electronic protected health information. Workstation use policies must address the physical environment, automatic logoff, and acceptable use requirements.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:22'),
(750,2,NULL,'A.6.6','Confidentiality or Non-Disclosure Agreements','Confidentiality or non-disclosure agreements reflecting the organization\'s needs for the protection of information shall be identified, documented, regularly reviewed, and signed by personnel and other relevant interested parties. These agreements establish legal obligations to protect sensitive organizational information.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:27'),
(751,10,NULL,'164.308(a)(4)(ii)(B)','Access Establishment and Modification','Implement policies and procedures that, based upon the covered entity\'s or business associate\'s access authorization policies, establish, document, review, and modify a user\'s right of access to a workstation, transaction, program, or process. Access rights must be reviewed and updated in response to environmental or operational changes.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:22'),
(752,2,NULL,'A.6.4','Disciplinary Process','A disciplinary process shall be formalized and communicated to take actions against personnel and other relevant interested parties who have committed an information security policy violation. The process shall be proportional and provide a formal mechanism for addressing security breaches by employees.',NULL,NULL,1,0,'2026-03-09 13:56:43','2026-03-09 14:01:27'),
(753,2,NULL,'A.5.29','Information Security During Disruption','The organization shall plan how to maintain information security at an appropriate level during disruption. Information security requirements shall be integrated into business continuity management to ensure that security controls remain effective during adverse conditions and recovery scenarios.',NULL,NULL,1,0,'2026-03-09 13:56:44','2026-03-09 14:01:27'),
(754,5,NULL,'RC.RP-01','Recovery Plan Execution','The recovery portion of the incident response plan is executed once initiated from the incident response process. Recovery activities follow predefined procedures to restore affected systems, services, and data to normal operations. The recovery plan is activated in coordination with incident response and business continuity teams to ensure a structured and efficient return to operations.',NULL,NULL,1,0,'2026-03-09 13:56:44','2026-03-09 14:01:13'),
(755,10,NULL,'164.308(a)(7)(i)','Contingency Plan','Establish and implement as needed policies and procedures for responding to an emergency or other occurrence that damages systems that contain electronic protected health information. The contingency plan must ensure the continuation of critical business processes necessary to protect the security of ePHI during and after a crisis.',NULL,NULL,1,0,'2026-03-09 13:56:44','2026-03-09 14:01:22'),
(756,2,NULL,'A.5.30','ICT Readiness for Business Continuity','ICT readiness shall be planned, implemented, maintained, and tested based on business continuity objectives and ICT continuity requirements. This ensures that the organization\'s information and communication technology infrastructure can be restored to support critical business functions within required timeframes.',NULL,NULL,1,0,'2026-03-09 13:56:44','2026-03-09 14:01:27'),
(757,5,NULL,'RC.RP-02','Recovery Action Selection and Execution','Recovery actions are selected, scoped, and performed to restore the affected systems and services. The organization prioritizes restoration based on business criticality, dependencies, and available resources. Recovery procedures include system rebuilding, data restoration, configuration validation, and security hardening before returning assets to production.',NULL,NULL,1,0,'2026-03-09 13:56:44','2026-03-09 14:01:13'),
(758,10,NULL,'164.308(a)(7)(ii)(B)','Disaster Recovery Plan','Establish and implement as needed procedures to restore any loss of data. The disaster recovery plan must document the processes and procedures for recovering electronic protected health information and restoring systems to normal operations following a disaster, including prioritization of recovery activities and assignment of responsibilities.',NULL,NULL,1,0,'2026-03-09 13:56:44','2026-03-09 14:01:22'),
(759,5,NULL,'ID.RA-09','Risk Record Integrity and Maintenance','The integrity and currency of risk records are maintained. Risk registers, risk assessments, and related documentation are kept up to date with accurate information reflecting the current risk landscape. Changes in risk status, new risk identifications, and risk treatment progress are recorded in a timely manner.',NULL,NULL,1,0,'2026-03-09 13:56:44','2026-03-09 14:01:12'),
(760,10,NULL,'164.308(a)(7)(ii)(E)','Applications and Data Criticality Analysis','Assess the relative criticality of specific applications and data in support of other contingency plan components. This analysis identifies the most critical systems and data that process or store ePHI, enabling the organization to prioritize resources for backup, disaster recovery, and emergency mode operations.',NULL,NULL,1,0,'2026-03-09 13:56:44','2026-03-09 14:01:22'),
(761,5,NULL,'RC.RP-04','Critical Function Recovery Validation','Critical mission functions and cybersecurity risk management are considered to establish post-incident operational norms. The organization verifies that recovered systems meet security and operational requirements before returning them to full production status. Post-recovery validation ensures that security controls are reinstated and that the organization\'s risk posture is restored to acceptable levels.',NULL,NULL,1,0,'2026-03-09 13:56:44','2026-03-09 14:01:13'),
(762,5,NULL,'RC.CO-01','Recovery Public Communication','Restoration activities and progress are communicated to designated internal and external stakeholders. The organization provides timely updates on recovery status, expected timelines, and any ongoing risks to customers, partners, regulators, and the public as appropriate. Communication is coordinated with legal, public relations, and executive leadership to ensure consistent messaging.',NULL,NULL,1,0,'2026-03-09 13:56:44','2026-03-09 14:01:13'),
(763,5,NULL,'RC.CO-02','Recovery Reputation Management','Public communications regarding incident recovery are managed to protect organizational reputation. The organization coordinates messaging through designated spokespersons, manages media relations, and ensures that public disclosures are accurate, timely, and legally reviewed. Reputation management activities aim to maintain stakeholder confidence during and after recovery efforts.',NULL,NULL,1,0,'2026-03-09 13:56:45','2026-03-09 14:01:13'),
(764,5,NULL,'RC.RP-03','Recovery Verification and Validation','The integrity of backups and other restoration assets is verified before using them for restoration. The organization validates that backup data is complete, uncorrupted, and free from malicious content before initiating recovery operations. Verification processes include integrity checks, malware scans, and test restorations to confirm backup viability.',NULL,NULL,1,0,'2026-03-09 13:56:45','2026-03-09 14:01:13'),
(765,10,NULL,'164.308(a)(7)(ii)(C)','Emergency Mode Operation Plan','Establish and implement as needed procedures to enable continuation of critical business processes for protection of the security of electronic protected health information while operating in emergency mode. The plan must identify essential functions and define how ePHI will be protected when normal operating procedures are unavailable.',NULL,NULL,1,0,'2026-03-09 13:56:45','2026-03-09 14:01:22'),
(766,5,NULL,'RC.RP-05','Post-Incident Lessons Learned','The integrity of restored assets is verified, systems and services are restored, and normal operating status is confirmed. The organization conducts post-incident reviews to capture lessons learned and identify improvements to recovery plans and procedures. Findings are documented and incorporated into updated recovery plans, training materials, and cybersecurity program enhancements.',NULL,NULL,1,0,'2026-03-09 13:56:45','2026-03-09 14:01:13'),
(767,10,NULL,'164.308(a)(7)(ii)(D)','Testing and Revision Procedures','Implement procedures for periodic testing and revision of contingency plans. The organization must regularly test its contingency plans to ensure their effectiveness, identify gaps or weaknesses, and update the plans to reflect changes in the organization\'s environment, technology, or operational requirements.',NULL,NULL,1,0,'2026-03-09 13:56:45','2026-03-09 14:01:22'),
(768,6,NULL,'SC.L2-3.13.11','CUI Encryption','Employ FIPS-validated cryptography when used to protect the confidentiality of CUI. All cryptographic modules used to protect CUI must be validated under the Cryptographic Module Validation Program (CMVP) and must use approved algorithms and key lengths.',NULL,NULL,1,0,'2026-03-09 13:56:45','2026-03-09 14:01:18'),
(769,6,NULL,'SC.L2-3.13.10','Key Management','Establish and manage cryptographic keys for cryptography employed in organizational systems. Organizations must implement key management procedures that address key generation, distribution, storage, access, rotation, revocation, and destruction in accordance with applicable standards.',NULL,NULL,1,0,'2026-03-09 13:56:45','2026-03-09 14:01:18'),
(770,9,NULL,'3.6.3','Documented Cryptographic Key Distribution Procedures','Cryptographic key management procedures must include documented processes for the secure distribution of cryptographic keys. Keys must only be distributed using secure methods that prevent unauthorized disclosure or substitution.',NULL,NULL,1,0,'2026-03-09 13:56:45','2026-03-09 14:01:16'),
(771,5,NULL,'ID.IM-02','Improvements from Security Tests and Exercises','Improvements are identified from security tests and exercises, including those done in coordination with suppliers and relevant third parties. Penetration tests, red team exercises, tabletop simulations, and other assessments reveal areas for improvement. Findings are prioritized, assigned to responsible parties, and tracked through resolution.',NULL,NULL,1,0,'2026-03-09 13:56:46','2026-03-09 14:01:12'),
(772,10,NULL,'164.308(a)(8)','Evaluation','Perform a periodic technical and nontechnical evaluation, based initially upon the standards implemented under the Security Rule and subsequently in response to environmental or operational changes affecting the security of electronic protected health information. The evaluation must assess the extent to which security policies and procedures meet the requirements of the Security Rule.',NULL,NULL,1,0,'2026-03-09 13:56:46','2026-03-09 14:01:22'),
(773,6,NULL,'CA.L2-3.12.3','Security Control Monitoring','Monitor security controls on an ongoing basis to ensure the continued effectiveness of the controls. Continuous monitoring programs must maintain awareness of the security posture, detect changes that may impact system security, and verify that controls remain properly implemented over time.',NULL,NULL,1,0,'2026-03-09 13:56:46','2026-03-09 14:01:17'),
(774,2,NULL,'A.5.36','Compliance with Policies, Rules and Standards for Information Security','Compliance with the organization\'s information security policy, topic-specific policies, rules, and standards shall be regularly reviewed. Managers shall verify that information processing and procedures within their area of responsibility are performed correctly to achieve compliance with security policies and standards.',NULL,NULL,1,0,'2026-03-09 13:56:47','2026-03-09 14:01:27'),
(775,5,NULL,'ID.IM-04','Incident Response Plan Testing','Incident response plans and other cybersecurity plans that affect operations are established, communicated, maintained, and improved. Plans are tested through exercises and updated based on lessons learned, organizational changes, and evolving threats. Stakeholders are informed of their roles and responsibilities within these plans.',NULL,NULL,1,0,'2026-03-09 13:56:47','2026-03-09 14:01:12'),
(776,5,NULL,'ID.IM-03','Improvements from Incident Response Execution','Improvements are identified from execution of operational processes, procedures, and activities. Lessons learned from incident response, change management, and day-to-day operations inform process refinements. Post-incident reviews and operational metrics are used to drive measurable improvements in cybersecurity practices.',NULL,NULL,1,0,'2026-03-09 13:56:47','2026-03-09 14:01:12'),
(777,6,NULL,'CA.L2-3.12.2','Plan of Action','Develop and implement plans of action designed to correct deficiencies and reduce or eliminate vulnerabilities in organizational systems. Plans of action must document the planned remedial actions to mitigate identified weaknesses, including timelines and responsible parties for each corrective measure.',NULL,NULL,1,0,'2026-03-09 13:56:47','2026-03-09 14:01:17'),
(931,3,NULL,'COSO-1','Demonstrates commitment to integrity and ethical values','The organization demonstrates a commitment to integrity and ethical values. The board of directors and management set the tone at the top by establishing standards of conduct, evaluating adherence to those standards, and addressing deviations in a timely manner. Ethical values are embedded into policies, job descriptions, and performance evaluations to reinforce expected behaviors throughout the entity.',NULL,NULL,1,1,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(932,3,NULL,'COSO-2','Exercises oversight responsibility','The board of directors demonstrates independence from management and exercises oversight of the development and performance of internal control. The board retains oversight responsibility for management\'s design, implementation, and conduct of internal control across all five components. This includes establishing expectations for competence, evaluating performance, and holding individuals accountable for their internal control responsibilities.',NULL,NULL,1,2,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(933,3,NULL,'COSO-3','Establishes structure, authority, and responsibility','Management establishes, with board oversight, structures, reporting lines, and appropriate authorities and responsibilities in the pursuit of objectives. The organizational structure supports effective internal control by defining roles, establishing reporting relationships, and delegating authority at appropriate levels. Management considers the entity\'s legal structure, business units, geographic locations, and outsourced service providers when designing internal control structures.',NULL,NULL,1,3,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(934,3,NULL,'COSO-4','Demonstrates commitment to competence','The organization demonstrates a commitment to attract, develop, and retain competent individuals in alignment with objectives. Policies and practices reflect expectations of competence needed to support internal control. The organization evaluates competence across the entity and outsourced service providers, identifies gaps, and provides training, mentoring, and other development activities to address shortfalls in knowledge and skills.',NULL,NULL,1,4,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(935,3,NULL,'COSO-5','Enforces accountability','The organization holds individuals accountable for their internal control responsibilities in the pursuit of objectives. Management and the board establish mechanisms to communicate and hold individuals accountable for the performance of internal control responsibilities. Accountability is reinforced through performance measures, incentives, rewards, and disciplinary actions applied consistently and fairly across the organization.',NULL,NULL,1,5,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(936,3,NULL,'COSO-6','Specifies suitable objectives','The organization specifies objectives with sufficient clarity to enable the identification and assessment of risks relating to objectives. Management defines operational, reporting, and compliance objectives clearly enough to identify risks. For financial reporting, objectives reflect applicable accounting standards, materiality considerations, and the activities of the entity. Objectives are set at entity and transaction levels.',NULL,NULL,1,6,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(937,3,NULL,'COSO-7','Identifies and analyzes risk','The organization identifies risks to the achievement of its objectives across the entity and analyzes risks as a basis for determining how the risks should be managed. Risk identification considers internal and external factors, including changes in the regulatory environment, economic conditions, business model, and IT infrastructure. Risk analysis involves evaluating the significance of identified risks, including assessing the likelihood and potential impact of each risk.',NULL,NULL,1,7,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(938,3,NULL,'COSO-8','Assesses fraud risk','The organization considers the potential for fraud in assessing risks to the achievement of objectives. Management considers the various types of fraud that can occur, including fraudulent financial reporting, misappropriation of assets, and corrupt activities. The assessment considers incentives and pressures, opportunities, and attitudes and rationalizations. Management evaluates fraud risk related to management override of controls.',NULL,NULL,1,8,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(939,3,NULL,'COSO-9','Identifies and analyzes significant change','The organization identifies and assesses changes that could significantly impact the system of internal control. The entity has processes to identify changes in the external environment (regulatory, economic, physical) and within the business (new technology, rapid growth, new business models, acquisitions, restructurings, and changes in key personnel) that may necessitate changes to the internal control system.',NULL,NULL,1,9,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(940,3,NULL,'COSO-10','Selects and develops control activities','The organization selects and develops control activities that contribute to the mitigation of risks to the achievement of objectives to acceptable levels. Control activities are actions established through policies and procedures that help ensure management\'s directives to mitigate risks are carried out. Control activities include approvals, authorizations, verifications, reconciliations, reviews of performance, security of assets, and segregation of duties. Activities are performed at all levels and at various stages within business processes.',NULL,NULL,1,10,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(941,3,NULL,'COSO-11','Selects and develops general controls over technology','The organization selects and develops general control activities over technology to support the achievement of objectives. Technology general controls include controls over the technology infrastructure, security management, and technology acquisition, development, and maintenance. These controls support the continued proper operation of technology and the functioning of automated controls such as system-enforced segregation of duties, automated transaction processing, and programmed edit checks.',NULL,NULL,1,11,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(942,3,NULL,'COSO-12','Deploys through policies and procedures','The organization deploys control activities through policies that establish what is expected and procedures that put policies into action. Policies reflect management or board statements about what should be done to effect control. Procedures are the specific actions that personnel perform to implement policies. Policies and procedures are established at the relevant level of the entity, across business processes, and over technology. They are implemented thoughtfully, conscientiously, and consistently.',NULL,NULL,1,12,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(943,3,NULL,'COSO-13','Uses relevant information','The organization obtains or generates and uses relevant, quality information to support the functioning of internal control. Management identifies information requirements to support the functioning of the other components of internal control. Information systems capture and process both internal and external data from multiple sources and convert it into actionable information. The quality of information — its timeliness, accuracy, completeness, accessibility, and protection — is assessed and maintained.',NULL,NULL,1,13,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(944,3,NULL,'COSO-14','Communicates internally','The organization internally communicates information, including objectives and responsibilities for internal control, necessary to support the functioning of internal control. Communication occurs in all directions: down, across, and up the organization. Management provides specific and directed communication that addresses expectations for behavior, control responsibilities, and any changes to the internal control system. Communication channels enable personnel to report suspected problems and provide input to management.',NULL,NULL,1,14,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(945,3,NULL,'COSO-15','Communicates externally','The organization communicates with external parties regarding matters affecting the functioning of internal control. Communication with external stakeholders — regulators, financial analysts, external auditors, suppliers, customers, and business partners — provides information necessary for them to understand events and conditions that may affect their interaction with the entity. External communication channels allow inbound communication including whistleblower hotlines and regulatory inquiries.',NULL,NULL,1,15,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(946,3,NULL,'COSO-16','Conducts ongoing and/or separate evaluations','The organization selects, develops, and performs ongoing and/or separate evaluations to ascertain whether the components of internal control are present and functioning. Ongoing evaluations are built into business processes at different levels of the entity and provide timely information. Separate evaluations — including internal audits, self-assessments, and peer reviews — are conducted periodically, with scope and frequency varying based on risk assessment, effectiveness of ongoing evaluations, and management judgment.',NULL,NULL,1,16,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(947,3,NULL,'COSO-17','Evaluates and communicates deficiencies','The organization evaluates and communicates internal control deficiencies in a timely manner to those parties responsible for taking corrective action, including senior management and the board of directors, as appropriate. Deficiencies identified through monitoring activities or other sources are assessed for severity and reported to appropriate levels. Management tracks whether deficiencies are remediated on a timely basis and escalates significant deficiencies and material weaknesses to senior management and the audit committee.',NULL,NULL,1,17,'2026-03-09 16:02:06','2026-03-09 16:02:06'),
(999,3,NULL,'ITGC-SM-01','ITGC-SM-01',NULL,NULL,NULL,1,0,'2026-03-09 20:00:15','2026-03-09 20:00:15'),
(1000,3,NULL,'ITGC-SM-02','ITGC-SM-02',NULL,NULL,NULL,1,0,'2026-03-09 20:00:15','2026-03-09 20:00:15'),
(1001,3,NULL,'ITGC-SM-03','ITGC-SM-03',NULL,NULL,NULL,1,0,'2026-03-09 20:00:15','2026-03-09 20:00:15'),
(1002,3,NULL,'ITGC-AC-01','ITGC-AC-01',NULL,NULL,NULL,1,0,'2026-03-09 20:00:15','2026-03-09 20:00:15'),
(1003,3,NULL,'ITGC-AC-02','ITGC-AC-02',NULL,NULL,NULL,1,0,'2026-03-09 20:00:15','2026-03-09 20:00:15'),
(1004,3,NULL,'ITGC-AC-03','ITGC-AC-03',NULL,NULL,NULL,1,0,'2026-03-09 20:00:15','2026-03-09 20:00:15'),
(1005,3,NULL,'ITGC-CM-01','ITGC-CM-01',NULL,NULL,NULL,1,0,'2026-03-09 20:00:15','2026-03-09 20:00:15'),
(1006,3,NULL,'ITGC-CM-02','ITGC-CM-02',NULL,NULL,NULL,1,0,'2026-03-09 20:00:15','2026-03-09 20:00:15'),
(1007,12,NULL,'GOVERN 1','AI Risk Management Policies and Processes','Policies, processes, procedures, and practices across the organization related to the mapping, measuring, and managing of AI risks are in place, transparent, and implemented effectively.',NULL,NULL,1,1,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1008,12,1007,'GOVERN 1.1','Legal and Regulatory Requirements','Legal and regulatory requirements involving AI are understood, managed, and documented.','Review applicable AI-specific laws, regulations, and standards. Document how they apply to each AI system.',NULL,1,2,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1009,12,1007,'GOVERN 1.2','Trustworthy AI Characteristics in Policies','The characteristics of trustworthy AI are integrated into organizational policies, processes, procedures, and practices.','Verify that policies address validity, reliability, safety, security, accountability, transparency, explainability, privacy, and fairness.',NULL,1,3,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1010,12,1007,'GOVERN 1.3','Risk Tolerance and Activity Levels','Processes, procedures, and practices are in place to determine the needed level of risk management activities based on the organization\'s risk tolerance.','Verify risk tolerance levels are defined for AI systems and used to scale risk management activities.',NULL,1,4,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1011,12,1007,'GOVERN 1.4','Transparent Risk Management Processes','The risk management process and its outcomes are established through transparent policies, procedures, and other controls.','Check that AI risk management processes are documented, transparent, and accessible to stakeholders.',NULL,1,5,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1012,12,1007,'GOVERN 1.5','Ongoing Monitoring and Review','Ongoing monitoring and periodic review of the risk management process and its outcomes are planned, and organizational roles and responsibilities clearly defined, including determining the frequency of periodic review.','Verify monitoring schedules exist and responsibilities are assigned for reviewing AI risk outcomes.',NULL,1,6,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1013,12,1007,'GOVERN 1.6','AI System Inventory','Mechanisms are in place to inventory AI systems and are resourced according to organizational risk priorities.','Check for a comprehensive inventory of AI systems with risk classifications and resource allocations.',NULL,1,7,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1014,12,1007,'GOVERN 1.7','Safe Decommissioning','Processes and procedures are in place for decommissioning and phasing out AI systems safely and in a manner that does not increase risks or harms.','Review decommissioning procedures for AI systems including data disposal and transition planning.',NULL,1,8,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1015,12,NULL,'GOVERN 2','Accountability Structures','Accountability structures are in place so that the appropriate teams and individuals are empowered, responsible, and trained for mapping, measuring, and managing AI risks.',NULL,NULL,1,9,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1016,12,1015,'GOVERN 2.1','Roles and Responsibilities','Roles and responsibilities and lines of communication related to mapping, measuring, and managing AI risks are documented and are clear to individuals and teams throughout the organization.','Review organizational charts and RACI matrices for AI risk management roles.',NULL,1,10,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1017,12,1015,'GOVERN 2.2','AI Risk Management Training','The organization\'s personnel and partners receive AI risk management training to enable them to perform their duties and responsibilities consistent with related policies, procedures, and agreements.','Verify training curricula, completion rates, and competency assessments for AI risk management.',NULL,1,11,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1018,12,1015,'GOVERN 2.3','Executive Accountability','Executive leadership of the organization takes responsibility for decisions about risks associated with AI system development and deployment.','Check for executive sign-off on AI risk decisions, board-level reporting, and escalation processes.',NULL,1,12,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1019,12,NULL,'GOVERN 3','Workforce Diversity and Accessibility','Workforce diversity, equity, inclusion, and accessibility processes are prioritized in the mapping, measuring, and managing of AI risks throughout the lifecycle.',NULL,NULL,1,13,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1020,12,1019,'GOVERN 3.1','Diverse Decision-Making Teams','Decision-making related to mapping, measuring, and managing AI risks throughout the lifecycle is informed by a diverse team (e.g., demographics, disciplines, experience, expertise, and backgrounds).','Review team composition records for AI development and risk management teams.',NULL,1,14,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1021,12,1019,'GOVERN 3.2','Human-AI Oversight Roles','Policies and procedures are in place to define and differentiate roles and responsibilities for human-AI configurations and oversight of AI systems.','Verify documented roles for human oversight including when human intervention is required.',NULL,1,15,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1022,12,NULL,'GOVERN 4','AI Risk Culture','Organizational teams are committed to a culture that considers and communicates AI risk.',NULL,NULL,1,16,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1023,12,1022,'GOVERN 4.1','Safety-First Mindset','Organizational policies and practices are in place to foster a critical thinking and safety-first mindset in the design, development, deployment, and uses of AI systems to minimize potential negative impacts.','Check for safety-by-design principles and critical review processes in AI development.',NULL,1,17,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1024,12,1022,'GOVERN 4.2','Risk Communication','Organizational teams document risks and potential impacts of the AI technology they design, develop, deploy, or use, and communicate about the impacts more broadly.','Review risk documentation and communication channels for AI system impacts.',NULL,1,18,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1025,12,1022,'GOVERN 4.3','Testing and Incident Sharing','Organizational practices are in place to enable AI testing, identification of incidents, and information sharing.','Verify AI testing processes, incident tracking, and information sharing protocols.',NULL,1,19,'2026-03-13 03:52:55','2026-03-13 03:52:55'),
(1026,12,NULL,'GOVERN 5','Stakeholder Engagement','Processes are in place for robust engagement with relevant AI actors.',NULL,NULL,1,20,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1027,12,1026,'GOVERN 5.1','External Feedback Collection','Organizational policies and practices are in place to collect, consider, prioritize, and integrate feedback from those external to the team that developed or deployed the AI system regarding the potential individual and societal impacts related to AI risks.','Review stakeholder engagement processes and feedback integration mechanisms.',NULL,1,21,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1028,12,1026,'GOVERN 5.2','Feedback Integration Mechanisms','Mechanisms are established to enable AI actors who did not serve as combatants in the development or deployment of the AI system to regularly incorporate adjudicated feedback from relevant AI actors into future system updates.','Verify feedback loops and processes for incorporating external input into AI systems.',NULL,1,22,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1029,12,NULL,'GOVERN 6','Third-Party AI Risk','Policies and procedures are in place that address AI risks and benefits arising from third-party software and data and other supply chain issues.',NULL,NULL,1,23,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1030,12,1029,'GOVERN 6.1','Third-Party AI Policies','Policies and procedures are in place that address AI risks associated with third-party entities, including risks of infringement of a third party\'s intellectual property or other rights.','Review vendor AI risk policies including IP rights, licensing, and data usage agreements.',NULL,1,24,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1031,12,1029,'GOVERN 6.2','Third-Party AI Contingency','Contingency processes are in place to handle failures or incidents in third-party data or AI systems deemed to be high-risk.','Verify contingency plans for third-party AI system failures and data quality issues.',NULL,1,25,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1032,12,NULL,'MAP 1','Context Establishment','Context is established and understood.',NULL,NULL,1,26,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1033,12,1032,'MAP 1.1','Intended Purpose and Context','Intended purposes, potentially beneficial uses, context of deployment, context-specific laws, norms and expectations, and prospective settings in which the AI system will be deployed are understood and documented. Assumptions and related limitations about these have been identified.','Review documentation of AI system purposes, deployment contexts, and applicable regulations.',NULL,1,27,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1034,12,1032,'MAP 1.2','Interdisciplinary Context Setting','Interdisciplinary AI actors, competencies, skills, and capacities for establishing context reflect demographic diversity and broad domain and user experience expertise, and their participation is documented. Opportunities for interdisciplinary collaboration are prioritized.','Check team composition and participation records for AI context-setting activities.',NULL,1,28,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1035,12,1032,'MAP 1.3','Mission Alignment','The organization\'s mission and relevant goals for the AI technology are understood and documented.','Verify alignment documentation between AI systems and organizational mission and goals.',NULL,1,29,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1036,12,1032,'MAP 1.4','Business Value Definition','The business value or context of business use has been clearly defined or â€“ in the case of assessing existing AI systems â€“ re-evaluated.','Review business case documentation and value assessments for AI systems.',NULL,1,30,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1037,12,1032,'MAP 1.5','Organizational Risk Tolerances','Organizational risk tolerances are determined and documented.','Verify that AI-specific risk tolerance levels are documented and approved by leadership.',NULL,1,31,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1038,12,1032,'MAP 1.6','System Requirements and Socio-Technical Design','System requirements (e.g., human oversight specifications) are elicited from and understood by relevant AI actors. Design decisions take socio-technical implications into account to address AI risks.','Review requirements documentation for human oversight and socio-technical considerations.',NULL,1,32,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1039,12,NULL,'MAP 2','AI System Categorization','Categorization of the AI system is performed.',NULL,NULL,1,33,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1040,12,1039,'MAP 2.1','Tasks and Methods Definition','The specific tasks and methods used to implement the tasks that the AI system will support are defined (e.g., classifiers, generative models, recommenders).','Review AI system technical documentation for task definitions and method specifications.',NULL,1,34,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1041,12,1039,'MAP 2.2','Knowledge Limits and Oversight','Information about the AI system\'s knowledge limits and how system output may be utilized and overseen by humans is documented. Documentation provides sufficient detail to assist relevant AI actors when making informed decisions and taking subsequent actions.','Verify documentation of AI system limitations and human oversight procedures.',NULL,1,35,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1042,12,1039,'MAP 2.3','Scientific Integrity and TEVV','Scientific integrity and TEVV (Test, Evaluation, Verification, and Validation) considerations are identified and documented, including those related to experimental design, data collection and selection (e.g., availability, representativeness, suitability), system trustworthiness, and construct validation.','Review TEVV documentation and scientific integrity processes for AI systems.',NULL,1,36,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1043,12,NULL,'MAP 3','AI Capabilities and Benefits','AI capabilities, targeted usage, goals, and expected benefits and costs compared with appropriate benchmarks are understood.',NULL,NULL,1,37,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1044,12,1043,'MAP 3.1','Benefits Documentation','Potential benefits of intended AI system functionality and target performance are examined and documented.','Review benefits analysis and expected performance documentation for AI systems.',NULL,1,38,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1045,12,1043,'MAP 3.2','Costs and Error Impact','Potential costs, including non-monetary costs, which result from expected or realized AI errors or system functionality and trustworthiness â€“ as connected to organizational risk tolerance â€“ are examined and documented.','Verify cost analysis including impact assessments for AI errors and trust failures.',NULL,1,39,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1046,12,1043,'MAP 3.3','Application Scope','Targeted application scope is narrowed, and the scope of technology is specified and documented based on the system\'s capability, established context, and AI system categorization.','Check that AI system scope is clearly bounded and documented.',NULL,1,40,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1047,12,1043,'MAP 3.4','Operator Proficiency','Processes for operator and practitioner proficiency with AI system performance and trustworthiness â€“ and relevant technical standards and certifications â€“ are defined, assessed, and documented.','Review operator training requirements and proficiency assessment processes.',NULL,1,41,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1048,12,1043,'MAP 3.5','Human Oversight Processes','Processes for human oversight are defined, assessed, and documented in accordance with organizational policies from the GOVERN function.','Verify human oversight procedures and escalation processes for AI systems.',NULL,1,42,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1049,12,NULL,'MAP 4','Component Risk Mapping','Risks and benefits are mapped for all components of the AI system including third-party software and data.',NULL,NULL,1,43,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1050,12,1049,'MAP 4.1','Third-Party Component Risk','Approaches for mapping AI technology and legal risks of its components â€“ including the use of third-party data or software â€“ are in place, followed, and documented, as are risks of infringement of a third-party\'s intellectual property or other rights.','Review component risk assessments including third-party data and software risks.',NULL,1,44,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1051,12,1049,'MAP 4.2','Internal Risk Controls','Internal risk controls for components of the AI system, including third-party AI technologies, are identified and documented.','Verify internal risk control documentation for all AI system components.',NULL,1,45,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1052,12,NULL,'MAP 5','Impact Characterization','Impacts to individuals, groups, communities, organizations, and society are characterized.',NULL,NULL,1,46,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1053,12,1052,'MAP 5.1','Impact Documentation','Likelihood and magnitude of each identified impact (both beneficial and harmful) based on expected use, past uses of similar systems, public incident reports, feedback from those external to the team that developed or deployed the AI system, or other data are identified, including those documented by independent combatants.','Review impact assessments documenting likelihood and magnitude of AI system impacts.',NULL,1,47,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1054,12,1052,'MAP 5.2','Impact Engagement Practices','Practices and personnel for supporting regular engagement with relevant AI actors and integrating feedback about positive, negative, and unanticipated impacts are in place and documented.','Verify stakeholder engagement processes for impact assessment and feedback integration.',NULL,1,48,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1055,12,NULL,'MEASURE 1','Measurement Methods and Metrics','Appropriate methods and metrics are identified and applied.',NULL,NULL,1,49,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1056,12,1055,'MEASURE 1.1','Risk Measurement Selection','Approaches and metrics for measurement of AI risks enumerated during the MAP function are selected for implementation starting with the most significant AI risks. The risks or trustworthiness characteristics that will not â€“ or cannot â€“ be measured are properly documented.','Review risk measurement methodology selection and documentation of unmeasurable risks.',NULL,1,50,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1057,12,1055,'MEASURE 1.2','Metric Effectiveness Assessment','Appropriateness of AI metrics and effectiveness of existing measures are regularly assessed and updated, including reports of errors and impacts on affected communities.','Verify regular review cycles for AI metrics and their effectiveness.',NULL,1,51,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1058,12,1055,'MEASURE 1.3','Independent Assessment','Internal experts who did not serve as front-line developers for the system and/or independent assessors are involved in regular assessments and updates. Internal experts are determined to have sufficient expertise and are free of conflicts of interest.','Check for independent review processes and assessor qualifications.',NULL,1,52,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1059,12,NULL,'MEASURE 2','Trustworthiness Evaluation','AI systems are evaluated for trustworthy characteristics.',NULL,NULL,1,53,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1060,12,1059,'MEASURE 2.1','TEVV Documentation','Test sets, metrics, and details about the tools used during Test, Evaluation, Verification, and Validation (TEVV) are documented.','Review TEVV documentation including test datasets, metrics, and tooling.',NULL,1,54,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1061,12,1059,'MEASURE 2.2','Human Subject Evaluations','Evaluations involving human subjects meet applicable requirements (including human subject protection) and are representative of the relevant population.','Verify IRB approvals or equivalent and representativeness of evaluation populations.',NULL,1,55,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1062,12,1059,'MEASURE 2.3','Deployment-Condition Testing','AI system performance or assurance criteria are measured qualitatively or quantitatively and demonstrated for conditions similar to deployment setting(s). Measurement results regarding AI system trustworthiness in deployment conditions and target change conditions are documented.','Check that testing conditions reflect actual deployment environments.',NULL,1,56,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1063,12,1059,'MEASURE 2.4','Production Monitoring','The functionality and behavior of the AI system and its components â€“ as identified in the MAP function â€“ are monitored when in production.','Verify production monitoring tools, dashboards, and alerting for AI system behavior.',NULL,1,57,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1064,12,1059,'MEASURE 2.5','Validity and Reliability','The AI system to be deployed is demonstrated to be valid and reliable. Limitations of the generalizability beyond the conditions under which the technology was developed are documented.','Review validation reports and generalizability limitation documentation.',NULL,1,58,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1065,12,1059,'MEASURE 2.6','Safety Evaluation','AI system is evaluated regularly for safety risks â€“ as identified in the MAP function. The AI system to be deployed is demonstrated to be safe, its residual negative risk does not exceed the risk tolerance, and it can fail safely, particularly if made to operate beyond its knowledge limits. Safety metrics reflect system reliability and robustness, real-time monitoring, and response times for AI system failures.','Verify safety evaluation processes and fail-safe mechanisms.',NULL,1,59,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1066,12,1059,'MEASURE 2.7','Security and Resilience','AI system security and resilience â€“ as identified in the MAP function â€“ are evaluated and documented.','Review security assessments specific to AI systems including adversarial attack resistance.',NULL,1,60,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1067,12,1059,'MEASURE 2.8','Transparency and Accountability','Risks associated with transparency and accountability â€“ as identified in the MAP function â€“ are examined and documented.','Check transparency reports and accountability mechanisms for AI systems.',NULL,1,61,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1068,12,1059,'MEASURE 2.9','Explainability and Interpretability','The AI model is explained, validated, and documented, and AI system output is interpreted within its context â€“ as identified in the MAP function â€“ to inform responsible use and governance.','Review model explanation documentation and output interpretation guidelines.',NULL,1,62,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1069,12,1059,'MEASURE 2.10','Privacy Risk','Privacy risk of the AI system â€“ as identified in the MAP function â€“ are examined and documented.','Verify privacy impact assessments specific to AI data processing and model training.',NULL,1,63,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1070,12,1059,'MEASURE 2.11','Fairness and Bias','Fairness and bias â€“ as identified in the MAP function â€“ are evaluated and the results are documented.','Review bias testing results, fairness metrics, and documentation of identified biases.',NULL,1,64,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1071,12,1059,'MEASURE 2.12','Environmental Impact','Environmental impact and sustainability of AI model training and management activities â€“ as identified in the MAP function â€“ are assessed and documented.','Check environmental impact assessments for AI training compute and energy usage.',NULL,1,65,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1072,12,1059,'MEASURE 2.13','TEVV Effectiveness','Effectiveness of the employed TEVV metrics and processes in the MEASURE function are evaluated and documented.','Verify meta-evaluation of TEVV processes and their effectiveness.',NULL,1,66,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1073,12,NULL,'MEASURE 3','Risk Tracking','Mechanisms for tracking identified AI risks over time are in place.',NULL,NULL,1,67,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1074,12,1073,'MEASURE 3.1','Risk Tracking Approaches','Approaches, personnel, and documentation are in place to regularly identify and track existing, unanticipated, and emergent AI risks based on factors such as intended and actual performance in deployed contexts.','Review risk tracking mechanisms and personnel assignments.',NULL,1,68,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1075,12,1073,'MEASURE 3.2','Measurement Gap Tracking','Risk tracking approaches are considered for settings where AI risks are difficult to assess using currently available measurement techniques or where metrics are not yet available.','Check for processes to track risks where quantitative measurement is not yet possible.',NULL,1,69,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1076,12,1073,'MEASURE 3.3','End User Feedback and Appeals','Feedback processes for end users and impacted communities to report problems and appeal system outcomes are established and integrated into the AI system\'s design and deployment.','Verify user feedback channels, complaint mechanisms, and appeal processes.',NULL,1,70,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1077,12,NULL,'MEASURE 4','Measurement Feedback','Feedback about efficacy of measurement is gathered and assessed.',NULL,NULL,1,71,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1078,12,1077,'MEASURE 4.1','Deployment Context Measurement','Measurement approaches for identifying AI risks are connected to deployment context(s) and informed by domain expertise and input from other groups.','Verify that measurement approaches reflect deployment context and domain expertise.',NULL,1,72,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1079,12,1077,'MEASURE 4.2','Trustworthiness Validation','Measurement results regarding AI system trustworthiness in deployment context(s) and target change conditions are informed by input from domain experts and relevant AI actors to validate, contextualize, and document the results.','Check validation processes for AI trustworthiness measurements.',NULL,1,73,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1080,12,1077,'MEASURE 4.3','Performance Improvement Tracking','Measurable performance improvements or declines based on consultations with relevant AI actors including combatants of AI-enabled decisions are identified and documented.','Review performance trend documentation and consultation records.',NULL,1,74,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1081,12,NULL,'MANAGE 1','Risk Prioritization and Response','AI risks based on assessments and other analytical output from the MAP and MEASURE functions are prioritized, responded to, and managed.',NULL,NULL,1,75,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1082,12,1081,'MANAGE 1.1','Go/No-Go Determination','A determination is made as to whether the AI system achieves its intended purposes and stated objectives and whether its development or deployment should proceed.','Verify go/no-go decision processes and criteria for AI system deployment.',NULL,1,76,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1083,12,1081,'MANAGE 1.2','Risk Treatment Prioritization','Treatment of documented AI risks is prioritized based on impact, likelihood, and available resources or methods.','Review risk treatment prioritization methodology and documentation.',NULL,1,77,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1084,12,1081,'MANAGE 1.3','Risk Response Planning','Responses to the AI risks deemed high priority, as identified by the MAP function, are developed, planned, and documented. Risk response options can include mitigating, transferring, avoiding, or accepting.','Check risk response plans for high-priority AI risks including selected response strategies.',NULL,1,78,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1085,12,1081,'MANAGE 1.4','Residual Risk Documentation','Negative residual risks (defined as the risks remaining after risk treatment) to both downstream acquirers of AI systems and end users are documented.','Verify documentation of residual risks and communication to downstream stakeholders.',NULL,1,79,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1086,12,NULL,'MANAGE 2','Benefit Maximization and Impact Minimization','Strategies to maximize AI benefits and minimize negative impacts are planned, prepared, implemented, documented, and informed by input from relevant AI actors.',NULL,NULL,1,80,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1087,12,1086,'MANAGE 2.1','Non-AI Alternatives','Resources required to manage AI risks are taken into account â€“ along with viable non-AI alternative systems, approaches, or methods â€“ to reduce the magnitude or likelihood of potential impacts.','Review analysis of non-AI alternatives and resource allocation for AI risk management.',NULL,1,81,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1088,12,1086,'MANAGE 2.2','Value Sustainment','Mechanisms are in place and applied to sustain the value of deployed AI systems.','Verify ongoing value monitoring and sustainment processes for deployed AI systems.',NULL,1,82,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1089,12,1086,'MANAGE 2.3','Unknown Risk Response','Procedures are followed to respond to and recover from a previously unknown risk when it is identified.','Check incident response procedures specific to newly discovered AI risks.',NULL,1,83,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1090,12,1086,'MANAGE 2.4','AI System Disengagement','Mechanisms are in place and applied, and responsibilities are assigned and understood, to supersede, disengage, or deactivate AI systems that demonstrate performance or outcomes inconsistent with intended use.','Verify kill-switch or disengagement procedures and assigned responsibilities.',NULL,1,84,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1091,12,NULL,'MANAGE 3','Third-Party AI Risk Management','AI risks and benefits from third-party entities are managed.',NULL,NULL,1,85,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1092,12,1091,'MANAGE 3.1','Third-Party Monitoring','AI risks and benefits from third-party resources are regularly monitored, and risk controls are applied and documented.','Review third-party AI vendor monitoring processes and risk control documentation.',NULL,1,86,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1093,12,1091,'MANAGE 3.2','Pre-Trained Model Monitoring','Pre-trained models which are used for development are monitored as part of AI system regular monitoring and maintenance.','Verify monitoring processes for pre-trained and foundation models used in AI systems.',NULL,1,87,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1094,12,NULL,'MANAGE 4','Risk Treatment Monitoring','Risk treatments, including response and recovery, and communication plans for the identified and measured AI risks are documented and monitored regularly.',NULL,NULL,1,88,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1095,12,1094,'MANAGE 4.1','Post-Deployment Monitoring','Post-deployment AI system monitoring plans are implemented, including mechanisms for capturing and evaluating input from users and other relevant AI actors, appeal and override, decommissioning, incident response, recovery, and change management.','Verify post-deployment monitoring plans and incident response procedures.',NULL,1,89,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1096,12,1094,'MANAGE 4.2','Continual Improvement','Measurable activities for continual improvements are integrated into AI system updates and include regular engagement with interested parties, including relevant AI actors.','Check continual improvement processes and stakeholder engagement for AI systems.',NULL,1,90,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(1097,12,1094,'MANAGE 4.3','Incident Communication','Incidents and errors are communicated to relevant AI actors, including affected communities. Processes for tracking, responding to, and recovering from incidents and errors are followed and documented.','Review AI incident communication processes and response documentation.',NULL,1,91,'2026-03-13 03:52:56','2026-03-13 03:52:56');
UNLOCK TABLES;

-- =============================================================================
-- SECTION 5: Seed Data - GRC Unified Questions (173 questions)
-- =============================================================================

LOCK TABLES `grc_unified_questions` WRITE;
INSERT IGNORE INTO `grc_unified_questions` (`id`, `question_ref`, `domain_id`, `question_text`, `guidance`, `maturity_1_desc`, `maturity_2_desc`, `maturity_3_desc`, `maturity_4_desc`, `control_examples`, `is_required`, `sort_order`, `created_at`, `updated_at`) VALUES (1,'GOV-01',1,'Has the organization established a formal information security policy approved by executive leadership?','Look for a documented information security policy signed by the CEO, CISO, or board. Verify it has been communicated to all employees and reviewed within the last 12 months.','No formal security policy exists or it is severely outdated.','A security policy exists but may not be comprehensive or regularly reviewed.','Comprehensive security policy is documented, approved by leadership, communicated to all staff, and reviewed annually.','Policy is continuously improved based on threat intelligence and business changes with automated compliance checking.','Examples: Written Information Security Policy document, Board minutes showing approval, Email/portal distribution records, Annual review change log.',1,1,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(2,'GOV-02',1,'Are information security roles, responsibilities, and reporting structures clearly defined, including a designated CISO or equivalent?','Verify there is an organizational chart showing security leadership, a named CISO or equivalent, and documented responsibilities for security staff. Check that the CISO reports to senior leadership.','No dedicated security role exists; security is handled ad hoc by IT staff.','A security lead is designated but lacks formal authority or dedicated team.','CISO or equivalent is appointed with clear authority, dedicated team, and defined reporting to executive leadership.','Security organization is mature with specialized roles, succession planning, and regular capability assessments.','Examples: Org chart with CISO position, Job descriptions for security roles, RACI matrix for security functions, Board committee charter.',1,2,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(3,'GOV-03',1,'Does the board or executive leadership receive regular cybersecurity risk briefings and provide strategic direction?','Check for evidence of board-level cybersecurity reporting at least quarterly. Look for board meeting minutes, risk dashboards, and strategic decisions documented.','Board has no visibility into cybersecurity posture or risks.','Board receives occasional security updates, typically after incidents.','Board receives quarterly cybersecurity briefings with risk metrics, compliance status, and strategic recommendations.','Board actively drives cybersecurity strategy with real-time dashboards, scenario planning, and dedicated cyber committee.','Examples: Board meeting minutes with security agenda items, Quarterly CISO board presentation, Risk dashboard screenshots, Board-approved security budget.',1,3,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(4,'GOV-04',1,'Has the organization developed a multi-year cybersecurity strategy and roadmap aligned with business objectives?','Look for a documented 2-3 year security strategy that ties security initiatives to business goals. Should include milestones, resource requirements, and prioritized projects.','No security strategy exists; actions are purely reactive.','Informal security plans exist but are not aligned with business objectives or funded.','Documented multi-year strategy exists, aligned with business goals, with funded initiatives and quarterly progress tracking.','Strategy is dynamic, continuously updated based on threat landscape, and integrated into enterprise strategic planning.','Examples: Security strategy document, Project roadmap with timelines, Budget allocation records, Quarterly progress reports.',1,4,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(5,'GOV-05',1,'Has the organization implemented a formal risk management framework to identify, assess, and treat information security risks?','Verify the organization uses a recognized risk methodology (NIST RMF, ISO 31000, FAIR). Check for a risk register, risk assessment procedures, and evidence of regular risk reviews.','No formal risk management process exists.','Basic risk assessments are performed but not consistently or with a standard methodology.','Formal risk management framework is implemented with documented methodology, maintained risk register, and regular reviews.','Risk management is quantitative, automated, integrated with business decisions, and continuously updated.','Examples: Risk management policy, Risk register with ratings, Risk assessment methodology document, Quarterly risk review meeting minutes.',1,5,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(6,'GOV-06',1,'Does the organization maintain an inventory of applicable regulatory, legal, and contractual security requirements?','Look for a documented list of all applicable regulations (GDPR, HIPAA, PCI DSS, SOX, etc.), legal obligations, and contractual security requirements. Verify it is reviewed when new contracts or regulations emerge.','No inventory of regulatory requirements exists.','Some regulations are known but not formally tracked or mapped to controls.','Complete inventory maintained with each requirement mapped to controls and assigned owners, reviewed annually.','Automated regulatory change monitoring with impact analysis and proactive control updates.','Examples: Regulatory requirements register, Contract security clause tracker, Legal compliance matrix, Regulatory change alerts.',1,6,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(7,'GOV-07',1,'Is there dedicated budget allocation for information security with adequate resources to implement the security program?','Verify a dedicated security budget exists separate from general IT spending. Check for resource planning, staffing plans, and technology investment documentation.','No dedicated security budget; security spending comes from general IT ad hoc.','Some budget exists but is insufficient and competes with IT operational spending.','Dedicated security budget approved by leadership, aligned with risk priorities, and tracked separately.','Budget is risk-driven, benchmarked against industry peers, with scenario-based funding models.','Examples: Annual security budget document, Headcount plan, Technology investment roadmap, Budget vs actual spending reports.',1,7,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(8,'GOV-08',1,'Is there an established security steering committee or governance body with cross-functional representation?','Look for a governance body that meets regularly with representatives from IT, legal, compliance, HR, and business units. Check meeting minutes and decision records.','No security governance body exists.','Informal meetings occur but without structure, agenda, or documented outcomes.','Formal steering committee meets quarterly with documented charter, cross-functional membership, and tracked action items.','Governance body drives strategic decisions with escalation paths, sub-committees for specific domains, and effectiveness reviews.','Examples: Committee charter, Membership roster, Meeting minutes, Decision log, Action item tracker.',1,8,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(9,'GOV-09',1,'Does the organization track and report cybersecurity metrics and KPIs to measure program effectiveness?','Check for defined security metrics (mean time to detect, patch compliance rate, training completion, etc.) that are regularly reported to management.','No security metrics are tracked.','Some metrics are collected but not consistently reported or acted upon.','Defined KPIs are tracked monthly, reported to leadership, and used to drive improvement initiatives.','Real-time security dashboards with trend analysis, automated alerting on KPI deviations, and predictive analytics.','Examples: Monthly security metrics report, KPI dashboard screenshots, Trend analysis charts, Management review meeting minutes.',1,9,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(10,'GOV-10',1,'Has the organization established a code of conduct and ethics policy that addresses information security responsibilities?','Look for a code of conduct that includes acceptable use of technology, data handling expectations, and consequences for violations. Verify employee acknowledgment records.','No code of conduct exists or it does not address information security.','Code of conduct exists but security expectations are vague or not enforced.','Comprehensive code of conduct with specific security provisions, annual employee acknowledgment, and clear enforcement procedures.','Code is integrated with security awareness, includes scenario-based guidance, and violations are tracked with corrective actions.','Examples: Code of conduct document, Employee acknowledgment forms, Violation tracking records, Annual distribution receipts.',1,10,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(11,'GOV-11',1,'Is there a formal process for requesting, reviewing, and approving exceptions to security policies?','Verify a documented exception process exists with risk-based approval, time-limited exceptions, and compensating controls. Check the exception register.','No exception process exists; policies are bypassed informally.','Exceptions are handled case-by-case without consistent documentation or approval.','Formal exception process with risk assessment, compensating controls, time limits, and management approval documented.','Exception data feeds into policy improvement, automated expiration tracking, and trend analysis to identify systemic issues.','Examples: Exception request form template, Exception register with approvals, Compensating control documentation, Expiration tracking log.',1,11,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(12,'GOV-12',1,'Is information security integrated into business processes, project management, and organizational change management?','Check that security is considered in new projects, business changes, and process designs. Look for security gates in project lifecycle and change management procedures.','Security is not considered in business processes or projects.','Security is occasionally consulted for major projects but not systematically.','Security review is a required gate in project lifecycle, change management includes security impact analysis.','Security is embedded in all business processes with automated security assessment tools integrated into DevOps and change workflows.','Examples: Project security review checklist, Change management security impact assessment form, Security gate sign-off records, DevSecOps pipeline evidence.',1,12,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(13,'IAM-01',2,'Does the organization have a formal process for provisioning and deprovisioning user accounts across all systems?','Verify documented procedures for creating accounts when employees join and disabling/deleting accounts when they leave. Check for automated HR-to-IT integration and timely access removal.','Account creation and removal is manual and inconsistent with no formal process.','Basic procedures exist but rely on manual requests and may have delays in deprovisioning.','Automated provisioning/deprovisioning integrated with HR systems, SLA for access removal within 24 hours of termination.','Fully automated identity lifecycle with real-time HR integration, automated access removal, and continuous reconciliation.','Examples: Account provisioning procedure, HR-IT integration workflow, Terminated user access removal logs, Account reconciliation reports.',1,1,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(14,'IAM-02',2,'Is the principle of least privilege enforced, ensuring users only have access necessary for their job functions?','Check that access is granted based on job requirements, not inherited from previous roles. Look for role definitions, access matrices, and evidence that excessive privileges are identified and removed.','No least privilege enforcement; users accumulate access over time without review.','Least privilege is a policy but not consistently enforced; privilege creep is common.','Access is granted based on defined roles, periodic reviews catch excessive access, and privilege requests require justification.','Just-in-time access provisioning, automated privilege analysis, and continuous enforcement with anomaly detection.','Examples: Role-based access matrix, Access request approval records, Privilege review reports, Account audit showing no excessive access.',1,2,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(15,'IAM-03',2,'Is multi-factor authentication (MFA) implemented for all remote access, privileged accounts, and critical systems?','Verify MFA is enabled for VPN, cloud services, email, privileged accounts, and administrative consoles. Check MFA enrollment rates and enforcement policies.','MFA is not implemented or only used for a few systems.','MFA is deployed for some critical systems but not comprehensively enforced.','MFA is required for all remote access, privileged accounts, cloud services, and critical applications with high enrollment rates.','Phishing-resistant MFA (FIDO2/WebAuthn) deployed universally with adaptive authentication and risk-based step-up.','Examples: MFA policy, MFA enrollment rate report, Conditional access policy screenshots, FIDO2 key deployment records.',1,3,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(16,'IAM-04',2,'Is privileged access managed through dedicated tools with session monitoring, just-in-time access, and credential vaulting?','Look for a PAM solution that vaults privileged credentials, provides session recording, enforces just-in-time access, and rotates passwords automatically.','Privileged accounts use shared passwords with no monitoring or vaulting.','Some privileged accounts are managed but without session recording or automated rotation.','PAM solution deployed with credential vaulting, session recording, just-in-time access, and automated password rotation.','Zero standing privileges with fully automated PAM, behavioral analytics, and real-time risk scoring of privileged sessions.','Examples: PAM tool deployment evidence, Session recording samples, Password rotation logs, Just-in-time access request records.',1,4,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(17,'IAM-05',2,'Are user access reviews conducted periodically to validate that access rights remain appropriate?','Verify access reviews are performed at least quarterly for privileged accounts and semi-annually for standard users. Check for reviewer sign-offs and remediation of findings.','No access reviews are performed.','Access reviews happen annually but are inconsistent and findings are not always remediated.','Quarterly access reviews for privileged accounts, semi-annual for all users, with documented sign-offs and tracked remediation.','Continuous access certification with automated anomaly detection, risk-based review frequency, and machine learning recommendations.','Examples: Access review schedule, Reviewer sign-off records, Remediation tracking log, Access certification campaign report.',1,5,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(18,'IAM-06',2,'Does the organization enforce strong password policies and secure credential management practices?','Check password policy settings (length, complexity, history, lockout). Verify credentials are not stored in plain text and a password manager is available for employees.','Weak or no password policy; credentials may be stored insecurely.','Basic password policy exists but may not meet current standards; no enterprise password manager.','Strong password policy enforced (14+ chars, complexity, history), enterprise password manager deployed, no plain-text credential storage.','Passwordless authentication adopted where possible, credential breach monitoring, and adaptive password policies based on risk.','Examples: Password policy document, Active Directory policy screenshots, Password manager deployment records, Credential breach monitoring alerts.',1,6,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(19,'IAM-07',2,'Is single sign-on (SSO) implemented to centralize authentication across business applications?','Look for SSO integration with major business applications using SAML, OIDC, or similar protocols. Check the percentage of applications federated through the identity provider.','No SSO; users maintain separate credentials for each application.','SSO is partially deployed for some cloud applications but not comprehensive.','SSO deployed for majority of applications with SAML/OIDC federation, centralized authentication policies.','Universal SSO with all applications federated, legacy app integration, and centralized session management.','Examples: SSO-integrated application list, Identity provider configuration screenshots, SAML federation records, SSO coverage percentage report.',1,7,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(20,'IAM-08',2,'Are service accounts, system accounts, and non-human identities inventoried, secured, and regularly reviewed?','Verify an inventory of all service accounts exists with assigned owners. Check that service account passwords are rotated, have restricted permissions, and interactive login is disabled where possible.','Service accounts are untracked, use shared passwords, and have excessive privileges.','Some service accounts are documented but reviews and password rotation are inconsistent.','Complete service account inventory with assigned owners, regular password rotation, restricted permissions, and periodic reviews.','Managed service identities with automated credential rotation, certificate-based authentication, and continuous monitoring.','Examples: Service account inventory spreadsheet, Password rotation schedules, Owner assignment records, Interactive login restriction evidence.',1,8,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(21,'IAM-09',2,'Are remote access connections secured with encryption, MFA, and monitored for unauthorized use?','Check VPN, ZTNA, or remote desktop configurations for encryption standards, MFA enforcement, and logging. Verify split-tunnel policies and geo-restriction rules.','Remote access is unrestricted with no encryption or monitoring.','VPN is deployed with basic encryption but MFA may not be enforced for all remote users.','All remote access requires MFA, uses strong encryption, with logging and monitoring for anomalous connections.','Zero trust network access (ZTNA) deployed with continuous device posture assessment and adaptive access policies.','Examples: VPN/ZTNA configuration screenshots, MFA enforcement policy, Remote access logs, Geo-restriction rules documentation.',1,9,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(22,'IAM-10',2,'Is a centralized directory service or identity provider used to manage all user identities?','Look for Active Directory, Azure AD/Entra ID, or equivalent identity provider managing all user accounts. Verify centralized group policies, authentication, and directory synchronization.','No centralized directory; local accounts on individual systems.','Directory service exists but not all systems are integrated; some local accounts persist.','Centralized directory manages all user identities with group policies, synchronized across cloud and on-premises environments.','Unified identity platform with cloud-native directory, real-time sync, and identity analytics for all environments.','Examples: Directory service architecture diagram, Group policy configuration, Cloud sync status reports, Account integration coverage report.',1,10,'2026-03-09 13:56:18','2026-03-09 13:56:18'),
(23,'IAM-11',2,'Is role-based access control (RBAC) implemented with documented roles mapped to job functions?','Verify defined roles exist for each job function with documented access permissions. Check that role assignments are based on position, not individual requests.','No defined roles; access is granted individually without standardization.','Some roles are defined for critical systems but not consistently applied across all platforms.','Comprehensive RBAC with documented roles for all job functions, enforced across all systems, and reviewed periodically.','Dynamic RBAC with attribute-based policies, automated role mining, and real-time role optimization based on usage patterns.','Examples: Role definition matrix, Role-to-permission mapping document, System role assignment screenshots, Role review records.',1,11,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(24,'IAM-12',2,'Are session management controls implemented including automatic timeouts and re-authentication requirements?','Check session timeout settings for web applications, VPN, and administrative consoles. Verify inactive sessions are terminated and re-authentication is required for sensitive operations.','No session timeout controls; sessions remain active indefinitely.','Basic timeouts exist for some systems but are inconsistently configured.','Consistent session timeouts across all systems (15-30 min inactivity), re-authentication for sensitive operations, concurrent session limits.','Adaptive session management based on risk context, continuous authentication signals, and automated session anomaly detection.','Examples: Session timeout configuration screenshots, Application session policy settings, Re-authentication prompt evidence, Concurrent session limit configuration.',1,12,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(25,'IAM-13',2,'Are emergency or break-glass access procedures documented and monitored for privileged access during emergencies?','Look for documented procedures for emergency access when normal authentication fails. Verify break-glass accounts are monitored, usage is logged and reviewed, and credentials are changed after use.','No emergency access procedures; staff share admin passwords or bypass security.','Informal emergency access exists but is not documented or monitored.','Documented break-glass procedures with sealed credentials, mandatory usage logging, post-use review, and immediate credential rotation.','Automated break-glass workflow with real-time alerts, automatic credential rotation after use, and integrated incident tracking.','Examples: Break-glass procedure document, Sealed credential envelope tracking log, Emergency access usage reports, Post-use review records.',1,13,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(26,'IAM-14',2,'Is authentication for APIs and automated services secured using tokens, certificates, or OAuth rather than embedded credentials?','Verify API authentication uses secure methods (OAuth 2.0, API keys with rotation, mutual TLS). Check that no credentials are hardcoded in source code or configuration files.','API authentication uses hardcoded credentials or no authentication at all.','API keys are used but not rotated regularly; some embedded credentials may exist.','OAuth 2.0 or certificate-based API authentication with regular key rotation, secrets management, and no hardcoded credentials.','Automated API credential lifecycle with secrets management vault, short-lived tokens, mutual TLS, and continuous scanning for credential leaks.','Examples: API authentication configuration, Secrets management vault deployment, Code scanning results for hardcoded credentials, API key rotation logs.',1,14,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(27,'DSP-01',3,'Has the organization implemented a data classification scheme that categorizes data by sensitivity level?','Look for a documented classification scheme (e.g., Public, Internal, Confidential, Restricted) with criteria, handling rules, and labeling requirements for each level.','No data classification scheme exists; all data treated the same.','Classification scheme is defined but not consistently applied across the organization.','Classification scheme with clear criteria and handling rules is implemented, labels applied to data stores, and staff trained.','Automated data discovery and classification with ML-based labeling, real-time policy enforcement, and continuous data inventory.','Examples: Data classification policy, Classification labels on documents/systems, Data inventory by classification level, Training completion records.',1,1,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(28,'DSP-02',3,'Is sensitive data encrypted at rest using industry-standard encryption algorithms across all storage locations?','Verify encryption at rest for databases, file servers, cloud storage, backups, and endpoints. Check encryption algorithms (AES-256 minimum) and key management practices.','No encryption at rest; sensitive data stored in plaintext.','Some systems have encryption but coverage is incomplete; older algorithms may be used.','AES-256 encryption at rest across all storage locations with centralized key management and documented coverage.','Encryption with automated key rotation, hardware-backed key storage (HSM), and continuous compliance monitoring for encryption gaps.','Examples: Encryption configuration screenshots, Key management procedure, Encryption coverage report by system, HSM deployment evidence.',1,2,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(29,'DSP-03',3,'Is data encrypted in transit using TLS 1.2 or higher for all internal and external communications?','Check TLS configurations for web servers, APIs, email, and internal network traffic. Verify certificates are valid and TLS 1.0/1.1 is disabled.','Data transmitted without encryption or using deprecated protocols.','TLS is used for external-facing services but internal traffic may be unencrypted.','TLS 1.2+ enforced for all external and internal communications with certificate management and deprecated protocols disabled.','TLS 1.3 preferred, mutual TLS for service-to-service communication, automated certificate lifecycle, and continuous cipher suite monitoring.','Examples: TLS configuration scan results, SSL certificate inventory, Deprecated protocol disable evidence, Internal traffic encryption policy.',1,3,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(30,'DSP-04',3,'Is a data loss prevention (DLP) solution deployed to monitor, detect, and prevent unauthorized data exfiltration?','Look for DLP controls covering email, web uploads, USB transfers, cloud storage, and endpoint activity. Check DLP policies aligned with data classification.','No DLP controls exist.','Basic email DLP exists but does not cover all exfiltration channels.','Comprehensive DLP across email, web, endpoints, and cloud with policies aligned to data classification and incident review.','AI-driven DLP with user behavior analytics, automated response, and integration with SIEM for correlated analysis.','Examples: DLP policy configuration, DLP incident reports, Channel coverage documentation, DLP alert review records.',1,4,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(31,'DSP-05',3,'Are privacy impact assessments (PIAs) conducted for new systems, processes, or projects that handle personal data?','Verify PIAs are performed before launching new systems or processes involving personal data. Check for PIA templates, completed assessments, and remediation tracking.','No privacy impact assessments are conducted.','PIAs are done for some projects but not systematically required.','PIAs are mandatory for all projects involving personal data, with standard templates, documented findings, and remediation tracking.','Automated PIA workflows integrated into project management, continuous privacy monitoring, and dynamic data mapping.','Examples: PIA policy, Completed PIA documents, PIA findings remediation log, Privacy review gate in project lifecycle.',1,5,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(32,'DSP-06',3,'Are data retention schedules defined and enforced, including secure disposal of data that exceeds retention periods?','Look for documented retention periods by data type, automated enforcement of retention policies, and secure disposal procedures (cryptographic erasure, physical destruction).','No retention schedules; data is kept indefinitely with no disposal process.','Retention schedules exist for some data types but enforcement is manual and inconsistent.','Comprehensive retention schedules enforced with automated deletion, secure disposal procedures, and certificates of destruction.','Automated data lifecycle management with continuous compliance monitoring and legal hold integration.','Examples: Data retention schedule, Automated deletion policy configurations, Certificates of destruction, Disposal audit logs.',1,6,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(33,'DSP-07',3,'Does the organization comply with privacy regulations regarding personal data collection, consent, and individual rights?','Verify compliance with applicable privacy laws (GDPR, CCPA, HIPAA). Check for consent mechanisms, privacy notices, data subject access request procedures, and breach notification processes.','No awareness of or compliance with privacy regulations.','Basic privacy notices exist but consent management and rights processes are incomplete.','Full privacy compliance program with consent management, DSAR procedures, privacy notices, and documented processing activities.','Automated privacy compliance with consent management platform, real-time DSAR fulfillment, and privacy-by-design integration.','Examples: Privacy notice, Consent management records, DSAR procedure and completion logs, Records of processing activities (ROPA).',1,7,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(34,'DSP-08',3,'Is data masking or tokenization used to protect sensitive data in non-production environments and for analytics?','Check that production data used in development, testing, or analytics environments is masked or tokenized. Verify sensitive fields cannot be reversed.','Production data with real PII/sensitive data used directly in non-production environments.','Some data masking exists but coverage is incomplete across all non-production environments.','Automated data masking/tokenization for all non-production environments with irreversible masking for PII and sensitive data.','Dynamic data masking with role-based visibility, synthetic data generation, and automated detection of unmasked sensitive data.','Examples: Data masking policy, Masking tool configuration, Non-production environment data audit, Tokenization implementation records.',1,8,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(35,'DSP-09',3,'Are database security controls implemented including access restrictions, query monitoring, and configuration hardening?','Verify database access is restricted to authorized accounts, administrative access requires MFA, query monitoring detects anomalies, and databases follow hardening benchmarks (CIS).','Databases have default configurations with unrestricted access.','Basic access controls exist but no query monitoring or hardening benchmarks applied.','Database access restricted by role, CIS benchmarks applied, query monitoring active, and administrative access requires MFA.','Database activity monitoring with ML-based anomaly detection, automated hardening compliance, and real-time alerting.','Examples: Database access control matrix, CIS benchmark compliance report, Query monitoring alert samples, Database hardening checklist.',1,9,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(36,'DSP-10',3,'Are data backups regularly tested to verify integrity and successful restoration within defined recovery objectives?','Check backup schedules, test restoration records, and alignment with RTO/RPO. Verify backup encryption and off-site/cloud storage.','Backups exist but are never tested; restoration capability is unknown.','Backups are tested occasionally but not systematically aligned with RTO/RPO targets.','Regular backup testing (at least quarterly) with documented restoration results, encrypted backups, and off-site storage.','Automated backup validation with continuous integrity checking, immutable backups, and instant recovery testing.','Examples: Backup schedule documentation, Restoration test records with timestamps, RTO/RPO alignment report, Off-site backup evidence.',1,10,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(37,'DSP-11',3,'Are controls in place to manage cross-border data transfers in compliance with applicable data protection laws?','Check for data transfer mechanisms (Standard Contractual Clauses, adequacy decisions, binding corporate rules) for international transfers. Verify data residency requirements are met.','No awareness of cross-border transfer requirements; data flows unrestricted.','Some awareness but transfer mechanisms may be incomplete or not documented.','Documented data transfer mechanisms in place, data residency requirements mapped, and transfer impact assessments completed.','Automated data flow mapping with real-time transfer monitoring, dynamic policy enforcement, and continuous compliance assessment.','Examples: Data transfer impact assessment, Standard Contractual Clauses, Data flow map showing cross-border transfers, Data residency documentation.',1,11,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(38,'DSP-12',3,'Is a records management program in place to ensure the integrity, availability, and proper handling of business records?','Look for records management policies, retention schedules, and evidence of proper handling for legal, regulatory, and business records. Check audit trail capabilities.','No records management program exists.','Basic records retention exists but without formal program or comprehensive coverage.','Formal records management program with retention schedules, access controls, audit trails, and legal hold capabilities.','Automated records lifecycle management with intelligent classification, compliance monitoring, and eDiscovery readiness.','Examples: Records management policy, Retention schedule by record type, Audit trail reports, Legal hold procedure documentation.',1,12,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(39,'EPS-01',4,'Is endpoint detection and response (EDR) or equivalent anti-malware protection deployed on all endpoints?','Verify EDR/AV coverage across all workstations, laptops, and servers. Check for real-time protection, behavioral analysis, and centralized management console.','No endpoint protection or only basic legacy antivirus on some endpoints.','Antivirus deployed but without EDR capabilities or full coverage.','EDR deployed on all endpoints with real-time protection, behavioral analysis, centralized management, and automated response.','XDR platform with cross-layer correlation, automated threat hunting, and integration with SOAR for orchestrated response.','Examples: EDR deployment coverage report, Centralized console dashboard screenshot, Detection and response policy, Malware detection logs.',1,1,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(40,'EPS-02',4,'Are operating systems and platforms hardened according to industry benchmarks such as CIS or DISA STIGs?','Check for documented hardening standards, CIS benchmark compliance scans, and evidence that unnecessary services, ports, and accounts are disabled.','Systems use default configurations with no hardening applied.','Some hardening is applied but not based on a recognized benchmark; inconsistently enforced.','CIS or DISA STIG benchmarks applied to all systems with regular compliance scanning and documented exceptions.','Automated hardening with infrastructure-as-code, continuous compliance drift detection, and auto-remediation.','Examples: CIS benchmark compliance scan report, Hardening standard document, Configuration baseline records, Exception documentation.',1,2,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(41,'EPS-03',4,'Is a patch management program in place to deploy security updates to all endpoints within defined timeframes?','Verify patch management covers OS, applications, and firmware. Check SLAs for critical patches (e.g., 14 days for critical, 30 days for high). Review patch compliance reports.','No formal patch management; updates are ad hoc or never applied.','Patching occurs but without defined SLAs, full coverage, or compliance tracking.','Formal patch program with defined SLAs, automated deployment, coverage tracking, and exception management.','Risk-based patching with automated prioritization, zero-day response procedures, and near-100% compliance rates.','Examples: Patch management policy with SLAs, Patch compliance percentage report, Automated deployment tool dashboard, Exception/deferral records.',1,3,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(42,'EPS-04',4,'Is mobile device management (MDM) deployed to enforce security policies on smartphones and tablets?','Check MDM enrollment rates, policy enforcement (passcode, encryption, remote wipe), app management, and conditional access tied to device compliance.','No MDM; personal and corporate mobile devices are unmanaged.','MDM exists but enrollment is optional or policies are not comprehensive.','MDM required for all corporate mobile devices with encryption, passcode, remote wipe, and conditional access enforcement.','Unified endpoint management with BYOD containerization, continuous device posture assessment, and automated compliance remediation.','Examples: MDM enrollment rate report, Mobile security policy, Remote wipe capability test records, Conditional access policy screenshots.',1,4,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(43,'EPS-05',4,'Are controls in place to restrict or monitor the use of removable media and USB storage devices?','Verify USB mass storage is blocked or restricted via endpoint policy. Check for exceptions process and monitoring of removable media usage.','No restrictions on USB or removable media usage.','USB restrictions exist for some systems but are not consistently enforced.','USB mass storage blocked across all endpoints via policy with documented exceptions and usage monitoring.','Granular device control with allowlisting specific devices, encrypted-only media enforcement, and automated DLP for removable media.','Examples: USB restriction policy configuration screenshots, Group Policy/MDM settings, Exception approval records, Removable media usage logs.',1,5,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(44,'EPS-06',4,'Is a comprehensive hardware and software asset inventory maintained and kept current?','Look for an automated asset discovery tool that maintains inventory of all hardware and software assets. Check for asset owners, classification, and regular reconciliation.','No asset inventory exists or it is severely outdated.','Partial inventory exists but relies on manual updates and does not cover all assets.','Automated asset discovery with comprehensive inventory of hardware and software, assigned owners, and regular reconciliation.','Real-time asset inventory with automated discovery, classification, lifecycle tracking, and integration with vulnerability management.','Examples: Asset inventory database/report, Automated discovery tool dashboard, Asset reconciliation records, Unauthorized asset detection alerts.',1,6,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(45,'EPS-07',4,'Is application whitelisting or allowlisting enforced to prevent unauthorized software execution?','Check for application control policies that restrict execution to approved applications. Verify the allowlist is maintained and unauthorized execution attempts are logged.','No application control; any software can be installed and executed.','Basic software restrictions exist but are easily bypassed or not comprehensively enforced.','Application allowlisting enforced on all endpoints with maintained approved list, blocked execution logging, and exception process.','Dynamic application trust with automated reputation analysis, publisher-based rules, and integration with threat intelligence.','Examples: Application control policy configuration, Approved software list, Blocked execution attempt logs, Exception request records.',1,7,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(46,'EPS-08',4,'Are secure configuration baselines documented and enforced for all endpoint types?','Verify documented baselines exist for each endpoint type (Windows, macOS, Linux, servers). Check for automated configuration management and drift detection.','No configuration baselines; each system configured independently.','Informal baselines exist but are not consistently enforced or monitored.','Documented baselines for each platform, deployed via configuration management tools, with regular drift detection and remediation.','Infrastructure-as-code baselines with automated deployment, continuous drift detection, and auto-remediation within minutes.','Examples: Configuration baseline documents per OS type, Configuration management tool deployment evidence, Drift detection scan results, Remediation records.',1,8,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(47,'EPS-09',4,'Are endpoint data backup policies in place to protect against data loss from ransomware or hardware failure?','Check that endpoint data is backed up (cloud sync, local backup, or enterprise backup solution). Verify backup encryption and recovery testing for endpoints.','No endpoint backup; data loss from ransomware or failure would be permanent.','Some backup exists (e.g., OneDrive sync) but not comprehensive or enforced across all endpoints.','Enterprise endpoint backup with cloud sync or scheduled backups, encryption, and tested recovery procedures.','Continuous endpoint backup with immutable copies, ransomware-resistant recovery, and automated verification of backup integrity.','Examples: Endpoint backup policy, Cloud sync configuration (OneDrive/Dropbox), Backup tool deployment records, Recovery test results.',1,9,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(48,'EPS-10',4,'Are virtual desktop infrastructure (VDI) or cloud workspaces secured with appropriate access controls and data protection?','If VDI/DaaS is used, verify secure access controls, data does not leave the virtual environment, session recording is available, and images are hardened.','VDI/cloud workspaces not applicable or deployed without security controls.','VDI is deployed but security configurations are basic and data leakage controls are limited.','VDI secured with MFA, clipboard/print restrictions, hardened images, and monitored access.','Zero-trust VDI with continuous device posture validation, watermarked sessions, and automated image lifecycle management.','Examples: VDI security configuration, Clipboard/print restriction settings, Image hardening checklist, VDI access logs with MFA verification.',1,10,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(49,'NET-01',5,'Is the network segmented to isolate sensitive systems, environments, and data from general-purpose networks?','Verify network segmentation between production/development, PCI CDE, guest networks, and IoT. Check firewall rules between segments and VLAN configurations.','Flat network with no segmentation; all systems on one network.','Basic VLANs exist but segmentation is incomplete and inter-VLAN filtering is minimal.','Network segmented by function and sensitivity with enforced firewall rules between segments and documented architecture.','Microsegmentation with zero-trust policies, dynamic segment assignment based on workload identity, and continuous verification.','Examples: Network architecture diagram showing segments, VLAN configuration, Firewall rules between zones, Segmentation test results.',1,1,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(50,'NET-02',5,'Are firewalls deployed at network boundaries with documented rule sets that are regularly reviewed?','Check firewall placement at internet boundary, between network zones, and for cloud environments. Verify rule reviews occur at least semi-annually with unused rules removed.','No firewalls or default-allow configurations at network boundaries.','Firewalls exist but rules are not documented, reviewed, or optimized.','Next-gen firewalls at all boundaries with documented rules, semi-annual review, unused rule removal, and change management.','Automated rule optimization with continuous traffic analysis, policy-as-code, and real-time threat intelligence integration.','Examples: Firewall rule documentation, Rule review meeting minutes, Unused rule cleanup evidence, Change management records for rule changes.',1,2,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(51,'NET-03',5,'Are intrusion detection and prevention systems (IDS/IPS) deployed to monitor network traffic for threats?','Verify IDS/IPS deployment at key network points with up-to-date signatures. Check for tuning to reduce false positives and integration with SIEM.','No IDS/IPS deployed; network threats go undetected.','IDS is deployed at some points but signatures are outdated and alerts are not actively monitored.','IDS/IPS at all critical points with current signatures, tuned alerts, SIEM integration, and active monitoring.','ML-based network detection with behavioral analysis, automated threat response, and continuous tuning based on threat intelligence.','Examples: IDS/IPS deployment diagram, Signature update schedule, Alert tuning records, SIEM correlation rule evidence.',1,3,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(52,'NET-04',5,'Is secure remote access provided through VPN, ZTNA, or equivalent with strong authentication and encryption?','Verify all remote access uses encrypted tunnels (IPsec, TLS) with MFA. Check for split-tunnel controls, session logging, and geographic restrictions.','Remote access is uncontrolled or uses unencrypted connections.','VPN deployed but may lack MFA enforcement or comprehensive logging.','All remote access through VPN/ZTNA with MFA, strong encryption, session logging, and geographic restrictions.','ZTNA with continuous device posture assessment, adaptive access policies, and per-application micro-tunnels.','Examples: VPN/ZTNA architecture diagram, MFA enforcement configuration, Session logs, Geographic restriction policy.',1,4,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(53,'NET-05',5,'Are wireless networks secured with enterprise-grade authentication (WPA3/WPA2-Enterprise) and monitored for rogue access points?','Check wireless authentication standards, guest network isolation, and rogue AP detection. Verify wireless traffic is encrypted and monitored.','Wireless uses PSK or open networks with no monitoring.','WPA2-Personal used with shared keys; limited rogue AP detection.','WPA3 or WPA2-Enterprise with 802.1X authentication, guest isolation, rogue AP detection, and wireless IDS.','Cloud-managed wireless with automated rogue AP containment, RF analytics, and integration with NAC.','Examples: Wireless authentication configuration, Guest network isolation evidence, Rogue AP detection scan results, 802.1X certificate deployment records.',1,5,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(54,'NET-06',5,'Are DNS security controls implemented to prevent DNS-based attacks and block malicious domains?','Check for DNS filtering, DNSSEC validation, DNS logging, and protection against DNS tunneling and typosquatting.','No DNS security controls; default ISP DNS used.','Basic DNS filtering exists but limited coverage and no advanced threat detection.','DNS security service with malicious domain blocking, DNSSEC validation, DNS logging, and tunneling detection.','Advanced DNS analytics with real-time threat intelligence, DGA detection, and automated response to DNS-based threats.','Examples: DNS security service configuration, Blocked domain reports, DNS query logs, DNSSEC validation evidence.',1,6,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(55,'NET-07',5,'Is network traffic continuously monitored with tools to detect anomalous behavior and potential security incidents?','Look for network monitoring tools (NetFlow, packet capture, NDR) with baseline traffic patterns and anomaly detection capabilities.','No network traffic monitoring beyond basic availability checks.','Basic monitoring exists but without behavioral analysis or anomaly detection.','Continuous network monitoring with traffic analysis, baseline profiling, anomaly detection, and alerting to SOC.','AI-driven network detection and response (NDR) with behavioral analytics, automated investigation, and threat hunting.','Examples: Network monitoring tool dashboard, Anomaly detection alert samples, Traffic baseline documentation, NDR deployment evidence.',1,7,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(56,'NET-08',5,'Are DDoS mitigation controls in place to protect internet-facing services from volumetric and application-layer attacks?','Verify DDoS protection for critical internet-facing services. Check for CDN-based protection, scrubbing centers, or ISP-level mitigation.','No DDoS protection; services vulnerable to volumetric attacks.','Basic ISP-level DDoS protection but no application-layer defense.','Multi-layer DDoS protection covering volumetric and application-layer attacks with automated mitigation and documented runbooks.','Adaptive DDoS protection with real-time traffic profiling, automated scaling, and integrated with incident response.','Examples: DDoS protection service configuration, Mitigation test results, Runbook documentation, Historical attack mitigation reports.',1,8,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(57,'NET-09',5,'Is network access control (NAC) implemented to authenticate devices before granting network access?','Check for NAC solution that validates device identity, health, and compliance before allowing network access. Verify remediation VLAN for non-compliant devices.','No NAC; any device can connect to the network.','Basic port security exists but no comprehensive NAC with health checks.','NAC with 802.1X authentication, device health checks, compliance validation, and remediation VLAN for non-compliant devices.','Adaptive NAC with real-time posture assessment, automated micro-segmentation, and continuous device trust scoring.','Examples: NAC policy configuration, 802.1X deployment evidence, Device health check results, Remediation VLAN configuration.',1,9,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(58,'NET-10',5,'Is the network architecture documented with up-to-date diagrams showing all segments, trust zones, and data flows?','Look for current network diagrams showing physical and logical topology, security zones, data flow paths, and external connections. Verify diagrams are reviewed annually.','No network documentation or diagrams exist.','Outdated network diagrams exist but do not reflect current architecture.','Current network diagrams maintained with all segments, trust zones, data flows, and external connections. Reviewed annually.','Automated network topology mapping with real-time updates, data flow visualization, and integration with asset inventory.','Examples: Network architecture diagram, Data flow diagram, Trust zone documentation, Annual review records, External connection inventory.',1,10,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(59,'NET-11',5,'Are cloud network security controls (VPCs, security groups, NACLs) configured and monitored for cloud environments?','Verify cloud network architecture uses VPCs with appropriate subnets, security groups with least-privilege rules, and NACLs. Check for cloud network flow logging.','Cloud network uses default configurations with no security group management.','Basic security groups exist but rules are overly permissive and not regularly reviewed.','VPCs with segmented subnets, least-privilege security groups, NACLs, flow logging, and regular review of cloud network rules.','Infrastructure-as-code cloud networking with automated compliance checks, drift detection, and policy-as-code for security groups.','Examples: VPC architecture diagram, Security group rules documentation, Flow log analysis, Cloud network compliance scan results.',1,11,'2026-03-09 13:56:19','2026-03-09 13:56:19'),
(60,'APS-01',6,'Is a secure software development lifecycle (SSDLC) implemented with security activities integrated at each phase?','Verify security is integrated into requirements, design, development, testing, and deployment phases. Look for threat modeling, secure coding standards, and security testing.','No security in the SDLC; security testing happens only in production, if at all.','Some security activities exist (e.g., penetration testing before release) but not integrated across all phases.','SSDLC with threat modeling, secure coding standards, SAST/DAST, security review gates, and developer security training.','DevSecOps with automated security in CI/CD pipeline, shift-left security, and continuous security feedback loops.','Examples: SSDLC process document, Threat model examples, Security gate checklist, CI/CD security scan integration evidence.',1,1,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(61,'APS-02',6,'Is static application security testing (SAST) or code review performed on source code before deployment?','Check for SAST tools integrated into CI/CD pipeline or manual code review processes. Verify critical/high findings must be remediated before deployment.','No code review or security analysis of source code.','Manual code reviews occur but no automated SAST scanning.','SAST integrated into CI/CD with automated scanning, required remediation of critical/high findings, and developer feedback.','Real-time SAST with IDE integration, AI-assisted remediation suggestions, and continuous code quality scoring.','Examples: SAST tool scan results, CI/CD pipeline security stage configuration, Code review records, Remediation tracking for findings.',1,2,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(62,'APS-03',6,'Is dynamic application security testing (DAST) performed on running applications to identify runtime vulnerabilities?','Verify DAST scanning of web applications and APIs before release and on a regular schedule. Check for OWASP Top 10 coverage and remediation tracking.','No dynamic testing of applications.','DAST scans run occasionally but not consistently or without remediation tracking.','DAST integrated into release process with OWASP Top 10 coverage, regular scans, and tracked remediation of findings.','Continuous DAST with IAST integration, automated crawling, and real-time vulnerability detection in staging environments.','Examples: DAST scan reports, OWASP Top 10 coverage evidence, Vulnerability remediation records, Scan schedule documentation.',1,3,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(63,'APS-04',6,'Is a web application firewall (WAF) deployed to protect internet-facing applications from common web attacks?','Verify WAF coverage for all internet-facing web applications and APIs. Check for OWASP Top 10 rule sets, custom rules, and regular rule updates.','No WAF protection for web applications.','WAF deployed for some applications but with basic or default rules.','WAF protecting all internet-facing applications with OWASP rule sets, custom rules, regular tuning, and blocking mode.','Adaptive WAF with ML-based threat detection, bot management, API-specific protection, and automated rule generation.','Examples: WAF deployment architecture, Rule set configuration, WAF alert and block logs, Custom rule documentation.',1,4,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(64,'APS-05',6,'Are API security controls implemented including authentication, rate limiting, input validation, and monitoring?','Check API authentication mechanisms (OAuth 2.0, API keys), rate limiting configurations, input validation, and API activity logging.','APIs lack authentication or use basic mechanisms with no rate limiting.','APIs have authentication but may lack rate limiting, input validation, or comprehensive monitoring.','All APIs secured with OAuth 2.0 or equivalent, rate limiting, input validation, schema validation, and activity logging.','API security gateway with behavioral analysis, automated threat detection, API discovery, and runtime protection.','Examples: API authentication configuration, Rate limiting settings, Input validation rules, API activity monitoring dashboard.',1,5,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(65,'APS-06',6,'Is open source and third-party library management in place with vulnerability scanning and license compliance?','Look for software composition analysis (SCA) tools that scan dependencies for known vulnerabilities. Check for vulnerability remediation SLAs and license tracking.','No tracking of open source dependencies or known vulnerabilities.','Some awareness of dependencies but no automated scanning or remediation process.','SCA integrated into CI/CD with automated vulnerability scanning, remediation SLAs, and license compliance tracking.','Real-time dependency monitoring with automated pull requests for updates, SBOM generation, and supply chain security verification.','Examples: SCA scan results, Dependency inventory (SBOM), Vulnerability remediation records, License compliance report.',1,6,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(66,'APS-07',6,'Is a formal change management process in place for all production system changes with approval and rollback procedures?','Verify changes to production require documented requests, risk assessment, approval, testing, and rollback plans. Check change advisory board (CAB) processes.','No change management; production changes made directly without approval.','Informal change process exists but approvals and documentation are inconsistent.','Formal change management with documented requests, risk assessment, CAB approval, testing requirements, and rollback procedures.','Automated change management integrated with CI/CD, automated testing gates, and risk-based approval workflows.','Examples: Change management policy, Change request forms/tickets, CAB meeting minutes, Rollback procedure documentation.',1,7,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(67,'APS-08',6,'Are release management and deployment controls in place to ensure secure and reliable software releases?','Check for deployment pipelines, environment promotion gates, deployment approval processes, and separation of development/staging/production.','No formal release process; code deployed directly to production.','Basic release process exists but environment separation or approval gates are incomplete.','Formal release process with environment separation, approval gates, automated deployment pipelines, and deployment verification.','Fully automated CI/CD with canary/blue-green deployments, automated rollback, and deployment security verification.','Examples: Deployment pipeline configuration, Environment promotion evidence, Release approval records, Deployment verification checklists.',1,8,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(68,'APS-09',6,'Are secure coding standards documented and enforced through training and automated tooling?','Look for documented coding standards that address OWASP Top 10, input validation, output encoding, and language-specific best practices. Check developer training records.','No secure coding standards; developers follow no security guidance.','General coding standards exist but security-specific guidance is limited or not enforced.','Secure coding standards documented for each language, developer security training provided, and enforcement via linting and code review.','AI-assisted secure coding with real-time IDE suggestions, gamified developer training, and continuous coding standard evolution.','Examples: Secure coding standards document, Developer security training completion records, Linting configuration, Code review checklist.',1,9,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(69,'APS-10',6,'Is application vulnerability scanning performed regularly on production applications with tracked remediation?','Verify regular vulnerability scanning of all production applications (web, mobile, API). Check scan frequency, coverage, and remediation SLAs.','No application vulnerability scanning.','Occasional scanning of some applications without consistent remediation.','Regular scanning of all production applications with defined SLAs, tracked remediation, and risk acceptance for exceptions.','Continuous scanning with automated remediation workflows, trend analysis, and integration with development backlog.','Examples: Vulnerability scan reports, Remediation tracking dashboard, Scan coverage report, Risk acceptance documentation for exceptions.',1,10,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(70,'OPS-01',7,'Is a SIEM or equivalent log correlation platform deployed for centralized security monitoring and alerting?','Verify SIEM collects logs from all critical systems, has correlation rules for known attack patterns, and generates actionable alerts monitored 24/7 or during business hours.','No centralized logging or SIEM; logs are siloed across systems.','Basic log aggregation exists but limited correlation rules and monitoring.','SIEM deployed with logs from all critical systems, tuned correlation rules, defined alert escalation, and SOC monitoring.','Advanced SIEM with UEBA, automated investigation playbooks, threat hunting, and ML-driven anomaly detection.','Examples: SIEM architecture diagram, Log source inventory, Correlation rule list, Alert escalation procedures, SOC operations schedule.',1,1,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(71,'OPS-02',7,'Does the organization have a security operations center (SOC) or equivalent monitoring capability for real-time threat detection?','Check for 24/7 or business-hours SOC (internal or MSSP/MDR) with defined procedures, escalation paths, and response capabilities.','No dedicated monitoring; security events go unnoticed until impact.','Part-time monitoring exists but without dedicated staff or consistent coverage.','SOC or MDR service provides continuous monitoring with defined procedures, escalation paths, and regular reporting.','Hybrid SOC with threat hunting, automated triage, and proactive threat intelligence integration.','Examples: SOC operational procedures, Monitoring coverage schedule, MSSP/MDR contract and SLAs, Monthly SOC performance report.',1,2,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(72,'OPS-03',7,'Are security logs retained for a sufficient period and protected from tampering or unauthorized access?','Verify log retention meets regulatory requirements (typically 1-7 years). Check that logs are stored immutably, access-controlled, and integrity is verified.','Logs are not retained or are kept for very short periods with no protection.','Logs retained for some period but without integrity protection or access controls.','Logs retained per regulatory requirements (min 1 year) with integrity controls, access restrictions, and tamper evidence.','Immutable log storage with cryptographic integrity verification, automated retention management, and forensic readiness.','Examples: Log retention policy, Log storage configuration, Access control evidence for log systems, Integrity verification records.',1,3,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(73,'OPS-04',7,'Is a vulnerability management program in place with regular scanning, risk-based prioritization, and defined remediation SLAs?','Verify vulnerability scanning covers all assets (internal, external, cloud). Check for risk-based prioritization (CVSS, EPSS), remediation SLAs, and exception management.','No vulnerability scanning or management program.','Occasional scans performed but no consistent prioritization or remediation tracking.','Regular scanning of all assets with risk-based prioritization, defined SLAs (critical: 7 days, high: 30 days), and tracked remediation.','Continuous vulnerability assessment with CTEM, automated prioritization using EPSS, and integration with patch management.','Examples: Vulnerability management policy, Scan results dashboard, Remediation SLA tracking report, Exception/risk acceptance records.',1,4,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(74,'OPS-05',7,'Is a patch management program implemented for all infrastructure including servers, network devices, and applications?','Beyond endpoint patching (covered in EPS-03), verify patching of servers, network equipment, firmware, databases, and middleware with defined SLAs and compliance tracking.','No infrastructure patch management; systems remain unpatched.','Some patching occurs but without SLAs, full coverage, or compliance tracking for infrastructure.','Comprehensive patch program covering all infrastructure with defined SLAs, automation, testing, and compliance tracking.','Automated risk-based patching with zero-day response, virtual patching capabilities, and near-100% compliance.','Examples: Infrastructure patch policy, Server/network patch compliance reports, Patching automation tool evidence, Patch testing records.',1,5,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(75,'OPS-06',7,'Does the organization leverage threat intelligence to inform security operations and proactive defense?','Check for threat intelligence feeds integrated into SIEM/IPS, membership in information sharing groups (ISACs), and use of threat intelligence in decision-making.','No threat intelligence capability or awareness.','Some open-source threat feeds used but not systematically integrated.','Multiple threat intelligence sources integrated into SIEM/IPS, ISAC membership, and regular threat briefings to security team.','Curated threat intelligence with automated indicator enrichment, proactive threat hunting, and tailored intelligence reports.','Examples: Threat intelligence feed subscriptions, ISAC membership evidence, Threat briefing records, SIEM indicator integration.',1,6,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(76,'OPS-07',7,'Are security tools and technologies inventoried, managed, and regularly evaluated for effectiveness?','Verify an inventory of all security tools exists with owners, licensing, health monitoring, and periodic effectiveness reviews.','No security tool inventory; tools are deployed ad hoc without oversight.','Some tools are tracked but without health monitoring or effectiveness evaluation.','Complete security tool inventory with assigned owners, health monitoring, license tracking, and annual effectiveness reviews.','Security tool rationalization with overlap analysis, automated health dashboards, and continuous ROI measurement.','Examples: Security tool inventory, License tracking spreadsheet, Tool health monitoring dashboard, Effectiveness review reports.',1,7,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(77,'OPS-08',7,'Is a configuration management database (CMDB) or process maintained to track and control system configurations?','Check for configuration management practices that maintain authorized configurations, detect unauthorized changes, and support change management.','No configuration management; system configurations are unknown or uncontrolled.','Some configuration documentation exists but is not consistently maintained.','Configuration management with maintained CMDB, authorized baselines, change detection, and regular audits.','Automated configuration management with infrastructure-as-code, continuous compliance, and auto-remediation of drift.','Examples: CMDB or configuration inventory, Authorized baseline documents, Change detection alerts, Configuration audit reports.',1,8,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(78,'OPS-09',7,'Is capacity management performed to ensure security infrastructure can handle current and projected workloads?','Verify monitoring of security system capacity (SIEM storage, firewall throughput, log capacity). Check for capacity planning aligned with business growth.','No capacity planning for security infrastructure.','Basic monitoring exists but no proactive planning or trend analysis.','Capacity monitoring for all security infrastructure with trend analysis, threshold alerts, and annual capacity planning.','Predictive capacity management with auto-scaling, cloud-bursting capabilities, and ML-based forecasting.','Examples: Capacity monitoring dashboards, Storage utilization trends, Capacity planning documents, Auto-scaling configuration.',1,9,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(79,'OPS-10',7,'Are all systems synchronized to authoritative time sources to ensure accurate log correlation and forensic analysis?','Verify NTP configuration on all systems pointing to authoritative time sources. Check for consistent timezone handling and time synchronization monitoring.','No time synchronization; system clocks vary across infrastructure.','Some systems use NTP but configuration is inconsistent.','All systems synchronized to authoritative NTP sources with consistent timezone handling and synchronization monitoring.','GPS-synchronized time sources with sub-millisecond accuracy, automated drift alerts, and forensic-grade timestamps.','Examples: NTP configuration evidence, Time synchronization monitoring dashboard, Clock variance report, NTP server architecture.',1,10,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(80,'OPS-11',7,'Is a penetration testing program in place with regular testing of applications, infrastructure, and cloud environments?','Verify penetration testing scope, frequency (at least annual), and remediation tracking. Check for qualified testers (internal or third-party) and re-testing of findings.','No penetration testing performed.','Penetration testing is done occasionally but without consistent scope or remediation follow-up.','Annual penetration testing of applications, infrastructure, and cloud by qualified testers with tracked remediation and re-testing.','Continuous penetration testing with red team/purple team exercises, automated breach simulation, and offensive security program.','Examples: Penetration test reports, Tester qualifications/certifications, Remediation tracking records, Re-test verification results.',1,11,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(81,'OPS-12',7,'Does the organization conduct red team, blue team, or purple team exercises to validate defensive capabilities?','Look for exercises that test detection, response, and recovery capabilities against realistic attack scenarios. Check for exercise reports and improvement actions.','No adversary simulation or team exercises conducted.','Occasional tabletop exercises without technical validation of defenses.','Annual red/purple team exercises with realistic scenarios, documented findings, and tracked improvement actions.','Continuous adversary simulation with automated breach and attack simulation (BAS), real-time detection validation, and adaptive defense improvement.','Examples: Red team exercise report, Purple team collaboration records, Detection gap analysis, Improvement action tracking.',1,12,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(82,'INC-01',8,'Does the organization have a documented incident response plan that is approved by management and regularly tested?','Look for a comprehensive IRP covering detection, containment, eradication, recovery, and lessons learned. Verify management approval and regular testing (tabletop/functional).','No incident response plan exists.','Basic IRP exists but has not been tested or is outdated.','Comprehensive IRP approved by management, tested at least annually via tabletop exercises, with documented procedures for all phases.','IRP is continuously updated based on threat intelligence, includes automated playbooks, and is tested through realistic simulations.','Examples: Incident response plan document, Management approval signature, Tabletop exercise records, After-action reports.',1,1,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(83,'INC-02',8,'Is an incident classification and severity framework defined to prioritize response activities?','Check for severity levels (Critical/High/Medium/Low) with clear criteria, response time SLAs, and escalation procedures for each severity.','No incident classification; all incidents treated the same way.','Informal severity levels exist but criteria are not documented or consistently applied.','Defined severity framework with clear criteria, response SLAs, escalation paths, and documented for all incident types.','Dynamic severity assessment with automated classification based on asset criticality, data sensitivity, and threat intelligence.','Examples: Incident severity matrix, Response SLA table, Escalation procedures, Classification decision tree.',1,2,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(84,'INC-03',8,'Are automated detection capabilities in place to identify security incidents in real-time or near-real-time?','Verify detection controls including SIEM alerts, EDR detections, network anomaly detection, and user behavior analytics that enable rapid incident identification.','Incident detection relies on manual observation or user reports.','Some automated detection exists but coverage gaps leave many incident types undetected.','Comprehensive automated detection with SIEM, EDR, network monitoring, and email security covering all major attack vectors.','ML-driven detection with UEBA, automated alert triage, and mean time to detect (MTTD) measured and continuously improving.','Examples: SIEM detection rule inventory, EDR alert dashboard, Detection coverage matrix, MTTD metrics report.',1,3,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(85,'INC-04',8,'Are containment procedures defined to rapidly isolate affected systems and prevent incident spread?','Check for documented containment strategies (network isolation, account disabling, host quarantine) with decision criteria and authorization procedures.','No containment procedures; incidents spread unchecked until resolved.','Ad hoc containment occurs but without documented procedures or pre-authorized actions.','Documented containment procedures with pre-authorized actions, network isolation capabilities, and rapid response tools.','Automated containment with SOAR playbooks, one-click isolation, and pre-approved containment actions for common scenarios.','Examples: Containment procedure document, Network isolation capability evidence, Pre-authorized action list, Containment timeline records.',1,4,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(86,'INC-05',8,'Does the organization have digital forensic investigation capabilities or access to forensic services?','Verify forensic capability (internal or contracted) with trained personnel, tools, and evidence preservation procedures. Check chain of custody documentation.','No forensic capability; investigations cannot be conducted.','Basic forensic tools available but staff are not trained and procedures are informal.','Forensic capability with trained personnel, documented procedures, evidence preservation, and chain of custody management.','Advanced forensic lab with automated evidence collection, memory and disk forensics, and integration with legal/compliance teams.','Examples: Forensic capability assessment, Trained personnel certifications, Evidence handling procedures, Chain of custody forms.',1,5,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(87,'INC-06',8,'Is a communication plan defined for internal and external notifications during security incidents?','Check for documented communication templates, stakeholder contact lists, media handling procedures, and regulatory notification requirements with timelines.','No communication plan; notifications are ad hoc during incidents.','Basic contact list exists but communication templates and procedures are not defined.','Comprehensive communication plan with templates, stakeholder lists, media procedures, and regulatory notification timelines.','Automated communication workflows with pre-approved templates, real-time status pages, and integrated stakeholder management.','Examples: Communication plan document, Notification templates, Stakeholder contact list, Regulatory notification timeline chart.',1,6,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(88,'INC-07',8,'Is a regulatory breach notification process in place to comply with data breach reporting requirements?','Verify documented process for determining breach notification requirements per applicable regulations (GDPR 72hr, state laws, HIPAA, etc.) with legal review.','No breach notification process; regulatory requirements unknown.','Awareness of some notification requirements but no formal process or templates.','Documented breach notification process with regulatory requirement matrix, notification templates, legal review, and timeline tracking.','Automated breach assessment with regulatory requirement engine, pre-approved notifications, and integrated regulatory tracking.','Examples: Breach notification procedure, Regulatory requirement matrix, Notification templates by jurisdiction, Legal review records.',1,7,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(89,'INC-08',8,'Are post-incident reviews (lessons learned) conducted after significant incidents with improvement actions tracked?','Look for documented post-incident reviews within 2 weeks of incident closure. Check for root cause analysis, improvement recommendations, and tracked implementation.','No post-incident reviews conducted; same mistakes repeated.','Informal reviews occur for major incidents but findings are not tracked.','Formal post-incident reviews for all significant incidents with root cause analysis, documented improvements, and tracked actions.','Systematic lessons-learned program with trend analysis, process improvement integration, and metrics on recurring incident reduction.','Examples: Post-incident review reports, Root cause analysis documents, Improvement action tracker, Trend analysis showing reduction in recurring incidents.',1,8,'2026-03-09 13:56:20','2026-03-09 13:56:20'),
(90,'INC-09',8,'Is an incident response team established with clearly defined roles, responsibilities, and contact information?','Check for named incident response team members with specific roles (incident commander, technical lead, communications, legal). Verify on-call procedures.','No designated incident response team; response is ad hoc.','Some team members are informally assigned but roles are not clearly defined or documented.','Formal IRT with named members, defined roles (commander, technical, communications, legal), on-call rotation, and contact list.','Tiered response team with specialized roles, cross-functional support, regular training, and automated escalation workflows.','Examples: IRT roster with roles, On-call schedule, Contact information card, Role-specific procedure documents.',1,9,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(91,'INC-10',8,'Are incident response exercises (tabletop, functional, full-scale) conducted regularly to validate preparedness?','Verify IR exercises are conducted at least annually with realistic scenarios. Check for participant attendance, exercise outcomes, and improvement actions.','No IR exercises or drills conducted.','Occasional tabletop exercises without structured scenarios or follow-up actions.','Annual tabletop and functional exercises with realistic scenarios, cross-functional participation, and documented improvement actions.','Quarterly exercises of varying types (tabletop, functional, full-scale) with red team integration and continuous improvement program.','Examples: Exercise schedule, Scenario documents, Participant attendance records, Exercise after-action reports, Improvement action tracker.',1,10,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(92,'SCM-01',9,'Does the organization have a formal vendor risk assessment process for evaluating third-party security posture?','Look for a documented process for assessing vendor security before engagement and periodically. Check assessment questionnaires, risk ratings, and due diligence records.','No vendor risk assessment process; vendors are onboarded without security evaluation.','Informal assessment for some vendors but not standardized or comprehensive.','Formal vendor risk assessment with standardized questionnaires, risk tiering, documented due diligence, and approval workflow.','Automated vendor assessment with continuous monitoring, real-time risk scoring, and integration with procurement.','Examples: Vendor assessment questionnaire, Risk rating methodology, Due diligence records, Vendor risk register.',1,1,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(93,'SCM-02',9,'Are security requirements included in vendor contracts, including data protection obligations and right to audit?','Verify contracts include security requirements, data handling obligations, breach notification, SLAs, and right to audit clauses.','No security clauses in vendor contracts.','Some contracts have basic security language but requirements are not standardized.','Standard security clauses in all vendor contracts including data protection, breach notification, SLAs, and right to audit.','Dynamic contract requirements based on risk tier with automated compliance tracking and penalty enforcement.','Examples: Standard security contract clauses, Data processing agreements, Right to audit provisions, Breach notification requirements.',1,2,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(94,'SCM-03',9,'Is ongoing vendor security monitoring performed beyond initial assessment, including periodic reassessments?','Check for periodic vendor reassessment schedules, continuous monitoring services, and processes for tracking changes in vendor security posture.','No ongoing monitoring; vendors are assessed once and never re-evaluated.','Annual reassessments for some critical vendors but no continuous monitoring.','Risk-tiered reassessment schedule (annual for high-risk, biennial for others) with continuous monitoring services for critical vendors.','Real-time vendor risk monitoring with automated security scoring, threat intelligence integration, and instant risk alerts.','Examples: Vendor reassessment schedule, Continuous monitoring dashboard, Security scorecard trends, Vendor risk change alerts.',1,3,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(95,'SCM-04',9,'Are supply chain security controls implemented to protect against compromise through vendors and partners?','Look for controls addressing software supply chain (SBOM, code signing), hardware supply chain integrity, and vendor access restrictions.','No supply chain security controls; vendor-provided software/hardware is trusted implicitly.','Basic controls exist for some supply chain risks but not comprehensive.','Supply chain controls including SBOM review, code signing verification, hardware integrity checks, and vendor access restrictions.','Comprehensive supply chain risk program with automated SBOM analysis, zero-trust vendor access, and continuous integrity monitoring.','Examples: SBOM for critical software, Code signing verification records, Hardware integrity procedures, Vendor access restriction policy.',1,4,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(96,'SCM-05',9,'Is sub-processor and fourth-party risk management addressed in the vendor management program?','Verify that critical vendors disclose sub-processors and that fourth-party risks are assessed. Check contract clauses requiring sub-processor notification.','No visibility into vendor sub-processors or fourth-party risks.','Some awareness of key sub-processors but no formal management process.','Sub-processor disclosure required in contracts, fourth-party risk assessed for critical vendors, and change notification procedures.','Automated sub-processor tracking with risk chain analysis and dynamic fourth-party risk scoring.','Examples: Sub-processor disclosure contract clause, Sub-processor inventory, Fourth-party risk assessment records, Change notification evidence.',1,5,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(97,'SCM-06',9,'Are vendor access controls implemented to restrict third-party access to only necessary systems and data?','Check that vendor access is limited by scope, time-bound, monitored, and uses dedicated accounts. Verify MFA and privileged access controls for vendor connections.','Vendors have unrestricted access to internal systems with shared credentials.','Some vendor access restrictions exist but are not consistently enforced.','Vendor access restricted to required systems, time-bound, using dedicated accounts with MFA, and monitored/logged.','Just-in-time vendor access with session recording, automated deprovisioning, and risk-based access controls.','Examples: Vendor access request/approval records, Dedicated vendor account list, MFA enforcement evidence, Vendor session logs.',1,6,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(98,'SCM-07',9,'Are SLAs monitored for vendor compliance with security requirements and service delivery standards?','Verify SLA monitoring processes, regular reporting from vendors, and consequences for SLA violations. Check vendor performance scorecards.','No SLA monitoring for vendor security or service delivery.','SLAs exist but monitoring is informal and consequences are not enforced.','SLA monitoring with regular vendor reporting, performance scorecards, documented violations, and escalation procedures.','Automated SLA monitoring with real-time dashboards, automated alerts, and contractual penalty tracking.','Examples: SLA monitoring reports, Vendor performance scorecards, SLA violation records, Vendor review meeting minutes.',1,7,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(99,'SCM-08',9,'Is fourth-party (sub-vendor) risk management addressed to understand dependencies in the supply chain?','Check for visibility into critical vendor dependencies, concentration risk analysis, and requirements for vendors to manage their own third-party risks.','No awareness of fourth-party dependencies or concentration risks.','Some understanding of major vendor dependencies but no formal analysis.','Fourth-party risk mapping for critical vendors, concentration risk analysis, and vendor requirements to manage their third parties.','Automated dependency mapping with multi-tier risk visualization and real-time concentration risk monitoring.','Examples: Fourth-party dependency map, Concentration risk analysis, Vendor third-party management requirements, Supply chain risk report.',1,8,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(100,'SCM-09',9,'Are vendor incident notification requirements defined with specific timeframes and communication channels?','Verify contracts require vendors to notify of security incidents within defined timeframes (e.g., 24-72 hours). Check incident communication procedures and escalation.','No vendor incident notification requirements in place.','Some contracts mention incident notification but timeframes and channels are vague.','Defined incident notification requirements in all vendor contracts with specific timeframes, channels, and escalation procedures.','Automated vendor incident intake with integration into organizational incident response and automated impact assessment.','Examples: Contract incident notification clauses, Vendor incident communication plan, Notification timeline requirements, Historical vendor incident records.',1,9,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(101,'SCM-10',9,'Are cloud service provider security controls assessed including certifications, shared responsibility, and configuration?','Verify CSP security assessment including SOC 2 reports, shared responsibility model documentation, and cloud configuration security reviews.','No assessment of cloud service provider security or shared responsibility.','CSP certifications reviewed but shared responsibility and configuration security not addressed.','CSP security assessed with SOC 2 review, shared responsibility documented, configuration security reviewed, and regular reassessment.','Continuous CSP security monitoring with automated configuration compliance, multi-cloud visibility, and shared responsibility enforcement.','Examples: CSP SOC 2 report review records, Shared responsibility matrix, Cloud configuration review results, CSP reassessment schedule.',1,10,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(102,'PHY-01',10,'Are physical access controls (badges, biometrics, PINs) implemented to restrict entry to facilities and sensitive areas?','Verify physical access control systems at facility entry points and for sensitive areas (server rooms, wiring closets). Check access logs and review processes.','No physical access controls; facilities are open or use basic locks only.','Badge access at main entry but sensitive areas may not have additional controls.','Multi-layer physical access with badge/biometric for building and additional controls for sensitive areas, access logs, and regular reviews.','Integrated physical-logical access with real-time monitoring, anti-tailgating technology, and behavioral analytics.','Examples: Access control system configuration, Badge reader placement map, Access log reports, Sensitive area access review records.',1,1,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(103,'PHY-02',10,'Are data center and server room security controls implemented including restricted access, monitoring, and environmental protection?','Check data center access restrictions, visitor procedures, temperature/humidity monitoring, fire suppression, and water detection systems.','Server rooms have no dedicated security controls or environmental monitoring.','Basic lock and key for server rooms but limited environmental monitoring.','Restricted access with logging, environmental monitoring (temperature, humidity, water), fire suppression, and UPS.','Fully monitored data center with redundant environmental controls, automated alerting, and predictive maintenance.','Examples: Server room access log, Environmental monitoring dashboard, Fire suppression system maintenance records, UPS test results.',1,2,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(104,'PHY-03',10,'Are environmental controls (HVAC, fire suppression, water detection) maintained and tested for facility protection?','Verify environmental controls are professionally maintained, regularly tested, and documented. Check for redundancy in critical systems.','No environmental controls or maintenance records.','Basic HVAC and fire systems exist but maintenance is irregular and testing undocumented.','Professional maintenance schedule, regular testing of fire suppression and HVAC, documented results, and redundancy for critical systems.','Smart building management with predictive analytics, automated alerting, and integrated environmental monitoring.','Examples: HVAC maintenance schedule and records, Fire suppression test certificates, Water detection sensor placement map, Environmental alert logs.',1,3,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(105,'PHY-04',10,'Is a visitor management process in place to control and monitor non-employee access to facilities?','Check for visitor registration, identification verification, escort requirements for sensitive areas, and visitor log retention.','No visitor management; visitors can enter and move freely.','Basic visitor sign-in exists but no escort requirements or consistent enforcement.','Formal visitor management with registration, ID verification, badges, escort requirements for sensitive areas, and retained logs.','Digital visitor management with pre-registration, automated badge printing, real-time tracking, and integration with access control.','Examples: Visitor management policy, Visitor sign-in log, Visitor badge samples, Escort requirement documentation.',1,4,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(106,'PHY-05',10,'Is video surveillance (CCTV) deployed at facility entry/exit points and sensitive areas with appropriate retention?','Verify camera placement at key locations, recording retention period, access controls to footage, and regular system maintenance.','No video surveillance in place.','Some cameras at main entrance but coverage is incomplete and retention may be short.','CCTV at all entry/exit points and sensitive areas with minimum 30-day retention, access-controlled footage, and regular maintenance.','AI-driven video analytics with anomaly detection, integration with access control, and automated incident flagging.','Examples: Camera placement map, Recording retention policy, Footage access log, Camera maintenance records.',1,5,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(107,'PHY-06',10,'Is secure disposal of equipment and media performed to prevent data recovery from decommissioned assets?','Look for documented disposal procedures including data wiping, degaussing, or physical destruction. Check for certificates of destruction and chain of custody.','Equipment disposed without data sanitization; data recovery risk exists.','Some disposal procedures exist but are inconsistently applied and undocumented.','Documented disposal procedures with approved methods (NIST 800-88), certificates of destruction, and chain of custody tracking.','Automated asset lifecycle management with integrated disposal tracking, verified destruction, and comprehensive audit trail.','Examples: Data sanitization procedure, Certificates of destruction, Chain of custody records, NIST 800-88 compliance evidence.',1,6,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(108,'PHY-07',10,'Is a clean desk and clear screen policy implemented and enforced to protect sensitive information?','Check for documented clean desk policy requiring sensitive documents to be secured, screens locked when unattended, and whiteboards cleared.','No clean desk policy; sensitive documents left unattended openly.','Policy exists but enforcement is minimal and compliance not monitored.','Clean desk policy enforced with regular spot checks, automatic screen lock, and secure storage available for all staff.','Smart office integration with automated screen lock, document presence detection, and compliance analytics.','Examples: Clean desk policy, Spot check results, Auto screen lock configuration, Secure storage availability evidence.',1,7,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(109,'PHY-08',10,'Are power and network cabling secured to prevent unauthorized access, interference, or damage?','Verify cabling is secured in locked conduits or cable trays, labeled, documented, and protected from physical damage or tampering.','Cables are unsecured, unlabeled, and exposed to potential tampering.','Some cable management exists but inconsistently applied.','Cables secured in conduits/trays, labeled, documented, and protected from unauthorized access and environmental damage.','Intelligent cabling management with fiber optic security monitoring, automated topology mapping, and tamper detection.','Examples: Cable management photos, Cabling documentation, Conduit/tray installation evidence, Network port security configuration.',1,8,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(110,'HRS-01',11,'Are background verification and screening checks performed on all employees before granting access to information systems?','Verify background checks are conducted during hiring, including criminal history, employment verification, and reference checks appropriate to the role.','No background checks performed on new hires.','Background checks for some roles but not consistently applied to all positions.','Background checks required for all employees before system access, with enhanced checks for privileged roles, documented and retained.','Continuous background monitoring with risk-based screening levels and automated re-screening for role changes.','Examples: Background check policy, Completed check records, Enhanced screening criteria for privileged roles, Retention records.',1,1,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(111,'HRS-02',11,'Is a security awareness training program implemented for all employees with regular updates and phishing simulations?','Check for mandatory annual training, phishing simulation program, training completion tracking, and consequences for repeated failures.','No security awareness training provided to employees.','Basic annual training exists but without phishing simulations or completion enforcement.','Mandatory annual training with monthly phishing simulations, tracked completion rates, and remedial training for failures.','Adaptive training with role-based content, gamification, continuous micro-learning, and behavioral risk scoring per employee.','Examples: Training program overview, Completion rate reports, Phishing simulation results, Remedial training records.',1,2,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(112,'HRS-03',11,'Is role-specific security training provided to staff in specialized positions such as developers, administrators, and incident responders?','Verify that staff in security-critical roles receive specialized training beyond general awareness (secure coding, system administration security, IR procedures).','No role-specific security training; all staff receive the same generic training.','Some specialized training exists but coverage is incomplete.','Role-specific training programs for developers, admins, and IR staff with defined curricula, certification support, and tracked completion.','Continuous skill development with hands-on labs, CTF exercises, industry certifications funded, and competency assessments.','Examples: Role-specific training curriculum, Training completion records by role, Certification tracking, Hands-on lab participation records.',1,3,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(113,'HRS-04',11,'Are security procedures integrated into the employee onboarding process including acceptable use acknowledgment?','Check that new hire onboarding includes security training, acceptable use policy acknowledgment, NDA signing, and access provisioning per role.','No security steps in the onboarding process.','Some security onboarding exists but it is informal and not consistently followed.','Formal security onboarding checklist with training, AUP acknowledgment, NDA, role-based access provisioning, and HR-IT coordination.','Automated onboarding workflow with integrated security training, automated access provisioning, and completion verification.','Examples: Onboarding security checklist, AUP acknowledgment forms, NDA records, Access provisioning request/completion evidence.',1,4,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(114,'HRS-05',11,'Are security procedures integrated into the employee offboarding process to ensure timely access revocation?','Verify offboarding checklist includes access revocation within 24 hours, device return, data transfer procedures, and exit interview covering security obligations.','No offboarding security procedures; terminated users retain access for extended periods.','Basic offboarding exists but access revocation may be delayed and inconsistent.','Formal offboarding with access revoked within 24 hours, devices returned, data procedures followed, and verified completion.','Automated offboarding triggered by HR system with instant access revocation, automated device wipe, and compliance verification.','Examples: Offboarding checklist, Access revocation timeline evidence, Device return log, Exit interview records.',1,5,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(115,'HRS-06',11,'Is an acceptable use policy defined and acknowledged by all employees covering use of organizational IT resources?','Look for AUP covering email, internet, social media, personal devices, and company assets. Verify annual acknowledgment by all employees.','No acceptable use policy exists.','AUP exists but may be outdated or not consistently acknowledged by all employees.','Comprehensive AUP covering all IT resources, annually acknowledged by all employees, with documented enforcement procedures.','Dynamic AUP with automated acknowledgment tracking, policy violation detection, and regular updates based on emerging risks.','Examples: Acceptable use policy document, Employee acknowledgment records, Enforcement procedure documentation, Annual distribution evidence.',1,6,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(116,'HRS-07',11,'Are non-disclosure and confidentiality agreements signed by all employees, contractors, and third parties with access to sensitive information?','Verify NDAs are signed during onboarding and maintained on file. Check that NDAs cover post-employment obligations and are updated for role changes.','No NDAs or confidentiality agreements in place.','NDAs used for some roles but not consistently required or maintained.','NDAs required for all employees, contractors, and third parties with access to sensitive data, signed and retained on file.','Automated NDA management with role-based terms, electronic signatures, renewal tracking, and compliance monitoring.','Examples: NDA template, Signed NDA records, Contractor NDA evidence, NDA tracking log.',1,7,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(117,'HRS-08',11,'Is a disciplinary process in place for addressing security policy violations and non-compliance?','Check for documented disciplinary procedures for security violations, clear escalation path, and evidence of consistent enforcement.','No disciplinary process for security violations.','Informal consequences exist but are not documented or consistently applied.','Formal disciplinary process documented in HR policy with graduated consequences, consistent enforcement, and documented outcomes.','Integrated violation tracking with automated incident-to-HR workflow, trend analysis, and proactive intervention for at-risk employees.','Examples: Disciplinary policy for security violations, Graduated consequence matrix, Violation tracking records, HR investigation procedures.',1,8,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(118,'HRS-09',11,'Does the organization promote a positive security culture where employees feel empowered to report security concerns?','Look for anonymous reporting mechanisms, security champion programs, positive reinforcement for reporting, and evidence of a no-blame culture for good-faith reports.','No security culture initiatives; employees fear reporting security concerns.','Some awareness of security culture but no formal programs or anonymous reporting.','Active security culture program with anonymous reporting, security champions, recognition programs, and no-blame reporting policy.','Data-driven security culture measurement with regular surveys, behavioral analytics, and continuous culture improvement program.','Examples: Anonymous reporting mechanism, Security champion program documentation, Recognition program records, Security culture survey results.',1,9,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(119,'HRS-10',11,'Are contractor and temporary staff subject to the same security requirements as permanent employees?','Verify that contractors undergo background checks, receive security training, sign NDAs, and have access limited to their assignment scope with timely offboarding.','Contractors have unrestricted access with no security requirements.','Some requirements for contractors but enforcement varies and gaps exist.','Contractors subject to background checks, security training, NDAs, time-bound access, and formal offboarding matching employee standards.','Automated contractor lifecycle management with integrated security requirements, continuous compliance, and automated access expiration.','Examples: Contractor security requirements policy, Contractor background check records, Training completion evidence, Time-bound access configuration.',1,10,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(120,'BCP-01',12,'Has the organization developed and maintained a business continuity plan (BCP) covering critical business functions?','Look for a documented BCP that identifies critical functions, dependencies, and procedures for maintaining operations during disruptions. Verify management approval.','No business continuity plan exists.','Basic BCP exists but may be incomplete, outdated, or not tested.','Comprehensive BCP covering all critical functions, approved by management, with defined procedures and annual review.','Dynamic BCP integrated with operational resilience framework, automated dependency mapping, and continuous improvement cycle.','Examples: BCP document, Management approval records, Critical function inventory, Annual review records.',1,1,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(121,'BCP-02',12,'Is a disaster recovery plan (DRP) documented for IT systems with defined recovery procedures and responsibilities?','Verify DRP covers critical IT systems with step-by-step recovery procedures, responsibilities, and communication plans. Check for regular updates.','No disaster recovery plan exists for IT systems.','Basic DRP exists but lacks detail, assigned responsibilities, or regular updates.','Comprehensive DRP with detailed procedures for all critical systems, assigned responsibilities, and tested annually.','Automated DR with infrastructure-as-code recovery, runbook automation, and orchestrated failover/failback procedures.','Examples: DR plan document, System recovery procedures, Responsibility matrix, DR test results.',1,2,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(122,'BCP-03',12,'Has a business impact analysis (BIA) been conducted to identify critical processes, dependencies, and acceptable downtime?','Check for documented BIA identifying critical business processes, maximum tolerable downtime, dependencies, and resource requirements for recovery.','No BIA conducted; critical process priorities are unknown.','Informal understanding of critical processes but no documented BIA.','Formal BIA completed identifying all critical processes, MAO/MTPD values, dependencies, and resource requirements. Updated annually.','Dynamic BIA with automated dependency mapping, real-time criticality assessment, and integrated with risk management.','Examples: BIA report, Critical process ranking, Dependency mapping, MAO/MTPD documentation, Annual update records.',1,3,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(123,'BCP-04',12,'Are recovery time objectives (RTO) and recovery point objectives (RPO) defined and tested for critical systems?','Verify RTO/RPO are defined for each critical system based on BIA results. Check that DR tests validate these objectives are achievable.','No RTO/RPO defined for any systems.','RTO/RPO defined for some systems but not tested or validated.','RTO/RPO defined for all critical systems based on BIA, validated through regular DR testing, and gaps remediated.','Automated RTO/RPO validation with continuous DR readiness testing and dynamic adjustment based on system changes.','Examples: RTO/RPO table for critical systems, DR test results showing recovery times, Gap remediation records, BIA alignment documentation.',1,4,'2026-03-09 13:56:21','2026-03-09 13:56:21'),
(124,'BCP-05',12,'Is a comprehensive backup strategy implemented with regular testing and off-site or cloud storage?','Verify backup policies cover all critical data, backups are encrypted, stored off-site/cloud, and restoration is tested regularly. Check for immutable backup capabilities.','No backup strategy or backups are unreliable and untested.','Backups exist but testing is infrequent and off-site storage may be incomplete.','Comprehensive backup strategy with 3-2-1 rule, encryption, off-site/cloud storage, quarterly restore testing, and documented procedures.','Immutable backups with continuous validation, automated integrity checking, instant recovery capability, and ransomware protection.','Examples: Backup policy and schedule, 3-2-1 compliance evidence, Restore test results, Off-site storage configuration, Encryption evidence.',1,5,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(125,'BCP-06',12,'Is a crisis communication plan in place for notifying stakeholders, customers, and regulators during major disruptions?','Check for documented communication plan with stakeholder contact lists, message templates, communication channels, and spokesperson assignments.','No crisis communication plan; stakeholders are notified ad hoc.','Basic contact lists exist but communication templates and procedures are not defined.','Crisis communication plan with templates, stakeholder lists, designated spokespersons, and defined channels for different audiences.','Automated crisis communication with multi-channel distribution, real-time status pages, and pre-approved message templates.','Examples: Crisis communication plan, Stakeholder contact list, Message templates, Spokesperson assignments, Status page configuration.',1,6,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(126,'BCP-07',12,'Are alternate processing sites or cloud failover capabilities available for critical systems?','Verify alternate recovery sites exist (hot/warm/cold site, cloud DR region, or DRaaS). Check failover testing records and connectivity verification.','No alternate processing capability; single point of failure for all systems.','Some cloud redundancy exists but failover is not tested or documented.','Alternate processing sites or cloud DR configured for critical systems with tested failover and documented procedures.','Active-active multi-region deployment with automated failover, zero-RPO capabilities, and continuous failover testing.','Examples: DR site documentation, Cloud DR region configuration, Failover test results, Connectivity verification records.',1,7,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(127,'BCP-08',12,'Are BCP and DR plans tested at least annually through tabletop exercises, functional tests, or full-scale drills?','Verify regular testing of BCP/DR plans with different exercise types. Check participant attendance, test results, and improvement actions from findings.','No BCP/DR testing performed.','Testing occurs sporadically without structured exercises or follow-up.','Annual BCP/DR testing with varied exercise types, documented results, identified gaps, and tracked improvement actions.','Quarterly testing with automated DR validation, chaos engineering, and continuous improvement program.','Examples: Test schedule and calendar, Exercise reports, Participant attendance records, Gap identification and remediation tracking.',1,8,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(128,'BCP-09',12,'Does the organization have plans to address pandemic, workforce disruption, or remote work continuity scenarios?','Check for plans addressing extended remote work, key person dependencies, workforce health events, and ability to maintain operations with reduced staffing.','No plans for workforce disruption scenarios.','Lessons learned from COVID-19 exist but not formalized into plans.','Documented plans for remote work continuity, key person dependencies, succession planning, and workforce disruption scenarios.','Resilient workforce model with distributed operations, cross-trained staff, automated processes, and regular disruption simulations.','Examples: Remote work continuity plan, Key person dependency analysis, Succession plan, Cross-training records.',1,9,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(129,'BCP-10',12,'Are supply chain and vendor continuity risks addressed in the business continuity program?','Verify BCP addresses critical vendor dependencies, alternate vendor identification, and vendor BCP/DR validation. Check for vendor continuity in contracts.','No consideration of supply chain continuity in BCP.','Key vendor dependencies are known but no alternate vendor plans exist.','Critical vendor dependencies mapped, alternate vendors identified, vendor BCP/DR validated, and continuity clauses in contracts.','Automated vendor continuity monitoring with real-time risk alerts, pre-qualified alternate vendors, and integrated supply chain resilience.','Examples: Critical vendor dependency map, Alternate vendor list, Vendor BCP validation records, Continuity contract clauses.',1,10,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(130,'CRY-01',13,'Has the organization defined a cryptographic policy specifying approved algorithms, key lengths, and use cases?','Look for a documented crypto policy specifying approved algorithms (AES-256, RSA-2048+, SHA-256+), prohibited algorithms (DES, MD5, SHA-1), and use case requirements.','No cryptographic policy; encryption decisions are ad hoc.','Some encryption standards exist but not comprehensively documented or enforced.','Documented cryptographic policy with approved algorithms, key lengths, prohibited ciphers, and use-case requirements.','Crypto-agile policy with automated algorithm inventory, quantum-readiness planning, and continuous cipher suite monitoring.','Examples: Cryptographic policy document, Approved algorithm list, Prohibited cipher list, Use-case requirements matrix.',1,1,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(131,'CRY-02',13,'Are cryptographic keys generated using approved methods with sufficient entropy and randomness?','Verify key generation uses approved random number generators, appropriate key lengths, and secure generation environments. Check for FIPS 140-2/3 compliance where required.','No standards for key generation; keys may be weak or predictable.','Keys are generated with standard tools but without verified entropy or documented procedures.','Key generation follows documented procedures using CSPRNG, appropriate key lengths, and FIPS-validated modules where required.','Hardware-based key generation with HSMs, quantum-resistant algorithms being evaluated, and automated key strength verification.','Examples: Key generation procedure, FIPS 140-2/3 certificate, HSM configuration, Key strength verification records.',1,2,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(132,'CRY-03',13,'Are cryptographic keys stored securely with appropriate access controls and protection from unauthorized access?','Check that keys are stored in key management systems, HSMs, or secure vaults. Verify access to key material is restricted and logged.','Keys stored in plain text in files, code, or databases without protection.','Keys stored with some protection but may be accessible to too many people.','Keys stored in dedicated KMS or HSM with strict access controls, audit logging, and separation of duties.','Hardware-backed key storage with FIPS 140-3 Level 3 HSMs, zero-knowledge key management, and automated access auditing.','Examples: KMS/HSM configuration, Key access control list, Key access audit logs, Separation of duties evidence.',1,3,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(133,'CRY-04',13,'Is a key rotation and retirement policy implemented with defined schedules for all cryptographic keys?','Verify key rotation schedules exist for all key types (encryption, signing, authentication). Check that retired keys are securely destroyed and rotation is documented.','No key rotation; keys are used indefinitely without change.','Some key rotation occurs but schedules are informal and inconsistent.','Defined rotation schedules for all key types, automated rotation where possible, and documented key retirement with secure destruction.','Fully automated key lifecycle management with zero-downtime rotation, compliance monitoring, and crypto-period enforcement.','Examples: Key rotation schedule, Automated rotation configuration, Key retirement records, Secure destruction evidence.',1,4,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(134,'CRY-05',13,'Is a PKI or certificate management system in place to manage digital certificates throughout their lifecycle?','Check for certificate inventory, automated renewal, expiration monitoring, and revocation procedures. Verify certificates use appropriate algorithms and key lengths.','No certificate management; certificates expire unexpectedly causing outages.','Basic certificate tracking exists but relies on manual monitoring and renewal.','Certificate management system with automated discovery, expiration monitoring, renewal workflows, and revocation procedures.','Fully automated certificate lifecycle with auto-renewal, short-lived certificates, and integration with DevOps pipelines.','Examples: Certificate inventory, Expiration monitoring dashboard, Automated renewal configuration, Revocation procedure documentation.',1,5,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(135,'CRY-06',13,'Are TLS/SSL configurations maintained at current standards with deprecated protocols and cipher suites disabled?','Verify TLS 1.2+ is enforced, TLS 1.0/1.1 disabled, weak cipher suites removed, and HSTS enabled. Check for regular TLS configuration scanning.','Deprecated TLS versions and weak ciphers in use; no configuration management.','TLS 1.2 used for some services but legacy configurations may persist.','TLS 1.2+ enforced everywhere, weak ciphers disabled, HSTS enabled, and regular configuration scanning with remediation.','TLS 1.3 preferred, automated cipher suite management, continuous scanning, and forward secrecy enforced on all endpoints.','Examples: TLS configuration scan results (SSL Labs), Cipher suite configuration, HSTS header evidence, Deprecated protocol disable records.',1,6,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(136,'CRY-07',13,'Are only approved and current encryption algorithms used, with a process to identify and replace deprecated algorithms?','Check for an algorithm inventory across all systems and applications. Verify deprecated algorithms (DES, 3DES, MD5, SHA-1 for signing) are identified and remediated.','No awareness of which algorithms are in use; deprecated algorithms likely present.','Some deprecated algorithms identified but remediation is incomplete.','Algorithm inventory maintained, deprecated algorithms identified with remediation plans, and new deployments use only approved algorithms.','Automated cryptographic discovery with continuous algorithm monitoring, quantum-readiness assessment, and crypto-agility planning.','Examples: Cryptographic algorithm inventory, Deprecated algorithm findings, Remediation plan and progress, Approved algorithm enforcement policy.',1,7,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(137,'CRY-08',13,'Are hardware security modules (HSMs) or equivalent used for protecting high-value cryptographic keys?','Where applicable, verify HSMs are used for root CA keys, payment processing, and other high-value key operations. Check FIPS certification and access controls.','No HSMs; high-value keys stored in software without hardware protection.','HSMs considered but not implemented; high-value keys in software-based vaults.','HSMs deployed for high-value keys with FIPS 140-2/3 certification, restricted access, and documented procedures.','Cloud HSMs or on-premise HSMs with automated key operations, high availability, and integrated with all critical cryptographic processes.','Examples: HSM deployment documentation, FIPS certification, Access control records, HSM audit logs.',1,8,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(138,'CMP-01',14,'Is a comprehensive inventory of applicable regulatory and compliance requirements maintained and current?','Verify a register of all applicable regulations, standards, and contractual obligations is maintained with assigned owners and compliance status tracking.','No compliance requirement inventory; regulatory obligations are unknown.','Some regulations are tracked but the inventory is incomplete and not regularly updated.','Complete compliance requirement register with assigned owners, mapped controls, compliance status tracking, and annual updates.','Automated regulatory change monitoring with impact analysis, automated control mapping, and real-time compliance status.','Examples: Compliance requirement register, Regulatory mapping matrix, Owner assignments, Compliance status dashboard.',1,1,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(139,'CMP-02',14,'Is an internal audit program established with regular audits of security controls and compliance?','Check for an internal audit plan, qualified auditors, documented findings, and remediation tracking. Verify audit scope covers security controls and compliance.','No internal audit program for security or compliance.','Occasional audits occur but without a formal plan or consistent methodology.','Formal internal audit program with annual plan, qualified auditors, documented findings, and tracked remediation.','Continuous auditing with automated control testing, real-time compliance monitoring, and risk-based audit prioritization.','Examples: Annual audit plan, Audit reports, Auditor qualifications, Finding remediation tracker.',1,2,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(140,'CMP-03',14,'Is external audit management in place for third-party assessments, certifications, and regulatory examinations?','Verify processes for managing external audits including preparation, evidence collection, finding remediation, and certification maintenance.','No external audit management; audits are chaotic and poorly coordinated.','Basic external audit coordination exists but evidence collection and preparation are ad hoc.','Formal external audit management with preparation checklists, evidence repositories, finding tracking, and certification maintenance.','Audit-ready at all times with continuous evidence collection, automated audit portals, and proactive compliance demonstrations.','Examples: External audit preparation checklist, Evidence repository, SOC 2 report, Certification maintenance records.',1,3,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(141,'CMP-04',14,'Is compliance monitoring performed continuously or periodically to track adherence to regulatory and policy requirements?','Check for compliance monitoring processes, automated compliance tools, regular reporting, and escalation procedures for non-compliance.','No compliance monitoring; issues discovered only during audits.','Periodic compliance checks occur but are manual and may miss issues.','Regular compliance monitoring with automated tools, periodic reporting to management, and escalation for non-compliance.','Continuous compliance monitoring with automated control testing, real-time dashboards, and predictive compliance analytics.','Examples: Compliance monitoring tool dashboard, Compliance status reports, Non-compliance escalation records, Automated test results.',1,4,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(142,'CMP-05',14,'Is a management review process in place to evaluate the effectiveness of the information security management system?','Verify management reviews occur at defined intervals (at least annually) covering security performance, risk changes, audit results, and improvement opportunities.','No management review of the security program.','Informal management reviews occur without structured agenda or documented outcomes.','Formal management review at least annually covering performance metrics, risk status, audit results, and documented improvement decisions.','Quarterly management reviews with data-driven decision support, strategic alignment verification, and effectiveness scoring.','Examples: Management review meeting minutes, Review agenda, Performance metric reports, Improvement decision records.',1,5,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(143,'CMP-06',14,'Is a corrective and preventive action (CAPA) process in place for addressing audit findings and compliance gaps?','Check for documented CAPA process with root cause analysis, action plan development, implementation tracking, and effectiveness verification.','No CAPA process; findings are acknowledged but not systematically addressed.','Some corrective actions taken but without root cause analysis or effectiveness verification.','Formal CAPA process with root cause analysis, action plans, assigned owners, implementation tracking, and effectiveness verification.','Automated CAPA workflow with trend analysis, predictive issue identification, and continuous process improvement integration.','Examples: CAPA procedure, Root cause analysis records, Action plan tracker, Effectiveness verification evidence.',1,6,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(144,'CMP-07',14,'Is there a process to monitor and respond to changes in applicable laws, regulations, and industry standards?','Verify the organization monitors regulatory changes through subscriptions, industry groups, or legal counsel. Check for impact assessments and compliance updates.','No monitoring of regulatory changes; organization reacts only when notified of violations.','Informal monitoring through industry contacts but no systematic process.','Systematic regulatory change monitoring with subscriptions, impact assessments, compliance update procedures, and assigned responsibility.','Automated regulatory intelligence with AI-driven impact analysis, automated control updates, and proactive compliance positioning.','Examples: Regulatory change monitoring subscriptions, Impact assessment records, Compliance update log, Regulatory intelligence reports.',1,7,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(145,'CMP-08',14,'Is the organization compliant with applicable privacy regulations (GDPR, CCPA, HIPAA) with documented evidence?','Verify specific compliance measures for each applicable privacy regulation including data subject rights, consent, DPA, DPIA, and breach notification.','No privacy regulation compliance measures in place.','Some privacy measures exist but compliance gaps remain and documentation is incomplete.','Documented compliance with all applicable privacy regulations including DPIAs, consent management, DSAR procedures, and DPA.','Privacy-by-design embedded in all processes with automated compliance verification and continuous privacy risk monitoring.','Examples: Privacy compliance documentation by regulation, DPIA records, Consent management evidence, DSAR fulfillment logs.',1,8,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(146,'CMP-09',14,'Are industry-specific compliance requirements (PCI DSS, HIPAA, SOX, CMMC) addressed with appropriate controls and evidence?','Verify compliance programs exist for each applicable industry standard with mapped controls, evidence collection, and certification/attestation maintenance.','No industry-specific compliance programs despite applicable requirements.','Some compliance activities exist but programs are incomplete or evidence is insufficient.','Complete compliance programs for each applicable standard with mapped controls, evidence repositories, and maintained certifications.','Integrated compliance management with unified control framework, automated evidence collection, and continuous certification readiness.','Examples: PCI DSS SAQ/ROC, HIPAA risk assessment, SOX control testing evidence, CMMC assessment records, Certification documentation.',1,9,'2026-03-09 13:56:22','2026-03-09 13:56:22'),
(147,'AIG-01',15,'Has the organization established formal policies and procedures for AI risk management, including integration of trustworthy AI characteristics (validity, fairness, transparency, accountability, privacy, security)?','Look for documented AI governance policies that address all seven trustworthy AI characteristics from NIST AI RMF. Verify policies cover legal/regulatory requirements specific to AI, and that risk management processes are transparent and documented.','No formal AI-specific policies exist; AI systems are deployed without governance oversight.','Basic AI policies exist but do not comprehensively address all trustworthy AI characteristics or may lack executive approval.','Comprehensive AI governance policies are documented, approved by leadership, address all trustworthy AI characteristics, and are integrated into existing risk management processes with transparent controls.','AI policies are continuously refined based on evolving regulations, emerging risks, and stakeholder feedback, with automated compliance monitoring and proactive regulatory horizon scanning.','Examples: AI Governance Policy document, AI Ethics Framework, AI Risk Management Procedure, Trustworthy AI Principles document, AI regulatory compliance register.',1,1,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(148,'AIG-02',15,'Does the organization maintain an inventory of all AI systems with risk classifications, and are processes in place for determining risk tolerance levels and safely decommissioning AI systems?','Verify the organization maintains a centralized register of all AI systems (including embedded AI in third-party products) with risk classifications. Check for defined risk tolerance thresholds and documented decommissioning procedures that address data disposal, model archival, and transition planning.','No AI system inventory exists; AI deployments are ad hoc with no tracking or risk classification.','A partial inventory exists but may not cover all AI systems; risk classifications are informal and decommissioning procedures are undefined.','Complete AI system inventory is maintained with formal risk classifications, documented risk tolerances per system category, and established decommissioning procedures covering data disposal and transition.','AI inventory is automated with real-time discovery, dynamic risk scoring, proactive lifecycle management, and decommissioning processes that include impact assessments and stakeholder notification.','Examples: AI System Registry/Inventory, AI Risk Classification Matrix, Risk Tolerance Policy for AI, AI System Decommissioning Procedure, AI Lifecycle Management Framework.',1,2,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(149,'AIG-03',15,'Are AI risk management roles, responsibilities, and reporting lines clearly defined, with executive leadership accountable for AI risk decisions?','Check for organizational charts showing AI governance roles (e.g., Chief AI Officer, AI Ethics Board, AI Risk Committee). Verify executive sign-off requirements for high-risk AI deployments and documented escalation paths. Confirm policies define human-AI oversight configurations.','No dedicated AI risk management roles exist; AI decisions are made informally without clear accountability.','Some AI responsibilities are assigned but roles may overlap, lack formal authority, or miss executive-level accountability.','AI risk management roles are clearly defined with a RACI matrix, executive leadership is formally accountable, and human-AI oversight configurations are documented for each AI system.','AI governance structure is mature with dedicated cross-functional teams, board-level AI oversight committee, succession planning, and regular capability assessments with clear human override authorities.','Examples: AI Governance Org Chart, RACI Matrix for AI Risk, AI Ethics Board Charter, Executive AI Risk Accountability Statement, Human-AI Oversight Configuration documents.',1,3,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(150,'AIG-04',15,'Do personnel involved in AI system design, development, deployment, and oversight receive adequate AI risk management training?','Verify that role-specific AI risk training programs exist for developers, data scientists, product managers, and oversight personnel. Check for training on bias detection, fairness testing, responsible AI practices, and AI-specific incident response. Confirm training completion is tracked and competency is assessed.','No AI-specific risk management training exists for any personnel.','Basic AI awareness training is provided but lacks role-specific depth or comprehensive coverage of responsible AI practices.','Structured AI risk management training programs are in place for all relevant roles, covering bias detection, fairness, transparency, and responsible AI practices with tracked completion and competency assessments.','AI training programs are continuously updated based on emerging risks and technologies, include hands-on exercises and simulations, and are integrated into professional development pathways with certification tracking.','Examples: AI Ethics Training curriculum, Responsible AI Development course, AI Bias Detection workshop materials, Training completion records, Competency assessment results.',1,4,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(151,'AIG-05',15,'Does the organization foster workforce diversity, equity, and inclusion in AI development teams, with interdisciplinary representation in AI decision-making?','Review team composition for AI development and governance activities. Verify diverse representation across demographics, disciplines (technical, domain, social science, legal, ethics), and experience levels. Check that DEIA policies specifically address AI team composition.','No consideration of diversity in AI team composition; teams are homogeneous in background and discipline.','Some awareness of diversity needs in AI teams but no formal policies or systematic approach to ensuring diverse representation.','DEIA policies explicitly address AI team composition, interdisciplinary teams are standard practice for AI decisions, and team diversity metrics are tracked and reported.','Diversity in AI teams is deeply embedded in culture, with proactive recruitment, mentorship programs, diverse advisory boards, and evidence that diverse perspectives measurably improve AI system outcomes.','Examples: DEIA Policy for AI Teams, Team composition reports, Interdisciplinary review board membership records, Diversity metrics dashboard, Advisory board charter.',1,5,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(152,'AIG-06',15,'Does the organization promote a safety-first culture for AI systems, including documentation and communication of AI risks, and processes for AI testing and incident sharing?','Look for organizational practices that encourage reporting AI safety concerns without reprisal. Verify that AI risks and potential impacts are documented and communicated broadly. Check for AI-specific testing protocols, incident tracking, and information sharing arrangements (internal and external).','No safety culture for AI exists; risks are not documented or communicated, and there are no AI-specific testing processes.','Some AI risk documentation exists but communication is limited; AI testing is ad hoc and incident sharing is informal.','Safety-first culture is promoted with formal AI risk documentation, broad communication channels, structured AI testing protocols, incident tracking systems, and participation in external information sharing.','Safety culture is deeply embedded with proactive risk identification, red-teaming exercises, open reporting culture, automated risk detection, and active participation in industry AI safety initiatives and research.','Examples: AI Safety Policy, AI Risk Register with communication records, AI Testing Protocol documents, AI Incident Tracking System, External information sharing agreements (e.g., AI incident databases).',1,6,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(153,'AIG-07',15,'Are mechanisms in place to collect, prioritize, and integrate feedback from external stakeholders (end users, impacted communities, domain experts) into AI system development and deployment?','Verify processes for gathering input from external stakeholders including end users, communities affected by AI decisions, domain experts, and civil society. Check that feedback is formally adjudicated and integrated into system updates. Look for public engagement and transparency reports.','No mechanisms exist for collecting external stakeholder feedback on AI systems.','Some feedback channels exist (e.g., support tickets) but are not structured for AI-specific concerns and feedback is not systematically integrated.','Formal stakeholder engagement mechanisms are in place with structured feedback collection, prioritization processes, and documented integration of adjudicated feedback into AI system updates.','Comprehensive stakeholder engagement program with proactive outreach, diverse engagement methods (surveys, focus groups, advisory panels), real-time feedback integration, and published transparency reports.','Examples: Stakeholder Engagement Plan for AI, Feedback collection portal/system, Feedback adjudication records, Transparency/accountability reports, Community advisory panel charter.',1,7,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(154,'AIG-08',15,'Are policies and contingency processes in place to address AI risks from third-party AI components, data, models, and supply chain dependencies?','Review policies covering third-party AI components including pre-trained models, training data, APIs, and AI SaaS services. Verify that IP rights, licensing, data usage, and risk transfer are addressed. Check for contingency plans to handle failures or incidents in third-party AI systems.','No policies address third-party AI risks; third-party AI components are used without governance or risk assessment.','Basic vendor assessment includes some AI considerations but lacks comprehensive coverage of AI-specific risks, IP issues, and contingency planning.','Comprehensive third-party AI risk policies address IP rights, data provenance, model risks, licensing, and supply chain. Contingency processes are documented for third-party AI failures.','Third-party AI risk management is proactive with continuous monitoring, automated supply chain risk detection, contractual AI risk clauses, and tested contingency processes with regular drills.','Examples: Third-Party AI Risk Policy, AI Vendor Assessment Questionnaire, AI Supply Chain Risk Register, Third-Party AI Contingency Plan, Model/data provenance documentation.',1,8,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(155,'AIG-09',15,'Are the intended purposes, beneficial uses, applicable laws, norms, and deployment contexts for each AI system documented, with organizational mission alignment verified?','Check that each AI system has documented intended purposes, expected beneficial uses, and deployment context. Verify applicable AI-specific laws and regulations are identified per system. Confirm alignment with organizational mission and goals is assessed and documented.','AI systems are deployed without documentation of intended purpose, legal context, or mission alignment.','Some AI systems have basic purpose documentation but legal requirements and mission alignment are not systematically assessed.','Each AI system has comprehensive documentation of intended purpose, beneficial uses, applicable laws and norms, deployment context, and verified mission alignment.','Documentation is living and continuously updated, with automated regulatory mapping, proactive context monitoring, and regular re-evaluation of purpose and alignment as conditions change.','Examples: AI System Purpose Statement, Deployment Context Analysis, AI Regulatory Applicability Matrix, Mission Alignment Assessment, AI Use Case Documentation.',1,9,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(156,'AIG-10',15,'Are interdisciplinary teams with diverse demographics, disciplines, and domain expertise involved in establishing AI system context and design decisions, with socio-technical implications addressed?','Verify that AI system context-setting and design involve teams spanning technical, domain, legal, ethics, and social science expertise. Check that socio-technical implications (human behavior, social impact, organizational effects) are explicitly considered in design decisions.','AI system context and design decisions are made solely by technical teams without interdisciplinary input.','Some non-technical input is sought informally but interdisciplinary participation is not systematic and socio-technical implications are not formally assessed.','Interdisciplinary teams with diverse expertise are formally involved in AI context-setting and design. Socio-technical implications are assessed and documented as part of the design process.','Interdisciplinary collaboration is deeply embedded in AI development culture, with structured co-design sessions, participatory design with impacted communities, and documented socio-technical impact assessments.','Examples: Interdisciplinary Team Charter, Socio-Technical Impact Assessment template, Design Review Board membership records, Participatory design session records, System requirements with socio-technical considerations.',1,10,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(157,'AIG-11',15,'Are organizational risk tolerances for AI systems determined, documented, and used to guide AI risk management activities?','Verify that AI-specific risk tolerance levels are formally defined (e.g., by AI system category, use case, or impact level). Check that risk tolerances are approved by leadership and actively used to scale the intensity of risk management activities.','No AI-specific risk tolerances are defined; all AI systems receive the same (or no) level of risk management.','Informal risk tolerance concepts exist for AI but are not formally documented or consistently applied across AI systems.','AI risk tolerances are formally defined by system category and impact level, approved by leadership, and actively used to determine the scope and depth of risk management activities.','Risk tolerances are dynamically adjusted based on real-time risk data, emerging threats, and operational experience, with automated scaling of risk management controls based on tolerance thresholds.','Examples: AI Risk Tolerance Framework, AI System Risk Categorization Matrix, Leadership-approved risk appetite statement for AI, Risk-scaled assessment procedures.',1,11,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(158,'AIG-12',15,'Are AI system tasks, methods (e.g., classifiers, generative models, recommenders), knowledge limits, and human oversight requirements documented?','Review technical documentation for each AI system covering: specific tasks performed, ML methods used (classification, generation, recommendation, etc.), known knowledge limits and failure modes, and required human oversight. Verify TEVV (Test, Evaluation, Verification, Validation) considerations are documented.','AI systems lack technical documentation of tasks, methods, limitations, or oversight requirements.','Basic technical documentation exists but may be incomplete regarding knowledge limits, failure modes, or oversight specifications.','Comprehensive technical documentation covers tasks, methods, knowledge limits, failure modes, human oversight requirements, and TEVV considerations for each AI system.','Documentation is version-controlled and continuously updated, with automated model cards, real-time knowledge limit detection, and dynamic oversight requirements that adapt to system performance.','Examples: AI Model Cards, Technical Specification documents, Knowledge Limit documentation, Human Oversight Requirements matrix, TEVV Plan and results.',1,12,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(159,'AIG-13',15,'Are the potential benefits, costs (including non-monetary), and performance benchmarks of AI systems examined, documented, and compared against targeted application scope?','Verify that each AI system has a documented analysis of potential benefits and costs (financial, social, reputational, human impact). Check that performance benchmarks are defined and compared against actual results. Confirm application scope is clearly bounded and documented.','No formal analysis of AI system benefits, costs, or performance benchmarks exists.','Basic business case exists but lacks comprehensive cost analysis (especially non-monetary costs) and formal performance benchmarking.','Thorough benefits-costs analysis including non-monetary impacts is documented, performance benchmarks are established and tracked, and application scope is clearly defined and enforced.','Benefits-costs analysis is continuously updated with real-world data, automated performance monitoring against benchmarks triggers alerts, and scope boundaries are dynamically managed.','Examples: AI Business Case Analysis, Cost-Benefit Assessment (including non-monetary), Performance Benchmark Reports, Application Scope Definition document, Operator Proficiency Standards.',1,13,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(160,'AIG-14',15,'Are processes for operator/practitioner proficiency and human oversight of AI systems assessed and documented?','Check that operator and practitioner competency requirements are defined for each AI system. Verify human oversight processes are documented per organizational governance policies, including when human review is required, escalation criteria, and override procedures.','No operator proficiency requirements or human oversight processes are defined for AI systems.','Some informal expectations exist for AI system operators but proficiency is not assessed and oversight processes are not formally documented.','Operator proficiency requirements are defined and assessed for each AI system, human oversight processes are documented with clear escalation and override procedures aligned with governance policies.','Proficiency assessment is continuous with adaptive training, human oversight is dynamically calibrated based on system performance and risk level, and override effectiveness is regularly evaluated.','Examples: Operator Proficiency Standards, Competency Assessment Records, Human Oversight Procedure document, Escalation and Override Protocol, Human-AI Interaction Guidelines.',1,14,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(161,'AIG-15',15,'Are the likelihood and magnitude of AI system impacts (beneficial and harmful) on individuals, groups, communities, and society documented, with regular stakeholder engagement on impacts?','Review impact assessments that document potential beneficial and harmful effects of AI systems across affected populations. Verify that third-party component risks (data, models, software) are mapped. Check for regular stakeholder engagement processes to assess and validate impacts.','No AI impact assessments are conducted; potential harms to individuals or communities are not evaluated.','Some impact considerations exist but are informal, not comprehensive across all affected groups, and lack regular stakeholder engagement.','Comprehensive AI impact assessments document likelihood and magnitude of beneficial and harmful effects across all affected populations, with regular stakeholder engagement and third-party component risk mapping.','Impact assessments are continuously updated with real-world outcome data, proactive engagement identifies emerging impacts, and assessment methodology is validated through independent review.','Examples: AI Impact Assessment reports, Stakeholder Impact Analysis, Third-Party Component Risk Map, Community Engagement Records, Impact Monitoring Dashboard.',1,15,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(162,'AIG-16',15,'Are appropriate methods, metrics, and tools for measuring AI risks identified, applied, and regularly assessed for effectiveness, including independent assessor involvement?','Verify that risk measurement approaches and metrics are selected based on significance of identified risks. Check that metrics are regularly reviewed for appropriateness and effectiveness. Confirm independent assessors (internal experts not on development team, or external auditors) participate in regular assessments.','No formal AI risk measurement methods or metrics exist; risks are not quantified or tracked.','Some AI metrics are used but selection is informal, effectiveness is not assessed, and independent assessment is not practiced.','AI risk measurement methods and metrics are formally selected based on risk significance, regularly assessed for effectiveness, and independent assessors are involved in periodic evaluations.','Measurement approaches are state-of-the-art with automated metric tracking, continuous effectiveness evaluation, independent red-team assessments, and participation in industry benchmarking initiatives.','Examples: AI Risk Measurement Methodology document, Metrics Selection and Review records, Independent Assessment Reports, Assessor Qualification records, Metric Effectiveness Review results.',1,16,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(163,'AIG-17',15,'Are AI systems evaluated for validity, reliability, and generalizability, with test sets, metrics, and testing details documented?','Review TEVV documentation including test datasets, evaluation metrics, and testing tools. Verify that AI systems are demonstrated to be valid and reliable before deployment. Check that limitations of generalizability beyond development conditions are documented.','No formal validity or reliability testing is performed on AI systems before deployment.','Some testing is conducted but test sets, metrics, and tools are not systematically documented, and generalizability limitations are not assessed.','Comprehensive TEVV is performed with documented test sets, metrics, and tools. Validity and reliability are demonstrated, and generalizability limitations are clearly documented.','TEVV is automated and continuous with real-time validity monitoring, automated regression testing, synthetic test data generation, and proactive generalizability assessment across deployment contexts.','Examples: TEVV Documentation package, Test Dataset specifications, Validation Reports, Reliability Assessment results, Generalizability Limitation statements.',1,17,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(164,'AIG-18',15,'Do evaluations involving human subjects meet applicable requirements, use representative populations, and is AI system functionality monitored in production?','Verify that any AI evaluations involving human subjects comply with ethical requirements (IRB or equivalent). Check that evaluation populations are representative of actual user/affected populations. Confirm AI system behavior is continuously monitored in production with appropriate alerting.','AI evaluations do not consider human subject requirements; production monitoring of AI behavior is absent.','Some human subject considerations exist informally; production monitoring is basic and may not cover AI-specific behavioral drift.','Human subject evaluations meet all applicable requirements with representative populations, and production AI monitoring includes behavioral drift detection, performance tracking, and alerting.','Human subject evaluations follow best practices with ongoing consent processes, production monitoring uses advanced techniques (data drift, concept drift, model decay detection), and TEVV effectiveness is regularly meta-evaluated.','Examples: IRB/Ethics approval records, Population representativeness analysis, Production Monitoring Dashboard, AI Behavioral Drift Reports, TEVV Effectiveness Assessment.',1,18,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(165,'AIG-19',15,'Are AI systems regularly evaluated for safety risks, with demonstrated safety and residual risk within organizational tolerance?','Check that AI safety evaluations are conducted regularly throughout the system lifecycle. Verify that AI systems can fail safely (graceful degradation), especially when operating beyond knowledge limits. Confirm safety metrics cover reliability, robustness, real-time monitoring, and response times.','No AI safety evaluations are performed; fail-safe mechanisms are absent.','Basic safety considerations exist but evaluations are not regular, fail-safe mechanisms are limited, and safety metrics are not comprehensive.','Regular AI safety evaluations are conducted with documented results, fail-safe mechanisms are implemented and tested, safety metrics cover reliability, robustness, and response times, and residual risk is within tolerance.','Safety evaluation is continuous with automated safety testing, chaos engineering for AI systems, real-time safety monitoring with automated response, and proactive safety research integration.','Examples: AI Safety Evaluation Reports, Fail-Safe Mechanism documentation, Safety Metrics Dashboard, Residual Risk Acceptance records, Safety Testing protocols and results.',1,19,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(166,'AIG-20',15,'Are AI system security, resilience, transparency, accountability, explainability, and interpretability evaluated and documented?','Review assessments of AI-specific security risks (adversarial attacks, data poisoning, model theft). Verify transparency and accountability mechanisms are documented. Check that AI models are explained and outputs can be interpreted within their context for responsible use.','AI-specific security, transparency, and explainability are not assessed; models are treated as black boxes.','Some security assessment exists but does not cover AI-specific threats; transparency and explainability are limited to basic model descriptions.','AI security evaluations cover adversarial threats and resilience, transparency reports are published, accountability mechanisms are documented, and model explainability is assessed with context-appropriate interpretation.','Comprehensive AI security includes red-teaming against adversarial attacks, transparency is proactive with public documentation, explainability methods are state-of-the-art with audience-appropriate explanations, and accountability is auditable end-to-end.','Examples: AI Security Assessment (adversarial, poisoning, theft), Resilience Testing results, Transparency Report, Accountability Framework document, Model Explainability documentation, Output Interpretation guidelines.',1,20,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(167,'AIG-21',15,'Are AI system privacy risks, fairness, bias, and environmental/sustainability impacts assessed and documented?','Verify privacy impact assessments specific to AI (training data, inference, re-identification risks). Check for bias testing across protected characteristics with documented results. Assess whether environmental impacts of AI model training and operation (compute, energy, carbon) are evaluated.','No AI-specific privacy, fairness, bias, or environmental impact assessments are conducted.','Some awareness of AI bias and privacy exists but assessments are informal and environmental impacts are not considered.','AI privacy impact assessments, comprehensive bias and fairness evaluations across protected characteristics, and environmental impact assessments of AI training and operations are all conducted and documented.','Privacy-by-design is implemented for all AI systems, continuous bias monitoring with automated alerts is in place, fairness metrics are benchmarked against industry standards, and environmental sustainability is a formal criterion in AI system design decisions.','Examples: AI Privacy Impact Assessment, Bias and Fairness Testing Reports, Protected Characteristic Analysis, Environmental Impact Assessment (compute/energy/carbon), AI Sustainability Report.',1,21,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(168,'AIG-22',15,'Are mechanisms in place to track existing, unanticipated, and emergent AI risks over time, including feedback channels for end users to report problems and appeal outcomes?','Review risk tracking mechanisms that capture known, emerging, and unanticipated AI risks. Verify that measurement approaches exist for risks that are difficult to quantify. Check for end-user feedback channels, problem reporting mechanisms, and appeal/redress processes for AI-driven decisions.','No AI risk tracking mechanisms exist; end users have no way to report problems or appeal AI-driven decisions.','Some risk tracking exists but does not cover emergent risks; feedback channels exist but are not AI-specific and lack formal appeal processes.','Comprehensive AI risk tracking covers existing, unanticipated, and emergent risks with dedicated personnel. End-user feedback channels and formal appeal/redress processes are established and integrated into AI system operations.','Risk tracking is proactive with horizon scanning, automated anomaly detection, cross-industry intelligence sharing, real-time user feedback integration, and appeal processes with guaranteed response times and transparent outcomes.','Examples: AI Risk Tracking Register, Emergent Risk Monitoring process, User Feedback Portal for AI systems, Appeal/Redress Procedure, Risk Tracking Personnel assignments, Measurement Gap documentation.',1,22,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(169,'AIG-23',15,'Are AI risks prioritized based on impact and likelihood, with documented risk response plans (mitigate, transfer, avoid, accept) and go/no-go determinations for AI system deployment?','Verify that AI risks are prioritized using a consistent methodology based on impact and likelihood. Check for documented risk response plans identifying the chosen strategy for each significant risk. Confirm go/no-go decision processes exist for AI deployment with documented criteria and residual risk acceptance.','AI deployment decisions are not informed by risk prioritization; no formal risk response plans exist.','Some risk prioritization occurs informally but risk response plans are incomplete and go/no-go decisions lack formal criteria.','AI risks are systematically prioritized, risk response plans document mitigation strategies for each significant risk, go/no-go criteria are defined and applied, and residual risks are formally documented and accepted.','Risk prioritization is dynamic and data-driven, response plans are regularly tested through simulations, go/no-go decisions incorporate automated risk scoring, and residual risk monitoring triggers re-evaluation automatically.','Examples: AI Risk Prioritization Matrix, Risk Response Plans, Go/No-Go Decision Framework, Residual Risk Acceptance records, Deployment Approval documentation.',1,23,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(170,'AIG-24',15,'Are resources for managing AI risks evaluated alongside viable non-AI alternatives, and are mechanisms in place to sustain the value of deployed AI systems?','Check that resource allocation for AI risk management is documented and justified. Verify that non-AI alternatives are formally considered as part of the deployment decision. Confirm mechanisms exist to monitor and sustain the ongoing value of deployed AI systems.','No formal resource planning for AI risk management; non-AI alternatives are not considered; deployed AI system value is not monitored.','Some resource allocation exists but non-AI alternatives are not formally evaluated and value sustainment for deployed AI is ad hoc.','AI risk management resources are formally allocated and justified, non-AI alternatives are evaluated as part of deployment decisions, and deployed AI system value is monitored with defined sustainment mechanisms.','Resource allocation is optimized based on risk data, non-AI alternatives analysis is a standard governance gate, and value sustainment includes automated performance monitoring with proactive retraining triggers.','Examples: AI Risk Management Resource Plan, Non-AI Alternatives Analysis, AI System Value Monitoring Reports, Value Sustainment Procedures, Resource Justification documentation.',1,24,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(171,'AIG-25',15,'Are procedures in place to respond to and recover from previously unknown AI risks, and are mechanisms established to supersede, disengage, or deactivate AI systems when needed?','Review incident response procedures specific to newly discovered AI risks. Verify that kill-switch or disengagement mechanisms exist for AI systems that demonstrate problematic performance. Check that responsibilities for AI system deactivation are assigned and understood.','No procedures exist for responding to unknown AI risks or deactivating problematic AI systems.','Basic incident response exists but is not AI-specific; disengagement mechanisms are informal with unclear responsibilities.','AI-specific incident response procedures address previously unknown risks, disengagement and deactivation mechanisms are implemented and tested, and responsibilities are clearly assigned and documented.','Unknown risk response includes automated detection and circuit-breaker mechanisms, disengagement is tested through regular drills, and recovery procedures include root cause analysis with systematic improvements.','Examples: AI Incident Response Procedure, AI System Kill-Switch/Disengagement Protocol, Deactivation Responsibility Matrix, Unknown Risk Response Playbook, Recovery and Root Cause Analysis templates.',1,25,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(172,'AIG-26',15,'Are AI risks and benefits from third-party resources (including pre-trained models) regularly monitored with documented risk controls?','Verify ongoing monitoring of third-party AI components including pre-trained/foundation models, AI APIs, and AI-powered services. Check that risk controls are applied (input/output validation, performance monitoring, contractual requirements). Confirm monitoring frequency and scope are documented.','Third-party AI components are not monitored after initial procurement; no ongoing risk controls exist.','Some monitoring of third-party AI components occurs but is not regular, and risk controls are limited or undocumented.','Third-party AI resources are regularly monitored with documented risk controls, including pre-trained model monitoring, performance tracking, and contractual compliance verification.','Continuous automated monitoring of third-party AI components with real-time anomaly detection, automated compliance checking, and proactive risk intelligence on vendor AI changes and vulnerabilities.','Examples: Third-Party AI Monitoring Reports, Pre-Trained Model Monitoring Logs, Vendor AI Risk Control documentation, Contractual AI Requirements tracker, Third-Party AI Performance Dashboard.',1,26,'2026-03-13 03:52:56','2026-03-13 03:52:56'),
(173,'AIG-27',15,'Are post-deployment AI monitoring plans implemented, with measurable continual improvement activities, and are AI incidents and errors communicated to affected communities with documented response/recovery processes?','Review post-deployment monitoring plans for AI systems including user feedback capture, performance evaluation, and change management. Verify continual improvement activities with measurable outcomes. Check that AI incidents and errors are communicated to affected communities with documented tracking, response, and recovery processes.','No post-deployment monitoring exists for AI systems; incidents are not tracked or communicated to affected parties.','Some post-deployment monitoring exists but lacks structure; incident communication is reactive and inconsistent.','Post-deployment monitoring plans are implemented with user feedback mechanisms, continual improvement activities are tracked with measurable outcomes, and AI incidents are promptly communicated to affected communities with documented response and recovery processes.','Post-deployment monitoring is continuous and automated, improvement activities are driven by data analytics, incident communication is proactive and transparent with guaranteed timelines, and lessons learned are systematically integrated into AI development lifecycle.','Examples: Post-Deployment Monitoring Plan, AI System Change Management Procedure, Continual Improvement Tracker, AI Incident Communication templates, Incident Response and Recovery logs, Affected Community Notification records.',1,27,'2026-03-13 03:52:56','2026-03-13 03:52:56');
UNLOCK TABLES;

-- =============================================================================
-- SECTION 6: Seed Data - Question-to-Framework Mappings
-- =============================================================================

LOCK TABLES `grc_question_framework_map` WRITE;
INSERT IGNORE INTO `grc_question_framework_map` (`id`, `question_id`, `framework_id`, `requirement_id`, `mapping_strength`, `created_at`) VALUES (1,1,1,1,'strong','2026-03-09 13:56:22'),
(2,1,2,475,'exact','2026-03-09 13:56:22'),
(3,1,5,476,'exact','2026-03-09 13:56:22'),
(4,1,6,477,'strong','2026-03-09 13:56:22'),
(5,1,7,135,'strong','2026-03-09 13:56:22'),
(6,1,9,443,'strong','2026-03-09 13:56:22'),
(7,1,10,478,'strong','2026-03-09 13:56:22'),
(8,1,11,479,'partial','2026-03-09 13:56:22'),
(9,2,1,2,'strong','2026-03-09 13:56:22'),
(10,2,2,480,'exact','2026-03-09 13:56:22'),
(11,2,5,481,'exact','2026-03-09 13:56:22'),
(12,2,6,477,'partial','2026-03-09 13:56:22'),
(13,2,7,135,'partial','2026-03-09 13:56:22'),
(14,2,9,444,'strong','2026-03-09 13:56:23'),
(15,2,10,482,'exact','2026-03-09 13:56:23'),
(17,3,1,2,'strong','2026-03-09 13:56:23'),
(18,3,2,484,'strong','2026-03-09 13:56:23'),
(19,3,5,485,'exact','2026-03-09 13:56:23'),
(20,3,5,486,'strong','2026-03-09 13:56:23'),
(21,3,9,451,'strong','2026-03-09 13:56:23'),
(22,3,10,478,'partial','2026-03-09 13:56:23'),
(23,4,1,3,'strong','2026-03-09 13:56:23'),
(24,4,2,475,'partial','2026-03-09 13:56:23'),
(25,4,5,487,'exact','2026-03-09 13:56:23'),
(26,4,6,488,'strong','2026-03-09 13:56:23'),
(27,4,7,138,'strong','2026-03-09 13:56:23'),
(28,4,9,443,'partial','2026-03-09 13:56:23'),
(29,5,1,9,'exact','2026-03-09 13:56:23'),
(30,5,2,489,'strong','2026-03-09 13:56:23'),
(31,5,5,490,'exact','2026-03-09 13:56:23'),
(32,5,5,491,'exact','2026-03-09 13:56:23'),
(33,5,6,492,'exact','2026-03-09 13:56:23'),
(34,5,7,132,'exact','2026-03-09 13:56:23'),
(35,5,9,448,'strong','2026-03-09 13:56:23'),
(36,5,10,493,'exact','2026-03-09 13:56:23'),
(38,6,1,9,'partial','2026-03-09 13:56:23'),
(39,6,2,494,'exact','2026-03-09 13:56:23'),
(40,6,5,495,'exact','2026-03-09 13:56:23'),
(41,6,5,486,'strong','2026-03-09 13:56:23'),
(42,6,9,443,'partial','2026-03-09 13:56:23'),
(44,6,10,497,'strong','2026-03-09 13:56:23'),
(45,7,1,3,'partial','2026-03-09 13:56:23'),
(46,7,2,475,'partial','2026-03-09 13:56:23'),
(47,7,5,498,'exact','2026-03-09 13:56:24'),
(48,7,5,499,'strong','2026-03-09 13:56:24'),
(49,7,9,443,'partial','2026-03-09 13:56:24'),
(50,8,1,2,'partial','2026-03-09 13:56:24'),
(51,8,2,484,'strong','2026-03-09 13:56:24'),
(52,8,5,481,'partial','2026-03-09 13:56:24'),
(53,8,5,500,'strong','2026-03-09 13:56:24'),
(54,8,9,451,'partial','2026-03-09 13:56:24'),
(55,9,1,13,'exact','2026-03-09 13:56:24'),
(56,9,2,501,'strong','2026-03-09 13:56:24'),
(57,9,5,500,'exact','2026-03-09 13:56:24'),
(58,9,5,502,'strong','2026-03-09 13:56:24'),
(59,9,9,451,'partial','2026-03-09 13:56:24'),
(61,10,1,1,'partial','2026-03-09 13:56:24'),
(62,10,2,484,'partial','2026-03-09 13:56:24'),
(63,10,5,504,'strong','2026-03-09 13:56:24'),
(64,10,9,455,'partial','2026-03-09 13:56:24'),
(65,10,10,505,'partial','2026-03-09 13:56:24'),
(66,11,1,1,'partial','2026-03-09 13:56:24'),
(67,11,2,475,'partial','2026-03-09 13:56:24'),
(68,11,5,476,'partial','2026-03-09 13:56:24'),
(69,11,5,506,'strong','2026-03-09 13:56:24'),
(70,11,9,443,'partial','2026-03-09 13:56:24'),
(71,12,1,4,'strong','2026-03-09 13:56:24'),
(72,12,2,507,'exact','2026-03-09 13:56:24'),
(73,12,5,508,'exact','2026-03-09 13:56:24'),
(74,12,5,504,'strong','2026-03-09 13:56:24'),
(76,13,1,18,'exact','2026-03-09 13:56:24'),
(77,13,2,509,'exact','2026-03-09 13:56:24'),
(78,13,5,510,'exact','2026-03-09 13:56:24'),
(79,13,6,511,'exact','2026-03-09 13:56:24'),
(80,13,7,52,'exact','2026-03-09 13:56:24'),
(81,13,9,355,'strong','2026-03-09 13:56:24'),
(82,13,9,366,'strong','2026-03-09 13:56:24'),
(83,13,10,512,'strong','2026-03-09 13:56:24'),
(84,13,11,513,'strong','2026-03-09 13:56:25'),
(85,14,1,20,'exact','2026-03-09 13:56:25'),
(86,14,2,514,'exact','2026-03-09 13:56:25'),
(87,14,5,515,'exact','2026-03-09 13:56:25'),
(88,14,6,516,'exact','2026-03-09 13:56:25'),
(89,14,7,56,'exact','2026-03-09 13:56:25'),
(90,14,9,355,'exact','2026-03-09 13:56:25'),
(91,14,10,517,'strong','2026-03-09 13:56:25'),
(92,14,11,518,'strong','2026-03-09 13:56:25'),
(94,15,1,18,'strong','2026-03-09 13:56:25'),
(95,15,2,520,'exact','2026-03-09 13:56:25'),
(96,15,5,521,'exact','2026-03-09 13:56:25'),
(97,15,6,522,'exact','2026-03-09 13:56:25'),
(98,15,7,97,'exact','2026-03-09 13:56:25'),
(99,15,9,378,'exact','2026-03-09 13:56:25'),
(100,15,10,523,'strong','2026-03-09 13:56:25'),
(101,15,11,524,'exact','2026-03-09 13:56:25'),
(102,16,1,18,'strong','2026-03-09 13:56:25'),
(103,16,2,525,'exact','2026-03-09 13:56:25'),
(104,16,5,515,'strong','2026-03-09 13:56:25'),
(105,16,6,526,'exact','2026-03-09 13:56:25'),
(106,16,7,58,'exact','2026-03-09 13:56:25'),
(107,16,9,390,'strong','2026-03-09 13:56:25'),
(108,16,11,527,'exact','2026-03-09 13:56:25'),
(110,17,1,19,'exact','2026-03-09 13:56:25'),
(111,17,2,509,'strong','2026-03-09 13:56:25'),
(112,17,5,515,'strong','2026-03-09 13:56:25'),
(113,17,6,511,'partial','2026-03-09 13:56:25'),
(114,17,7,52,'partial','2026-03-09 13:56:25'),
(115,17,9,358,'strong','2026-03-09 13:56:26'),
(116,17,10,517,'partial','2026-03-09 13:56:26'),
(117,17,11,513,'partial','2026-03-09 13:56:26'),
(119,18,1,18,'strong','2026-03-09 13:56:26'),
(120,18,2,530,'exact','2026-03-09 13:56:26'),
(121,18,5,531,'exact','2026-03-09 13:56:26'),
(122,18,6,532,'exact','2026-03-09 13:56:26'),
(123,18,7,101,'exact','2026-03-09 13:56:26'),
(124,18,9,369,'exact','2026-03-09 13:56:26'),
(125,18,10,512,'strong','2026-03-09 13:56:26'),
(126,18,11,533,'exact','2026-03-09 13:56:26'),
(127,19,1,18,'partial','2026-03-09 13:56:26'),
(128,19,2,520,'partial','2026-03-09 13:56:26'),
(129,19,5,521,'strong','2026-03-09 13:56:26'),
(130,19,6,534,'strong','2026-03-09 13:56:26'),
(131,19,7,95,'strong','2026-03-09 13:56:26'),
(132,19,11,535,'strong','2026-03-09 13:56:26'),
(133,20,1,18,'strong','2026-03-09 13:56:26'),
(134,20,2,509,'strong','2026-03-09 13:56:26'),
(135,20,5,510,'strong','2026-03-09 13:56:26'),
(136,20,6,511,'strong','2026-03-09 13:56:26'),
(137,20,7,52,'strong','2026-03-09 13:56:26'),
(138,20,9,390,'exact','2026-03-09 13:56:26'),
(139,20,11,536,'exact','2026-03-09 13:56:26'),
(140,21,1,18,'strong','2026-03-09 13:56:26'),
(141,21,2,537,'strong','2026-03-09 13:56:26'),
(142,21,5,538,'exact','2026-03-09 13:56:26'),
(143,21,6,539,'exact','2026-03-09 13:56:26'),
(144,21,7,63,'exact','2026-03-09 13:56:26'),
(145,21,9,378,'strong','2026-03-09 13:56:26'),
(146,21,10,540,'strong','2026-03-09 13:56:26'),
(147,21,11,541,'strong','2026-03-09 13:56:26'),
(148,22,1,18,'partial','2026-03-09 13:56:26'),
(149,22,2,542,'exact','2026-03-09 13:56:26'),
(150,22,5,510,'strong','2026-03-09 13:56:26'),
(151,22,6,534,'exact','2026-03-09 13:56:26'),
(152,22,7,95,'exact','2026-03-09 13:56:27'),
(153,22,11,513,'strong','2026-03-09 13:56:27'),
(154,23,1,20,'exact','2026-03-09 13:56:27'),
(155,23,2,514,'exact','2026-03-09 13:56:27'),
(156,23,5,515,'exact','2026-03-09 13:56:27'),
(157,23,6,543,'exact','2026-03-09 13:56:27'),
(158,23,7,53,'exact','2026-03-09 13:56:27'),
(159,23,9,358,'exact','2026-03-09 13:56:27'),
(161,23,11,518,'strong','2026-03-09 13:56:27'),
(162,24,1,18,'partial','2026-03-09 13:56:27'),
(163,24,2,537,'partial','2026-03-09 13:56:27'),
(164,24,5,544,'exact','2026-03-09 13:56:27'),
(165,24,6,545,'exact','2026-03-09 13:56:27'),
(166,24,7,61,'exact','2026-03-09 13:56:27'),
(167,24,9,377,'strong','2026-03-09 13:56:27'),
(168,24,11,546,'strong','2026-03-09 13:56:27'),
(169,25,1,18,'partial','2026-03-09 13:56:27'),
(170,25,2,525,'strong','2026-03-09 13:56:27'),
(171,25,5,515,'partial','2026-03-09 13:56:27'),
(172,25,6,511,'partial','2026-03-09 13:56:27'),
(173,25,7,52,'partial','2026-03-09 13:56:27'),
(174,25,9,390,'partial','2026-03-09 13:56:27'),
(175,26,1,18,'strong','2026-03-09 13:56:27'),
(176,26,2,520,'strong','2026-03-09 13:56:27'),
(177,26,5,531,'strong','2026-03-09 13:56:27'),
(178,26,6,547,'exact','2026-03-09 13:56:27'),
(179,26,7,96,'exact','2026-03-09 13:56:27'),
(180,26,9,378,'strong','2026-03-09 13:56:27'),
(181,26,11,548,'strong','2026-03-09 13:56:27'),
(182,27,1,22,'strong','2026-03-09 13:56:27'),
(183,27,2,549,'exact','2026-03-09 13:56:27'),
(184,27,5,550,'exact','2026-03-09 13:56:27'),
(185,27,6,551,'strong','2026-03-09 13:56:27'),
(186,27,7,115,'strong','2026-03-09 13:56:27'),
(187,27,9,302,'strong','2026-03-09 13:56:27'),
(188,27,10,517,'partial','2026-03-09 13:56:27'),
(189,27,11,552,'exact','2026-03-09 13:56:27'),
(190,28,1,24,'exact','2026-03-09 13:56:28'),
(191,28,2,553,'exact','2026-03-09 13:56:28'),
(192,28,5,554,'exact','2026-03-09 13:56:28'),
(193,28,6,555,'exact','2026-03-09 13:56:28'),
(194,28,7,154,'exact','2026-03-09 13:56:28'),
(195,28,9,311,'exact','2026-03-09 13:56:28'),
(196,28,10,556,'exact','2026-03-09 13:56:28'),
(197,28,11,557,'exact','2026-03-09 13:56:28'),
(198,29,1,24,'exact','2026-03-09 13:56:28'),
(199,29,2,553,'strong','2026-03-09 13:56:28'),
(200,29,5,558,'exact','2026-03-09 13:56:28'),
(201,29,6,559,'exact','2026-03-09 13:56:28'),
(202,29,7,146,'exact','2026-03-09 13:56:28'),
(203,29,9,317,'exact','2026-03-09 13:56:28'),
(204,29,10,540,'exact','2026-03-09 13:56:28'),
(205,29,11,560,'exact','2026-03-09 13:56:28'),
(206,30,1,22,'strong','2026-03-09 13:56:28'),
(207,30,2,561,'exact','2026-03-09 13:56:28'),
(208,30,5,562,'strong','2026-03-09 13:56:28'),
(209,30,6,563,'strong','2026-03-09 13:56:28'),
(210,30,7,117,'strong','2026-03-09 13:56:28'),
(211,30,9,304,'strong','2026-03-09 13:56:28'),
(212,30,10,564,'strong','2026-03-09 13:56:28'),
(213,30,11,565,'exact','2026-03-09 13:56:28'),
(214,31,1,44,'exact','2026-03-09 13:56:28'),
(215,31,2,566,'exact','2026-03-09 13:56:28'),
(216,31,5,495,'strong','2026-03-09 13:56:28'),
(217,31,9,443,'partial','2026-03-09 13:56:28'),
(218,31,10,493,'strong','2026-03-09 13:56:28'),
(219,32,1,22,'strong','2026-03-09 13:56:28'),
(220,32,2,567,'exact','2026-03-09 13:56:28'),
(221,32,5,562,'strong','2026-03-09 13:56:28'),
(222,32,6,563,'exact','2026-03-09 13:56:28'),
(223,32,7,117,'exact','2026-03-09 13:56:28'),
(224,32,9,299,'exact','2026-03-09 13:56:28'),
(225,32,10,568,'exact','2026-03-09 13:56:28'),
(226,32,11,569,'strong','2026-03-09 13:56:28'),
(227,33,1,44,'exact','2026-03-09 13:56:28'),
(228,33,1,45,'strong','2026-03-09 13:56:28'),
(229,33,2,566,'exact','2026-03-09 13:56:29'),
(230,33,5,495,'strong','2026-03-09 13:56:29'),
(231,33,9,302,'partial','2026-03-09 13:56:29'),
(232,33,10,570,'exact','2026-03-09 13:56:29'),
(233,34,1,22,'strong','2026-03-09 13:56:29'),
(234,34,2,571,'exact','2026-03-09 13:56:29'),
(235,34,5,554,'partial','2026-03-09 13:56:29'),
(236,34,9,308,'strong','2026-03-09 13:56:29'),
(237,34,10,572,'strong','2026-03-09 13:56:29'),
(238,34,11,573,'strong','2026-03-09 13:56:29'),
(239,35,1,18,'strong','2026-03-09 13:56:29'),
(240,35,2,574,'strong','2026-03-09 13:56:29'),
(241,35,5,554,'partial','2026-03-09 13:56:29'),
(242,35,6,575,'strong','2026-03-09 13:56:29'),
(243,35,7,54,'strong','2026-03-09 13:56:29'),
(244,35,9,341,'strong','2026-03-09 13:56:29'),
(245,35,11,569,'partial','2026-03-09 13:56:29'),
(246,36,1,35,'exact','2026-03-09 13:56:29'),
(247,36,2,576,'exact','2026-03-09 13:56:29'),
(248,36,5,577,'exact','2026-03-09 13:56:29'),
(249,36,6,578,'strong','2026-03-09 13:56:29'),
(250,36,7,123,'strong','2026-03-09 13:56:29'),
(251,36,9,404,'strong','2026-03-09 13:56:29'),
(252,36,10,579,'exact','2026-03-09 13:56:29'),
(253,36,11,580,'exact','2026-03-09 13:56:29'),
(254,37,1,49,'exact','2026-03-09 13:56:29'),
(255,37,2,501,'exact','2026-03-09 13:56:29'),
(256,37,5,495,'partial','2026-03-09 13:56:29'),
(257,37,9,443,'partial','2026-03-09 13:56:29'),
(258,37,10,540,'partial','2026-03-09 13:56:29'),
(259,38,1,22,'partial','2026-03-09 13:56:29'),
(260,38,2,567,'strong','2026-03-09 13:56:29'),
(261,38,5,562,'partial','2026-03-09 13:56:29'),
(262,38,9,449,'partial','2026-03-09 13:56:29'),
(263,38,10,581,'exact','2026-03-09 13:56:29'),
(265,39,1,25,'exact','2026-03-09 13:56:29'),
(266,39,2,582,'exact','2026-03-09 13:56:29'),
(267,39,5,583,'exact','2026-03-09 13:56:29'),
(268,39,6,584,'exact','2026-03-09 13:56:29'),
(269,39,7,156,'exact','2026-03-09 13:56:29'),
(270,39,9,326,'exact','2026-03-09 13:56:30'),
(271,39,10,585,'strong','2026-03-09 13:56:30'),
(272,39,11,586,'exact','2026-03-09 13:56:30'),
(273,40,1,18,'strong','2026-03-09 13:56:30'),
(274,40,2,587,'exact','2026-03-09 13:56:30'),
(275,40,5,588,'exact','2026-03-09 13:56:30'),
(276,40,6,589,'exact','2026-03-09 13:56:30'),
(277,40,7,86,'exact','2026-03-09 13:56:30'),
(278,40,9,288,'exact','2026-03-09 13:56:30'),
(279,40,10,517,'partial','2026-03-09 13:56:30'),
(280,40,11,590,'exact','2026-03-09 13:56:30'),
(281,41,1,26,'strong','2026-03-09 13:56:30'),
(282,41,2,591,'exact','2026-03-09 13:56:30'),
(283,41,5,592,'exact','2026-03-09 13:56:30'),
(284,41,6,593,'exact','2026-03-09 13:56:30'),
(285,41,7,155,'exact','2026-03-09 13:56:30'),
(286,41,9,346,'exact','2026-03-09 13:56:30'),
(287,41,10,585,'partial','2026-03-09 13:56:30'),
(288,41,11,594,'exact','2026-03-09 13:56:30'),
(289,42,1,18,'strong','2026-03-09 13:56:30'),
(290,42,2,537,'strong','2026-03-09 13:56:30'),
(291,42,5,588,'strong','2026-03-09 13:56:30'),
(292,42,6,595,'exact','2026-03-09 13:56:30'),
(293,42,7,69,'exact','2026-03-09 13:56:30'),
(294,42,9,404,'partial','2026-03-09 13:56:30'),
(295,42,11,596,'strong','2026-03-09 13:56:30'),
(296,43,1,22,'strong','2026-03-09 13:56:30'),
(297,43,2,597,'exact','2026-03-09 13:56:30'),
(298,43,5,554,'partial','2026-03-09 13:56:30'),
(299,43,6,598,'exact','2026-03-09 13:56:30'),
(300,43,7,121,'exact','2026-03-09 13:56:30'),
(301,43,9,402,'strong','2026-03-09 13:56:30'),
(302,43,11,599,'strong','2026-03-09 13:56:30'),
(303,44,1,18,'partial','2026-03-09 13:56:30'),
(304,44,2,600,'exact','2026-03-09 13:56:30'),
(305,44,5,601,'exact','2026-03-09 13:56:30'),
(306,44,5,602,'exact','2026-03-09 13:56:31'),
(307,44,6,589,'strong','2026-03-09 13:56:31'),
(308,44,7,86,'strong','2026-03-09 13:56:31'),
(309,44,9,603,'exact','2026-03-09 13:56:31'),
(310,44,10,604,'strong','2026-03-09 13:56:31'),
(311,44,11,479,'exact','2026-03-09 13:56:31'),
(312,45,1,25,'strong','2026-03-09 13:56:31'),
(313,45,2,605,'strong','2026-03-09 13:56:31'),
(314,45,5,588,'strong','2026-03-09 13:56:31'),
(315,45,6,606,'exact','2026-03-09 13:56:31'),
(316,45,7,93,'exact','2026-03-09 13:56:31'),
(317,45,9,350,'strong','2026-03-09 13:56:31'),
(318,45,11,607,'exact','2026-03-09 13:56:31'),
(319,46,1,18,'strong','2026-03-09 13:56:31'),
(320,46,2,587,'exact','2026-03-09 13:56:31'),
(321,46,5,588,'exact','2026-03-09 13:56:31'),
(322,46,6,608,'exact','2026-03-09 13:56:31'),
(323,46,7,87,'exact','2026-03-09 13:56:31'),
(324,46,9,288,'exact','2026-03-09 13:56:31'),
(325,46,11,590,'strong','2026-03-09 13:56:31'),
(326,47,1,35,'strong','2026-03-09 13:56:31'),
(327,47,2,576,'strong','2026-03-09 13:56:31'),
(328,47,5,577,'strong','2026-03-09 13:56:31'),
(329,47,6,578,'strong','2026-03-09 13:56:31'),
(330,47,7,123,'strong','2026-03-09 13:56:31'),
(331,47,11,580,'partial','2026-03-09 13:56:31'),
(332,48,1,18,'partial','2026-03-09 13:56:31'),
(333,48,2,537,'partial','2026-03-09 13:56:31'),
(334,48,5,588,'partial','2026-03-09 13:56:31'),
(335,48,6,539,'partial','2026-03-09 13:56:31'),
(336,48,7,63,'partial','2026-03-09 13:56:31'),
(337,48,11,590,'partial','2026-03-09 13:56:31'),
(338,49,1,23,'exact','2026-03-09 13:56:31'),
(339,49,2,609,'exact','2026-03-09 13:56:31'),
(340,49,5,610,'exact','2026-03-09 13:56:31'),
(341,49,6,611,'exact','2026-03-09 13:56:31'),
(342,49,7,143,'exact','2026-03-09 13:56:31'),
(343,49,9,268,'exact','2026-03-09 13:56:31'),
(344,49,11,612,'exact','2026-03-09 13:56:32'),
(345,50,1,23,'exact','2026-03-09 13:56:32'),
(346,50,2,613,'exact','2026-03-09 13:56:32'),
(347,50,5,610,'strong','2026-03-09 13:56:32'),
(348,50,6,614,'exact','2026-03-09 13:56:32'),
(349,50,7,139,'exact','2026-03-09 13:56:32'),
(350,50,9,265,'exact','2026-03-09 13:56:32'),
(351,50,11,615,'exact','2026-03-09 13:56:32'),
(352,51,1,27,'exact','2026-03-09 13:56:32'),
(353,51,2,616,'exact','2026-03-09 13:56:32'),
(354,51,5,583,'exact','2026-03-09 13:56:32'),
(355,51,6,617,'exact','2026-03-09 13:56:32'),
(356,51,7,160,'exact','2026-03-09 13:56:32'),
(357,51,9,434,'exact','2026-03-09 13:56:32'),
(358,51,11,618,'exact','2026-03-09 13:56:32'),
(359,52,1,18,'strong','2026-03-09 13:56:32'),
(360,52,2,537,'strong','2026-03-09 13:56:32'),
(361,52,5,538,'exact','2026-03-09 13:56:32'),
(362,52,6,539,'exact','2026-03-09 13:56:32'),
(363,52,7,63,'exact','2026-03-09 13:56:32'),
(364,52,9,378,'partial','2026-03-09 13:56:32'),
(365,52,11,541,'strong','2026-03-09 13:56:32'),
(366,53,1,23,'strong','2026-03-09 13:56:32'),
(367,53,2,613,'strong','2026-03-09 13:56:32'),
(368,53,5,610,'strong','2026-03-09 13:56:32'),
(369,53,6,619,'exact','2026-03-09 13:56:32'),
(370,53,7,67,'exact','2026-03-09 13:56:32'),
(371,53,9,296,'exact','2026-03-09 13:56:33'),
(372,53,11,620,'exact','2026-03-09 13:56:33'),
(373,54,1,23,'partial','2026-03-09 13:56:33'),
(374,54,2,613,'partial','2026-03-09 13:56:33'),
(375,54,5,562,'partial','2026-03-09 13:56:33'),
(376,54,6,614,'partial','2026-03-09 13:56:33'),
(377,54,7,139,'partial','2026-03-09 13:56:33'),
(378,54,11,621,'exact','2026-03-09 13:56:33'),
(379,55,1,27,'exact','2026-03-09 13:56:33'),
(380,55,2,616,'exact','2026-03-09 13:56:33'),
(381,55,5,583,'exact','2026-03-09 13:56:33'),
(382,55,6,617,'strong','2026-03-09 13:56:33'),
(383,55,7,160,'strong','2026-03-09 13:56:33'),
(384,55,9,422,'strong','2026-03-09 13:56:33'),
(385,55,11,622,'exact','2026-03-09 13:56:33'),
(386,56,1,34,'strong','2026-03-09 13:56:33'),
(387,56,2,613,'partial','2026-03-09 13:56:33'),
(388,56,5,610,'partial','2026-03-09 13:56:33'),
(389,56,9,276,'partial','2026-03-09 13:56:33'),
(390,56,11,623,'strong','2026-03-09 13:56:33'),
(391,57,1,18,'strong','2026-03-09 13:56:33'),
(392,57,2,613,'strong','2026-03-09 13:56:33'),
(393,57,5,538,'strong','2026-03-09 13:56:33'),
(394,57,6,539,'strong','2026-03-09 13:56:33'),
(395,57,7,63,'strong','2026-03-09 13:56:33'),
(396,57,9,268,'strong','2026-03-09 13:56:33'),
(397,57,11,624,'exact','2026-03-09 13:56:33'),
(398,58,1,23,'partial','2026-03-09 13:56:33'),
(399,58,2,625,'strong','2026-03-09 13:56:33'),
(400,58,5,626,'exact','2026-03-09 13:56:33'),
(401,58,6,614,'partial','2026-03-09 13:56:33'),
(402,58,9,267,'exact','2026-03-09 13:56:33'),
(403,58,11,627,'strong','2026-03-09 13:56:33'),
(404,59,1,23,'strong','2026-03-09 13:56:33'),
(405,59,2,609,'strong','2026-03-09 13:56:33'),
(406,59,5,610,'strong','2026-03-09 13:56:33'),
(407,59,6,611,'strong','2026-03-09 13:56:33'),
(408,59,7,143,'strong','2026-03-09 13:56:33'),
(409,59,9,268,'strong','2026-03-09 13:56:33'),
(410,59,11,612,'strong','2026-03-09 13:56:33'),
(411,60,1,31,'exact','2026-03-09 13:56:33'),
(412,60,2,628,'exact','2026-03-09 13:56:33'),
(413,60,5,629,'exact','2026-03-09 13:56:34'),
(414,60,6,630,'strong','2026-03-09 13:56:34'),
(415,60,9,341,'exact','2026-03-09 13:56:34'),
(416,60,11,631,'exact','2026-03-09 13:56:34'),
(417,61,1,31,'strong','2026-03-09 13:56:34'),
(418,61,2,632,'exact','2026-03-09 13:56:34'),
(419,61,5,629,'strong','2026-03-09 13:56:34'),
(420,61,9,343,'exact','2026-03-09 13:56:34'),
(421,61,11,633,'exact','2026-03-09 13:56:34'),
(422,62,1,31,'strong','2026-03-09 13:56:34'),
(423,62,2,634,'exact','2026-03-09 13:56:34'),
(424,62,5,629,'strong','2026-03-09 13:56:34'),
(425,62,9,345,'exact','2026-03-09 13:56:34'),
(426,62,11,635,'exact','2026-03-09 13:56:34'),
(427,63,1,23,'strong','2026-03-09 13:56:34'),
(428,63,2,613,'strong','2026-03-09 13:56:34'),
(429,63,5,636,'strong','2026-03-09 13:56:34'),
(430,63,9,351,'exact','2026-03-09 13:56:34'),
(431,63,11,623,'strong','2026-03-09 13:56:34'),
(432,64,1,18,'strong','2026-03-09 13:56:34'),
(433,64,2,637,'exact','2026-03-09 13:56:34'),
(434,64,5,531,'strong','2026-03-09 13:56:34'),
(435,64,9,344,'strong','2026-03-09 13:56:34'),
(436,64,11,638,'exact','2026-03-09 13:56:34'),
(437,65,1,31,'strong','2026-03-09 13:56:34'),
(438,65,2,632,'strong','2026-03-09 13:56:34'),
(439,65,5,639,'strong','2026-03-09 13:56:34'),
(440,65,6,593,'partial','2026-03-09 13:56:34'),
(441,65,9,346,'strong','2026-03-09 13:56:34'),
(442,65,11,640,'exact','2026-03-09 13:56:34'),
(443,66,1,31,'exact','2026-03-09 13:56:34'),
(444,66,2,641,'exact','2026-03-09 13:56:34'),
(445,66,5,642,'exact','2026-03-09 13:56:34'),
(446,66,6,643,'exact','2026-03-09 13:56:34'),
(447,66,7,88,'exact','2026-03-09 13:56:34'),
(448,66,9,353,'exact','2026-03-09 13:56:34'),
(450,66,11,645,'strong','2026-03-09 13:56:35'),
(451,67,1,31,'strong','2026-03-09 13:56:35'),
(452,67,2,646,'exact','2026-03-09 13:56:35'),
(453,67,5,642,'strong','2026-03-09 13:56:35'),
(454,67,6,647,'exact','2026-03-09 13:56:35'),
(455,67,7,89,'exact','2026-03-09 13:56:35'),
(456,67,9,353,'strong','2026-03-09 13:56:35'),
(458,68,1,31,'strong','2026-03-09 13:56:35'),
(459,68,2,632,'strong','2026-03-09 13:56:35'),
(460,68,5,629,'strong','2026-03-09 13:56:35'),
(461,68,9,342,'exact','2026-03-09 13:56:35'),
(462,68,11,649,'strong','2026-03-09 13:56:35'),
(463,69,1,26,'strong','2026-03-09 13:56:35'),
(464,69,2,591,'strong','2026-03-09 13:56:35'),
(465,69,5,650,'strong','2026-03-09 13:56:35'),
(466,69,6,651,'exact','2026-03-09 13:56:35'),
(467,69,7,133,'exact','2026-03-09 13:56:35'),
(468,69,9,431,'exact','2026-03-09 13:56:35'),
(469,69,11,594,'strong','2026-03-09 13:56:35'),
(470,70,1,27,'exact','2026-03-09 13:56:35'),
(471,70,2,652,'exact','2026-03-09 13:56:35'),
(472,70,5,653,'exact','2026-03-09 13:56:35'),
(473,70,6,654,'exact','2026-03-09 13:56:35'),
(474,70,7,77,'exact','2026-03-09 13:56:35'),
(475,70,9,409,'exact','2026-03-09 13:56:35'),
(476,70,10,655,'exact','2026-03-09 13:56:35'),
(477,70,11,656,'exact','2026-03-09 13:56:35'),
(478,71,1,27,'strong','2026-03-09 13:56:35'),
(479,71,2,616,'strong','2026-03-09 13:56:35'),
(480,71,5,583,'strong','2026-03-09 13:56:35'),
(481,71,6,654,'partial','2026-03-09 13:56:35'),
(482,71,7,77,'partial','2026-03-09 13:56:35'),
(483,71,9,422,'strong','2026-03-09 13:56:35'),
(484,71,11,657,'exact','2026-03-09 13:56:35'),
(485,72,1,27,'strong','2026-03-09 13:56:35'),
(486,72,2,652,'exact','2026-03-09 13:56:35'),
(487,72,5,658,'strong','2026-03-09 13:56:36'),
(488,72,6,659,'exact','2026-03-09 13:56:36'),
(489,72,7,84,'exact','2026-03-09 13:56:36'),
(490,72,9,420,'exact','2026-03-09 13:56:36'),
(491,72,10,655,'strong','2026-03-09 13:56:36'),
(492,72,11,660,'exact','2026-03-09 13:56:36'),
(493,73,1,26,'exact','2026-03-09 13:56:36'),
(494,73,2,591,'exact','2026-03-09 13:56:36'),
(495,73,5,650,'exact','2026-03-09 13:56:36'),
(496,73,6,651,'exact','2026-03-09 13:56:36'),
(497,73,7,133,'exact','2026-03-09 13:56:36'),
(498,73,9,431,'exact','2026-03-09 13:56:36'),
(499,73,10,493,'strong','2026-03-09 13:56:36'),
(500,73,11,594,'exact','2026-03-09 13:56:36'),
(501,74,1,26,'strong','2026-03-09 13:56:36'),
(502,74,2,591,'strong','2026-03-09 13:56:36'),
(503,74,5,592,'exact','2026-03-09 13:56:36'),
(504,74,6,593,'exact','2026-03-09 13:56:36'),
(505,74,7,155,'exact','2026-03-09 13:56:36'),
(506,74,9,346,'exact','2026-03-09 13:56:36'),
(507,74,11,661,'exact','2026-03-09 13:56:36'),
(508,75,1,10,'strong','2026-03-09 13:56:36'),
(509,75,2,489,'exact','2026-03-09 13:56:36'),
(510,75,5,662,'exact','2026-03-09 13:56:36'),
(511,75,6,492,'strong','2026-03-09 13:56:36'),
(512,75,7,132,'strong','2026-03-09 13:56:36'),
(513,75,9,338,'partial','2026-03-09 13:56:36'),
(514,75,11,663,'strong','2026-03-09 13:56:36'),
(515,76,1,18,'partial','2026-03-09 13:56:36'),
(516,76,2,600,'strong','2026-03-09 13:56:36'),
(517,76,5,601,'partial','2026-03-09 13:56:36'),
(518,76,11,479,'partial','2026-03-09 13:56:36'),
(519,77,1,31,'strong','2026-03-09 13:56:36'),
(520,77,2,587,'exact','2026-03-09 13:56:36'),
(521,77,5,588,'exact','2026-03-09 13:56:36'),
(522,77,6,589,'exact','2026-03-09 13:56:36'),
(523,77,7,86,'exact','2026-03-09 13:56:36'),
(524,77,9,288,'strong','2026-03-09 13:56:36'),
(525,77,11,590,'strong','2026-03-09 13:56:36'),
(526,78,1,34,'strong','2026-03-09 13:56:36'),
(527,78,2,664,'exact','2026-03-09 13:56:36'),
(528,78,5,665,'strong','2026-03-09 13:56:36'),
(529,78,11,479,'partial','2026-03-09 13:56:36'),
(530,79,1,27,'partial','2026-03-09 13:56:36'),
(531,79,2,666,'exact','2026-03-09 13:56:37'),
(532,79,5,658,'partial','2026-03-09 13:56:37'),
(533,79,6,667,'exact','2026-03-09 13:56:37'),
(534,79,7,83,'exact','2026-03-09 13:56:37'),
(535,79,9,417,'exact','2026-03-09 13:56:37'),
(536,79,11,668,'exact','2026-03-09 13:56:37'),
(537,80,1,13,'strong','2026-03-09 13:56:37'),
(538,80,2,669,'exact','2026-03-09 13:56:37'),
(539,80,5,670,'exact','2026-03-09 13:56:37'),
(540,80,6,477,'strong','2026-03-09 13:56:37'),
(541,80,7,135,'strong','2026-03-09 13:56:37'),
(542,80,9,434,'exact','2026-03-09 13:56:37'),
(543,80,11,671,'exact','2026-03-09 13:56:37'),
(544,81,1,14,'strong','2026-03-09 13:56:37'),
(545,81,2,669,'strong','2026-03-09 13:56:37'),
(546,81,5,670,'strong','2026-03-09 13:56:37'),
(547,81,9,434,'partial','2026-03-09 13:56:37'),
(548,81,11,672,'exact','2026-03-09 13:56:37'),
(549,82,1,28,'exact','2026-03-09 13:56:37'),
(550,82,2,673,'exact','2026-03-09 13:56:37'),
(551,82,5,674,'exact','2026-03-09 13:56:37'),
(552,82,6,675,'exact','2026-03-09 13:56:37'),
(553,82,7,106,'exact','2026-03-09 13:56:37'),
(554,82,9,468,'exact','2026-03-09 13:56:37'),
(555,82,10,676,'exact','2026-03-09 13:56:37'),
(556,82,11,677,'exact','2026-03-09 13:56:37'),
(557,83,1,28,'strong','2026-03-09 13:56:37'),
(558,83,2,678,'exact','2026-03-09 13:56:37'),
(559,83,5,679,'exact','2026-03-09 13:56:38'),
(560,83,6,675,'strong','2026-03-09 13:56:38'),
(561,83,7,106,'strong','2026-03-09 13:56:38'),
(562,83,11,680,'exact','2026-03-09 13:56:38'),
(563,84,1,27,'exact','2026-03-09 13:56:38'),
(564,84,2,678,'strong','2026-03-09 13:56:38'),
(565,84,5,653,'exact','2026-03-09 13:56:38'),
(566,84,6,681,'strong','2026-03-09 13:56:38'),
(567,84,7,107,'strong','2026-03-09 13:56:38'),
(568,84,9,422,'strong','2026-03-09 13:56:38'),
(569,84,11,682,'exact','2026-03-09 13:56:38'),
(570,85,1,28,'exact','2026-03-09 13:56:38'),
(571,85,2,683,'exact','2026-03-09 13:56:38'),
(572,85,5,684,'exact','2026-03-09 13:56:38'),
(573,85,6,681,'exact','2026-03-09 13:56:38'),
(574,85,7,107,'exact','2026-03-09 13:56:38'),
(575,85,9,471,'strong','2026-03-09 13:56:38'),
(576,85,11,685,'exact','2026-03-09 13:56:38'),
(577,86,1,29,'strong','2026-03-09 13:56:38'),
(578,86,2,686,'exact','2026-03-09 13:56:38'),
(579,86,5,687,'exact','2026-03-09 13:56:38'),
(580,86,6,681,'partial','2026-03-09 13:56:38'),
(581,86,7,107,'partial','2026-03-09 13:56:38'),
(582,86,11,688,'exact','2026-03-09 13:56:38'),
(583,87,1,29,'exact','2026-03-09 13:56:38'),
(584,87,2,683,'strong','2026-03-09 13:56:38'),
(585,87,5,689,'exact','2026-03-09 13:56:38'),
(586,87,6,681,'strong','2026-03-09 13:56:38'),
(587,87,7,107,'strong','2026-03-09 13:56:38'),
(588,87,9,469,'strong','2026-03-09 13:56:38'),
(589,87,11,677,'partial','2026-03-09 13:56:38'),
(590,88,1,29,'strong','2026-03-09 13:56:38'),
(591,88,2,683,'strong','2026-03-09 13:56:38'),
(592,88,5,689,'strong','2026-03-09 13:56:38'),
(593,88,9,474,'exact','2026-03-09 13:56:38'),
(594,88,10,690,'exact','2026-03-09 13:56:38'),
(595,89,1,30,'exact','2026-03-09 13:56:38'),
(596,89,2,691,'exact','2026-03-09 13:56:39'),
(597,89,5,692,'exact','2026-03-09 13:56:39'),
(598,89,6,693,'exact','2026-03-09 13:56:39'),
(599,89,7,108,'exact','2026-03-09 13:56:39'),
(600,89,9,694,'strong','2026-03-09 13:56:39'),
(601,89,11,695,'exact','2026-03-09 13:56:39'),
(602,90,1,28,'strong','2026-03-09 13:56:39'),
(603,90,2,673,'strong','2026-03-09 13:56:39'),
(604,90,5,696,'exact','2026-03-09 13:56:39'),
(605,90,6,675,'strong','2026-03-09 13:56:39'),
(606,90,7,106,'strong','2026-03-09 13:56:39'),
(607,90,9,470,'exact','2026-03-09 13:56:39'),
(608,90,11,677,'strong','2026-03-09 13:56:39'),
(609,91,1,28,'strong','2026-03-09 13:56:39'),
(610,91,2,673,'strong','2026-03-09 13:56:39'),
(611,91,5,697,'exact','2026-03-09 13:56:39'),
(612,91,6,693,'strong','2026-03-09 13:56:39'),
(613,91,7,108,'strong','2026-03-09 13:56:39'),
(614,91,9,472,'exact','2026-03-09 13:56:39'),
(615,91,11,698,'exact','2026-03-09 13:56:39'),
(616,92,1,33,'exact','2026-03-09 13:56:39'),
(617,92,2,699,'exact','2026-03-09 13:56:39'),
(618,92,5,700,'exact','2026-03-09 13:56:39'),
(619,92,6,488,'partial','2026-03-09 13:56:39'),
(620,92,9,460,'exact','2026-03-09 13:56:39'),
(621,92,10,701,'exact','2026-03-09 13:56:39'),
(622,92,11,702,'exact','2026-03-09 13:56:39'),
(623,93,1,33,'strong','2026-03-09 13:56:39'),
(624,93,2,703,'exact','2026-03-09 13:56:39'),
(625,93,5,704,'exact','2026-03-09 13:56:39'),
(626,93,9,462,'exact','2026-03-09 13:56:39'),
(627,93,10,705,'exact','2026-03-09 13:56:40'),
(628,93,11,706,'exact','2026-03-09 13:56:40'),
(629,94,1,33,'strong','2026-03-09 13:56:40'),
(630,94,2,707,'exact','2026-03-09 13:56:40'),
(631,94,5,708,'exact','2026-03-09 13:56:40'),
(632,94,9,464,'exact','2026-03-09 13:56:40'),
(633,94,11,709,'exact','2026-03-09 13:56:40'),
(634,95,1,33,'strong','2026-03-09 13:56:40'),
(635,95,2,710,'exact','2026-03-09 13:56:40'),
(636,95,5,711,'exact','2026-03-09 13:56:40'),
(637,95,5,712,'strong','2026-03-09 13:56:40'),
(638,95,6,614,'partial','2026-03-09 13:56:40'),
(639,95,11,713,'strong','2026-03-09 13:56:40'),
(640,96,1,33,'strong','2026-03-09 13:56:40'),
(641,96,2,710,'strong','2026-03-09 13:56:40'),
(642,96,5,711,'strong','2026-03-09 13:56:40'),
(643,96,9,465,'strong','2026-03-09 13:56:40'),
(644,96,11,702,'partial','2026-03-09 13:56:40'),
(645,97,1,18,'strong','2026-03-09 13:56:40'),
(646,97,2,703,'strong','2026-03-09 13:56:40'),
(647,97,5,714,'exact','2026-03-09 13:56:40'),
(648,97,6,511,'partial','2026-03-09 13:56:40'),
(649,97,9,463,'strong','2026-03-09 13:56:40'),
(650,97,11,715,'exact','2026-03-09 13:56:40'),
(651,98,1,33,'strong','2026-03-09 13:56:40'),
(652,98,2,707,'strong','2026-03-09 13:56:40'),
(653,98,5,708,'strong','2026-03-09 13:56:40'),
(654,98,9,464,'strong','2026-03-09 13:56:40'),
(655,99,1,33,'partial','2026-03-09 13:56:40'),
(656,99,2,710,'partial','2026-03-09 13:56:40'),
(657,99,5,711,'strong','2026-03-09 13:56:40'),
(658,99,9,465,'partial','2026-03-09 13:56:40'),
(659,100,1,29,'partial','2026-03-09 13:56:40'),
(660,100,2,703,'strong','2026-03-09 13:56:40'),
(661,100,5,704,'partial','2026-03-09 13:56:40'),
(662,100,9,462,'strong','2026-03-09 13:56:40'),
(663,100,10,701,'partial','2026-03-09 13:56:40'),
(664,101,1,33,'strong','2026-03-09 13:56:40'),
(665,101,2,716,'exact','2026-03-09 13:56:40'),
(666,101,5,700,'strong','2026-03-09 13:56:40'),
(667,101,9,461,'strong','2026-03-09 13:56:40'),
(668,101,11,702,'strong','2026-03-09 13:56:40'),
(669,102,1,21,'exact','2026-03-09 13:56:40'),
(670,102,2,717,'exact','2026-03-09 13:56:41'),
(671,102,5,538,'strong','2026-03-09 13:56:41'),
(672,102,6,718,'exact','2026-03-09 13:56:41'),
(673,102,7,126,'exact','2026-03-09 13:56:41'),
(674,102,9,392,'exact','2026-03-09 13:56:41'),
(675,102,10,719,'exact','2026-03-09 13:56:41'),
(676,102,11,479,'partial','2026-03-09 13:56:41'),
(677,103,1,21,'exact','2026-03-09 13:56:41'),
(678,103,2,720,'exact','2026-03-09 13:56:41'),
(679,103,5,721,'strong','2026-03-09 13:56:41'),
(680,103,6,722,'exact','2026-03-09 13:56:41'),
(681,103,7,127,'exact','2026-03-09 13:56:41'),
(682,103,9,392,'strong','2026-03-09 13:56:41'),
(683,103,10,723,'exact','2026-03-09 13:56:41'),
(684,104,1,34,'strong','2026-03-09 13:56:41'),
(685,104,2,720,'strong','2026-03-09 13:56:41'),
(686,104,5,721,'exact','2026-03-09 13:56:41'),
(687,104,6,724,'exact','2026-03-09 13:56:41'),
(688,104,7,128,'exact','2026-03-09 13:56:41'),
(689,104,9,392,'partial','2026-03-09 13:56:41'),
(690,104,10,723,'strong','2026-03-09 13:56:41'),
(691,105,1,21,'strong','2026-03-09 13:56:41'),
(692,105,2,725,'exact','2026-03-09 13:56:41'),
(693,105,5,538,'partial','2026-03-09 13:56:41'),
(694,105,6,724,'strong','2026-03-09 13:56:41'),
(695,105,7,128,'strong','2026-03-09 13:56:41'),
(696,105,9,400,'exact','2026-03-09 13:56:41'),
(697,105,10,726,'strong','2026-03-09 13:56:41'),
(698,106,1,21,'strong','2026-03-09 13:56:41'),
(699,106,2,727,'exact','2026-03-09 13:56:41'),
(700,106,5,728,'exact','2026-03-09 13:56:41'),
(701,106,6,722,'partial','2026-03-09 13:56:41'),
(702,106,7,127,'partial','2026-03-09 13:56:42'),
(703,106,9,393,'exact','2026-03-09 13:56:42'),
(704,107,1,22,'exact','2026-03-09 13:56:42'),
(705,107,2,729,'exact','2026-03-09 13:56:42'),
(706,107,5,562,'exact','2026-03-09 13:56:42'),
(707,107,6,563,'exact','2026-03-09 13:56:42'),
(708,107,7,117,'exact','2026-03-09 13:56:42'),
(709,107,9,402,'exact','2026-03-09 13:56:42'),
(710,107,10,568,'exact','2026-03-09 13:56:42'),
(711,107,11,730,'strong','2026-03-09 13:56:42'),
(712,108,1,21,'partial','2026-03-09 13:56:42'),
(713,108,2,731,'exact','2026-03-09 13:56:42'),
(714,108,5,554,'partial','2026-03-09 13:56:42'),
(715,108,9,400,'partial','2026-03-09 13:56:42'),
(716,108,11,552,'partial','2026-03-09 13:56:42'),
(717,109,1,21,'partial','2026-03-09 13:56:42'),
(718,109,2,732,'exact','2026-03-09 13:56:42'),
(719,109,5,721,'partial','2026-03-09 13:56:42'),
(720,109,9,392,'partial','2026-03-09 13:56:42'),
(721,110,1,4,'exact','2026-03-09 13:56:42'),
(722,110,2,733,'exact','2026-03-09 13:56:42'),
(723,110,5,481,'partial','2026-03-09 13:56:42'),
(724,110,6,734,'exact','2026-03-09 13:56:42'),
(725,110,7,124,'exact','2026-03-09 13:56:42'),
(726,110,9,459,'exact','2026-03-09 13:56:42'),
(727,110,10,735,'strong','2026-03-09 13:56:42'),
(728,110,11,736,'strong','2026-03-09 13:56:42'),
(729,111,1,4,'strong','2026-03-09 13:56:42'),
(730,111,2,737,'exact','2026-03-09 13:56:42'),
(731,111,5,738,'exact','2026-03-09 13:56:42'),
(732,111,6,739,'exact','2026-03-09 13:56:42'),
(733,111,7,74,'exact','2026-03-09 13:56:42'),
(734,111,9,455,'exact','2026-03-09 13:56:42'),
(735,111,10,740,'exact','2026-03-09 13:56:42'),
(736,111,11,736,'exact','2026-03-09 13:56:42'),
(737,112,1,4,'strong','2026-03-09 13:56:42'),
(738,112,2,737,'strong','2026-03-09 13:56:42'),
(739,112,5,741,'exact','2026-03-09 13:56:43'),
(740,112,6,742,'exact','2026-03-09 13:56:43'),
(741,112,7,75,'exact','2026-03-09 13:56:43'),
(742,112,9,455,'strong','2026-03-09 13:56:43'),
(743,112,11,743,'exact','2026-03-09 13:56:43'),
(744,113,1,4,'strong','2026-03-09 13:56:43'),
(745,113,2,733,'strong','2026-03-09 13:56:43'),
(746,113,5,738,'partial','2026-03-09 13:56:43'),
(747,113,6,734,'strong','2026-03-09 13:56:43'),
(748,113,7,124,'strong','2026-03-09 13:56:43'),
(749,113,9,455,'partial','2026-03-09 13:56:43'),
(750,113,10,744,'strong','2026-03-09 13:56:43'),
(751,114,1,19,'exact','2026-03-09 13:56:43'),
(752,114,2,745,'exact','2026-03-09 13:56:43'),
(753,114,5,510,'strong','2026-03-09 13:56:43'),
(754,114,6,746,'exact','2026-03-09 13:56:43'),
(755,114,7,125,'exact','2026-03-09 13:56:43'),
(756,114,9,366,'strong','2026-03-09 13:56:43'),
(757,114,10,747,'exact','2026-03-09 13:56:43'),
(758,114,11,513,'partial','2026-03-09 13:56:43'),
(759,115,1,1,'partial','2026-03-09 13:56:43'),
(760,115,2,748,'exact','2026-03-09 13:56:43'),
(761,115,5,476,'partial','2026-03-09 13:56:43'),
(762,115,9,449,'strong','2026-03-09 13:56:43'),
(763,115,10,749,'partial','2026-03-09 13:56:43'),
(764,115,11,736,'partial','2026-03-09 13:56:43'),
(765,116,1,4,'strong','2026-03-09 13:56:43'),
(766,116,2,750,'exact','2026-03-09 13:56:43'),
(767,116,5,476,'partial','2026-03-09 13:56:43'),
(768,116,6,734,'partial','2026-03-09 13:56:43'),
(769,116,9,462,'partial','2026-03-09 13:56:43'),
(770,116,10,751,'strong','2026-03-09 13:56:43'),
(771,117,1,5,'exact','2026-03-09 13:56:43'),
(772,117,2,752,'exact','2026-03-09 13:56:44'),
(773,117,5,499,'partial','2026-03-09 13:56:44'),
(774,117,9,455,'partial','2026-03-09 13:56:44'),
(775,117,10,505,'strong','2026-03-09 13:56:44'),
(776,118,1,4,'partial','2026-03-09 13:56:44'),
(777,118,2,737,'partial','2026-03-09 13:56:44'),
(778,118,5,738,'partial','2026-03-09 13:56:44'),
(779,118,9,455,'partial','2026-03-09 13:56:44'),
(780,118,11,736,'partial','2026-03-09 13:56:44'),
(781,119,1,4,'strong','2026-03-09 13:56:44'),
(782,119,2,733,'strong','2026-03-09 13:56:44'),
(783,119,5,481,'partial','2026-03-09 13:56:44'),
(784,119,6,734,'strong','2026-03-09 13:56:44'),
(785,119,7,124,'strong','2026-03-09 13:56:44'),
(786,119,9,459,'strong','2026-03-09 13:56:44'),
(787,119,10,744,'strong','2026-03-09 13:56:44'),
(788,120,1,35,'exact','2026-03-09 13:56:44'),
(789,120,2,753,'exact','2026-03-09 13:56:44'),
(790,120,5,754,'exact','2026-03-09 13:56:44'),
(791,120,9,468,'partial','2026-03-09 13:56:44'),
(792,120,10,755,'exact','2026-03-09 13:56:44'),
(793,120,11,677,'partial','2026-03-09 13:56:44'),
(794,121,1,35,'exact','2026-03-09 13:56:44'),
(795,121,2,756,'exact','2026-03-09 13:56:44'),
(796,121,5,757,'exact','2026-03-09 13:56:44'),
(797,121,9,468,'partial','2026-03-09 13:56:44'),
(798,121,10,758,'exact','2026-03-09 13:56:44'),
(799,122,1,34,'exact','2026-03-09 13:56:44'),
(800,122,2,753,'strong','2026-03-09 13:56:44'),
(801,122,5,759,'exact','2026-03-09 13:56:44'),
(802,122,10,760,'exact','2026-03-09 13:56:44'),
(803,123,1,35,'strong','2026-03-09 13:56:44'),
(804,123,2,756,'strong','2026-03-09 13:56:44'),
(805,123,5,761,'exact','2026-03-09 13:56:44'),
(806,123,9,468,'partial','2026-03-09 13:56:44'),
(807,123,10,758,'strong','2026-03-09 13:56:44'),
(808,124,1,35,'exact','2026-03-09 13:56:44'),
(809,124,2,576,'exact','2026-03-09 13:56:44'),
(810,124,5,577,'exact','2026-03-09 13:56:44'),
(811,124,6,578,'exact','2026-03-09 13:56:44'),
(812,124,7,123,'exact','2026-03-09 13:56:44'),
(813,124,9,404,'partial','2026-03-09 13:56:44'),
(814,124,10,579,'exact','2026-03-09 13:56:44'),
(815,124,11,580,'exact','2026-03-09 13:56:44'),
(816,125,1,35,'partial','2026-03-09 13:56:44'),
(817,125,2,753,'partial','2026-03-09 13:56:44'),
(818,125,5,762,'exact','2026-03-09 13:56:45'),
(819,125,5,763,'strong','2026-03-09 13:56:45'),
(820,125,9,469,'partial','2026-03-09 13:56:45'),
(821,126,1,35,'strong','2026-03-09 13:56:45'),
(822,126,2,756,'strong','2026-03-09 13:56:45'),
(823,126,5,764,'exact','2026-03-09 13:56:45'),
(824,126,10,765,'exact','2026-03-09 13:56:45'),
(825,127,1,36,'exact','2026-03-09 13:56:45'),
(826,127,2,756,'exact','2026-03-09 13:56:45'),
(827,127,5,766,'exact','2026-03-09 13:56:45'),
(828,127,9,470,'strong','2026-03-09 13:56:45'),
(829,127,10,767,'exact','2026-03-09 13:56:45'),
(830,128,1,35,'partial','2026-03-09 13:56:45'),
(831,128,2,753,'partial','2026-03-09 13:56:45'),
(832,128,5,754,'partial','2026-03-09 13:56:45'),
(833,128,10,755,'partial','2026-03-09 13:56:45'),
(834,129,1,33,'strong','2026-03-09 13:56:45'),
(835,129,2,710,'strong','2026-03-09 13:56:45'),
(836,129,5,711,'strong','2026-03-09 13:56:45'),
(837,129,5,754,'partial','2026-03-09 13:56:45'),
(838,129,9,464,'partial','2026-03-09 13:56:45'),
(839,130,1,24,'strong','2026-03-09 13:56:45'),
(840,130,2,553,'exact','2026-03-09 13:56:45'),
(841,130,5,554,'strong','2026-03-09 13:56:45'),
(842,130,6,768,'exact','2026-03-09 13:56:45'),
(843,130,7,149,'exact','2026-03-09 13:56:45'),
(844,130,9,313,'exact','2026-03-09 13:56:45'),
(845,130,11,557,'strong','2026-03-09 13:56:45'),
(846,131,1,24,'strong','2026-03-09 13:56:45'),
(847,131,2,553,'strong','2026-03-09 13:56:45'),
(848,131,5,554,'partial','2026-03-09 13:56:45'),
(849,131,6,768,'strong','2026-03-09 13:56:45'),
(850,131,7,149,'strong','2026-03-09 13:56:45'),
(851,131,9,313,'strong','2026-03-09 13:56:45'),
(852,132,1,24,'strong','2026-03-09 13:56:45'),
(853,132,2,553,'strong','2026-03-09 13:56:45'),
(854,132,5,554,'partial','2026-03-09 13:56:45'),
(855,132,6,769,'exact','2026-03-09 13:56:45'),
(856,132,7,148,'exact','2026-03-09 13:56:45'),
(857,132,9,770,'exact','2026-03-09 13:56:45'),
(858,132,11,557,'partial','2026-03-09 13:56:45'),
(859,133,1,24,'strong','2026-03-09 13:56:45'),
(860,133,2,553,'strong','2026-03-09 13:56:46'),
(861,133,5,554,'partial','2026-03-09 13:56:46'),
(862,133,6,769,'strong','2026-03-09 13:56:46'),
(863,133,7,148,'strong','2026-03-09 13:56:46'),
(864,133,9,315,'exact','2026-03-09 13:56:46'),
(865,134,1,24,'partial','2026-03-09 13:56:46'),
(866,134,2,553,'partial','2026-03-09 13:56:46'),
(867,134,5,558,'partial','2026-03-09 13:56:46'),
(868,134,9,313,'partial','2026-03-09 13:56:46'),
(869,134,11,560,'partial','2026-03-09 13:56:46'),
(870,135,1,24,'exact','2026-03-09 13:56:46'),
(871,135,2,553,'strong','2026-03-09 13:56:46'),
(872,135,5,558,'strong','2026-03-09 13:56:46'),
(873,135,6,559,'strong','2026-03-09 13:56:46'),
(874,135,7,146,'strong','2026-03-09 13:56:46'),
(875,135,9,317,'exact','2026-03-09 13:56:46'),
(876,135,11,560,'exact','2026-03-09 13:56:46'),
(877,136,1,24,'strong','2026-03-09 13:56:46'),
(878,136,2,553,'exact','2026-03-09 13:56:46'),
(879,136,5,554,'partial','2026-03-09 13:56:46'),
(880,136,6,768,'strong','2026-03-09 13:56:46'),
(881,136,7,149,'strong','2026-03-09 13:56:46'),
(882,136,9,311,'strong','2026-03-09 13:56:46'),
(883,137,1,24,'partial','2026-03-09 13:56:46'),
(884,137,2,553,'partial','2026-03-09 13:56:46'),
(885,137,5,554,'partial','2026-03-09 13:56:46'),
(886,137,9,313,'partial','2026-03-09 13:56:46'),
(887,138,1,9,'strong','2026-03-09 13:56:46'),
(888,138,2,494,'exact','2026-03-09 13:56:46'),
(889,138,5,495,'exact','2026-03-09 13:56:46'),
(890,138,6,477,'strong','2026-03-09 13:56:46'),
(891,138,7,135,'strong','2026-03-09 13:56:46'),
(892,138,9,443,'strong','2026-03-09 13:56:46'),
(894,138,10,497,'strong','2026-03-09 13:56:46'),
(895,139,1,13,'exact','2026-03-09 13:56:46'),
(896,139,2,501,'exact','2026-03-09 13:56:46'),
(897,139,5,771,'exact','2026-03-09 13:56:46'),
(898,139,6,477,'exact','2026-03-09 13:56:46'),
(899,139,7,135,'exact','2026-03-09 13:56:46'),
(900,139,9,451,'strong','2026-03-09 13:56:46'),
(902,139,10,772,'exact','2026-03-09 13:56:46'),
(903,140,1,13,'strong','2026-03-09 13:56:46'),
(904,140,2,501,'strong','2026-03-09 13:56:46'),
(905,140,5,771,'strong','2026-03-09 13:56:46'),
(906,140,6,773,'exact','2026-03-09 13:56:46'),
(907,140,7,137,'exact','2026-03-09 13:56:47'),
(908,140,9,426,'strong','2026-03-09 13:56:47'),
(910,141,1,13,'strong','2026-03-09 13:56:47'),
(911,141,2,774,'exact','2026-03-09 13:56:47'),
(912,141,5,502,'exact','2026-03-09 13:56:47'),
(913,141,6,488,'strong','2026-03-09 13:56:47'),
(914,141,7,138,'strong','2026-03-09 13:56:47'),
(915,141,9,451,'strong','2026-03-09 13:56:47'),
(917,142,1,14,'exact','2026-03-09 13:56:47'),
(918,142,2,501,'exact','2026-03-09 13:56:47'),
(919,142,5,775,'exact','2026-03-09 13:56:47'),
(920,142,9,451,'partial','2026-03-09 13:56:47'),
(922,142,10,772,'strong','2026-03-09 13:56:47'),
(923,143,1,14,'strong','2026-03-09 13:56:47'),
(924,143,2,774,'strong','2026-03-09 13:56:47'),
(925,143,5,776,'exact','2026-03-09 13:56:47'),
(926,143,6,777,'exact','2026-03-09 13:56:47'),
(927,143,7,136,'exact','2026-03-09 13:56:47'),
(928,143,9,451,'partial','2026-03-09 13:56:47'),
(929,144,1,9,'partial','2026-03-09 13:56:47'),
(930,144,2,494,'strong','2026-03-09 13:56:47'),
(931,144,5,495,'strong','2026-03-09 13:56:47'),
(932,144,9,443,'partial','2026-03-09 13:56:47'),
(934,145,1,44,'strong','2026-03-09 13:56:47'),
(935,145,2,566,'exact','2026-03-09 13:56:47'),
(936,145,5,495,'strong','2026-03-09 13:56:47'),
(937,145,9,443,'partial','2026-03-09 13:56:47'),
(938,145,10,570,'exact','2026-03-09 13:56:47'),
(939,146,1,9,'strong','2026-03-09 13:56:47'),
(940,146,2,494,'strong','2026-03-09 13:56:47'),
(941,146,5,495,'strong','2026-03-09 13:56:47'),
(942,146,6,488,'strong','2026-03-09 13:56:48'),
(943,146,7,138,'strong','2026-03-09 13:56:48'),
(944,146,9,443,'strong','2026-03-09 13:56:48'),
(946,146,10,497,'strong','2026-03-09 13:56:48'),
(947,2,3,999,'strong','2026-03-09 20:00:15'),
(948,5,3,999,'strong','2026-03-09 20:00:15'),
(949,6,3,1000,'strong','2026-03-09 20:00:15'),
(950,9,3,1001,'strong','2026-03-09 20:00:15'),
(951,12,3,1000,'partial','2026-03-09 20:00:15'),
(952,14,3,1002,'exact','2026-03-09 20:00:15'),
(953,16,3,1003,'exact','2026-03-09 20:00:15'),
(954,17,3,1004,'exact','2026-03-09 20:00:15'),
(955,23,3,1002,'strong','2026-03-09 20:00:15'),
(956,38,3,1000,'strong','2026-03-09 20:00:15'),
(957,66,3,1005,'exact','2026-03-09 20:00:15'),
(958,67,3,1006,'strong','2026-03-09 20:00:15'),
(959,138,3,999,'exact','2026-03-09 20:00:15'),
(960,139,3,1001,'exact','2026-03-09 20:00:15'),
(961,140,3,1001,'strong','2026-03-09 20:00:15'),
(962,141,3,1001,'strong','2026-03-09 20:00:15'),
(963,142,3,1001,'strong','2026-03-09 20:00:16'),
(964,144,3,1000,'strong','2026-03-09 20:00:16'),
(965,146,3,999,'strong','2026-03-09 20:00:16'),
(966,147,12,1008,'exact','2026-03-13 03:52:56'),
(967,147,12,1009,'exact','2026-03-13 03:52:56'),
(968,147,12,1011,'strong','2026-03-13 03:52:56'),
(969,148,12,1010,'strong','2026-03-13 03:52:56'),
(970,148,12,1013,'exact','2026-03-13 03:52:56'),
(971,148,12,1014,'exact','2026-03-13 03:52:56'),
(972,149,12,1016,'exact','2026-03-13 03:52:56'),
(973,149,12,1018,'exact','2026-03-13 03:52:56'),
(974,149,12,1021,'strong','2026-03-13 03:52:56'),
(975,150,12,1017,'exact','2026-03-13 03:52:56'),
(976,151,12,1020,'exact','2026-03-13 03:52:56'),
(977,152,12,1023,'exact','2026-03-13 03:52:56'),
(978,152,12,1024,'exact','2026-03-13 03:52:56'),
(979,152,12,1025,'exact','2026-03-13 03:52:56'),
(980,153,12,1027,'exact','2026-03-13 03:52:56'),
(981,153,12,1028,'exact','2026-03-13 03:52:56'),
(982,154,12,1030,'exact','2026-03-13 03:52:56'),
(983,154,12,1031,'exact','2026-03-13 03:52:56'),
(984,155,12,1033,'exact','2026-03-13 03:52:56'),
(985,155,12,1035,'exact','2026-03-13 03:52:56'),
(986,155,12,1036,'strong','2026-03-13 03:52:56'),
(987,156,12,1034,'exact','2026-03-13 03:52:56'),
(988,156,12,1038,'exact','2026-03-13 03:52:56'),
(989,157,12,1037,'exact','2026-03-13 03:52:56'),
(990,158,12,1040,'exact','2026-03-13 03:52:56'),
(991,158,12,1041,'exact','2026-03-13 03:52:56'),
(992,158,12,1042,'strong','2026-03-13 03:52:56'),
(993,159,12,1044,'exact','2026-03-13 03:52:56'),
(994,159,12,1045,'exact','2026-03-13 03:52:56'),
(995,159,12,1046,'strong','2026-03-13 03:52:56'),
(996,160,12,1047,'exact','2026-03-13 03:52:56'),
(997,160,12,1048,'exact','2026-03-13 03:52:56'),
(998,161,12,1050,'strong','2026-03-13 03:52:56'),
(999,161,12,1051,'strong','2026-03-13 03:52:56'),
(1000,161,12,1053,'exact','2026-03-13 03:52:56'),
(1001,161,12,1054,'exact','2026-03-13 03:52:56'),
(1002,162,12,1056,'exact','2026-03-13 03:52:56'),
(1003,162,12,1057,'exact','2026-03-13 03:52:56'),
(1004,162,12,1058,'exact','2026-03-13 03:52:56'),
(1005,163,12,1060,'exact','2026-03-13 03:52:56'),
(1006,163,12,1062,'strong','2026-03-13 03:52:56'),
(1007,163,12,1064,'exact','2026-03-13 03:52:56'),
(1008,164,12,1061,'exact','2026-03-13 03:52:56'),
(1009,164,12,1063,'exact','2026-03-13 03:52:56'),
(1010,164,12,1072,'strong','2026-03-13 03:52:56'),
(1011,165,12,1065,'exact','2026-03-13 03:52:56'),
(1012,166,12,1066,'exact','2026-03-13 03:52:56'),
(1013,166,12,1067,'exact','2026-03-13 03:52:56'),
(1014,166,12,1068,'exact','2026-03-13 03:52:56'),
(1015,167,12,1069,'exact','2026-03-13 03:52:56'),
(1016,167,12,1070,'exact','2026-03-13 03:52:56'),
(1017,167,12,1071,'exact','2026-03-13 03:52:56'),
(1018,168,12,1074,'exact','2026-03-13 03:52:56'),
(1019,168,12,1075,'exact','2026-03-13 03:52:56'),
(1020,168,12,1076,'exact','2026-03-13 03:52:56'),
(1021,168,12,1078,'strong','2026-03-13 03:52:56'),
(1022,168,12,1079,'strong','2026-03-13 03:52:56'),
(1023,168,12,1080,'strong','2026-03-13 03:52:56'),
(1024,169,12,1082,'exact','2026-03-13 03:52:56'),
(1025,169,12,1083,'exact','2026-03-13 03:52:56'),
(1026,169,12,1084,'exact','2026-03-13 03:52:56'),
(1027,169,12,1085,'exact','2026-03-13 03:52:56'),
(1028,170,12,1087,'exact','2026-03-13 03:52:56'),
(1029,170,12,1088,'exact','2026-03-13 03:52:56'),
(1030,171,12,1089,'exact','2026-03-13 03:52:56'),
(1031,171,12,1090,'exact','2026-03-13 03:52:56'),
(1032,172,12,1092,'exact','2026-03-13 03:52:56'),
(1033,172,12,1093,'exact','2026-03-13 03:52:56'),
(1034,173,12,1095,'exact','2026-03-13 03:52:56'),
(1035,173,12,1096,'exact','2026-03-13 03:52:56'),
(1036,173,12,1097,'exact','2026-03-13 03:52:56'),
(1037,1,12,1009,'related','2026-03-13 03:52:56'),
(1038,2,12,1016,'related','2026-03-13 03:52:56'),
(1039,3,12,1018,'related','2026-03-13 03:52:56'),
(1040,5,12,1010,'related','2026-03-13 03:52:56'),
(1041,6,12,1008,'related','2026-03-13 03:52:56'),
(1042,10,12,1023,'related','2026-03-13 03:52:56'),
(1043,31,12,1069,'related','2026-03-13 03:52:56'),
(1044,33,12,1033,'related','2026-03-13 03:52:56'),
(1045,92,12,1030,'related','2026-03-13 03:52:56'),
(1046,94,12,1092,'related','2026-03-13 03:52:56'),
(1047,95,12,1050,'related','2026-03-13 03:52:56'),
(1048,111,12,1017,'related','2026-03-13 03:52:56'),
(1049,112,12,1017,'related','2026-03-13 03:52:56'),
(1050,82,12,1097,'related','2026-03-13 03:52:56'),
(1051,138,12,1008,'related','2026-03-13 03:52:56'),
(1052,141,12,1012,'related','2026-03-13 03:52:56');
UNLOCK TABLES;

-- =============================================================================
-- SECTION 7: Seed Data - Email Templates
-- =============================================================================

LOCK TABLES `email_templates` WRITE;
INSERT IGNORE INTO `email_templates` (`id`, `template_category`, `template_key`, `assessment_template_id`, `display_name`, `email_subject`, `email_body_html`, `email_body_text`, `available_variables`, `is_active`, `created_at`, `updated_at`) VALUES (11,'vendor','assessment_request',NULL,'Assessment Request (Default)','Security Assessment Request{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr>\n            <td align=\"center\">\n                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                    <tr>\n                        <td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}\n                            <h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1>\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"background-color: #D1ECF1; border-left: 4px solid #17A2B8; padding: 15px 30px;\">\n                            <p style=\"margin: 0; color: #0C5460; font-size: 16px; font-weight: bold;\">Security Assessment Request</p>\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding: 30px;\">\n                            <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">{{greeting}}</p>\n                            <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">As part of our vendor risk management process, we require <strong>{{vendor_name}}</strong> to complete a security assessment.</p>\n                            <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Please click the button below to access and complete the assessment:</p>\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{assessment_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Assessment</a>\n                                    </td>\n                                </tr>\n                            </table>\n                            <p style=\"margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;\">This link will expire on {{expires_at}}. If you have any questions, please contact us.</p>\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"background-color: {{footer_color}}; padding: 20px 30px;\">\n                            <p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>','{{greeting}}\n\nAs part of our vendor risk management process, we require {{vendor_name}} to complete a security assessment.\n\nPlease click the link below to access and complete the assessment:\n\n{{assessment_url}}\n\nThis link will expire on {{expires_at}}. If you have any questions, please contact us.\n\nThank you,\nThird Party Risk Management Team','[\"vendor_name\",\"assessment_name\",\"assessment_url\",\"greeting\",\"contact_name\",\"full_name\",\"from_name\",\"company_name\",\"expires_at\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-11 21:21:35'),
(12,'vendor','assessment_7_days_before',NULL,'Assessment Reminder - 7 Days','Reminder: Assessment Due in 7 Days{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}','<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\r\n<tbody>\r\n<tr>\r\n<td align=\"center\">\r\n<table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\r\n<tbody>\r\n<tr>\r\n<td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}\r\n<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"background-color: #fff3cd; border-left: 4px solid #FFC107; padding: 15px 30px;\">\r\n<p style=\"margin: 0; color: #856404; font-size: 16px; font-weight: bold;\">Assessment Due in 7 Days</p>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"padding: 30px;\">\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">{{greeting}}</p>\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">This is a reminder that the security assessment for <strong>{{vendor_name}}</strong> is due in <strong>{{days_left}} days</strong> on <strong>{{expires_at}}</strong>.</p>\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Please click the button below to complete the assessment before it expires:</p>\r\n<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\">\r\n<tbody>\r\n<tr>\r\n<td align=\"center\"><a href=\"{{assessment_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Assessment</a></td>\r\n</tr>\r\n</tbody>\r\n</table>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"background-color: {{footer_color}}; padding: 20px 30px;\">\r\n<p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated reminder from {{system_title}}. Please do not reply to this email.</p>\r\n</td>\r\n</tr>\r\n</tbody>\r\n</table>\r\n</td>\r\n</tr>\r\n</tbody>\r\n</table>','Assessment Due in 7 Days\r\n==========================================\r\n\r\n{{greeting}}\r\n\r\nThis is a reminder that the security assessment for {{vendor_name}} is due in {{days_left}} days on {{expires_at}}.\r\n\r\nPlease click the link below to complete the assessment before it expires:\r\n\r\n{{assessment_url}}\r\n\r\n==========================================\r\nThis is an automated reminder from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"assessment_name\",\"assessment_url\",\"greeting\",\"contact_name\",\"expires_at\",\"days_left\",\"from_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-11 21:21:35'),
(13,'vendor','assessment_3_days_before',NULL,'Assessment Reminder - 3 Days','Urgent: Assessment Due in 3 Days{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}','<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\r\n<tbody>\r\n<tr>\r\n<td align=\"center\">\r\n<table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\r\n<tbody>\r\n<tr>\r\n<td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}\r\n<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"background-color: #fff3cd; border-left: 4px solid #FF9800; padding: 15px 30px;\">\r\n<p style=\"margin: 0; color: #856404; font-size: 16px; font-weight: bold;\">Assessment Due in 3 Days</p>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"padding: 30px;\">\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">{{greeting}}</p>\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">This is an urgent reminder that the security assessment for <strong>{{vendor_name}}</strong> is due in <strong>{{days_left}} days</strong> on <strong>{{expires_at}}</strong>.</p>\r\n<p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Please complete it as soon as possible.:</p>\r\n<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\">\r\n<tbody>\r\n<tr>\r\n<td align=\"center\"><a href=\"{{assessment_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Assessment</a></td>\r\n</tr>\r\n</tbody>\r\n</table>\r\n</td>\r\n</tr>\r\n<tr>\r\n<td style=\"background-color: {{footer_color}}; padding: 20px 30px;\">\r\n<p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated reminder from {{system_title}}. Please do not reply to this email.</p>\r\n</td>\r\n</tr>\r\n</tbody>\r\n</table>\r\n</td>\r\n</tr>\r\n</tbody>\r\n</table>','Assessment Due in 3 Days\r\n==========================================\r\n\r\n{{greeting}}\r\n\r\nThis is an urgent reminder that the security assessment for {{vendor_name}} is due in {{days_left}} days on {{expires_at}}.\r\n\r\nPlease complete it as soon as possible.\r\n\r\n{{assessment_url}}\r\n\r\n==========================================\r\nThis is an automated reminder from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"assessment_name\",\"assessment_url\",\"greeting\",\"contact_name\",\"expires_at\",\"days_left\",\"from_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-11 21:21:35'),
(14,'vendor','assessment_expiry_day',NULL,'Assessment Reminder - Expiry Day','FINAL NOTICE: Assessment Expires Today{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #F8D7DA; border-left: 4px solid #DC3545; padding: 15px 30px;\"><p style=\"margin: 0; color: #721C24; font-size: 16px; font-weight: bold;\">Assessment Expires Today</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">{{greeting}}</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The security assessment for <strong>{{vendor_name}}</strong> expires <strong>today, {{expires_at}}</strong>. After this date, the assessment link will no longer be accessible.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Please complete it immediately:</p>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{assessment_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Assessment Now</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated reminder from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Assessment Expires Today\n==========================================\n\n{{greeting}}\n\nThe security assessment for {{vendor_name}} expires today, {{expires_at}}. After this date, the assessment link will no longer be accessible.\n\nPlease complete it immediately:\n\n{{assessment_url}}\n\n==========================================\nThis is an automated reminder from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"assessment_name\",\"assessment_url\",\"greeting\",\"contact_name\",\"expires_at\",\"days_left\",\"from_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-11 21:21:35'),
(15,'stakeholder','30_days_before',NULL,'Annual Review - 30 Day Reminder','Upcoming Vendor Review: {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #FFF3CD; border-left: 4px solid #FFC107; padding: 15px 30px;\"><p style=\"margin: 0; color: #856404; font-size: 16px; font-weight: bold;\">Annual Vendor Review Due Soon</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The annual review for <strong>{{vendor_name}}</strong> is due in 30 days on <strong>{{due_date}}</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">As the assigned stakeholder, you are required to complete the annual review, which includes:</p>\n                    <ul style=\"color: #333333; font-size: 16px; line-height: 1.8; margin: 0 0 20px 0;\"><li>Confirming your role as the current stakeholder</li><li>Reviewing and updating the scope of services</li><li>Verifying vendor contact information</li></ul>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{review_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Review Now</a></td></tr></table>\n                    <p style=\"margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;\">If you are no longer the stakeholder for this vendor, you can reassign it during the review process.</p>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p><p style=\"margin: 10px 0 0 0; color: #ffffff; font-size: 12px;\"><strong>Vendor:</strong> {{vendor_name}}</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Annual Vendor Review Due Soon\n==========================================\n\nThe annual review for {{vendor_name}} is due in 30 days on {{due_date}}.\n\nAs the assigned stakeholder, you are required to complete the annual review, which includes:\n- Confirming your role as the current stakeholder\n- Reviewing and updating the scope of services\n- Verifying vendor contact information\n\nComplete your review here:\n{{review_url}}\n\nIf you are no longer the stakeholder for this vendor, you can reassign it during the review process.\n\n==========================================\nVendor: {{vendor_name}}\n\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"review_url\",\"due_date\",\"from_name\",\"company_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-11 21:21:35'),
(16,'stakeholder','due_date',NULL,'Annual Review - Due Today','Action Required: Vendor Review Due Today - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #FFF3CD; border-left: 4px solid #FF9800; padding: 15px 30px;\"><p style=\"margin: 0; color: #856404; font-size: 16px; font-weight: bold;\">Annual Vendor Review Due Today</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The annual review for <strong>{{vendor_name}}</strong> is due today, <strong>{{due_date}}</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">As the assigned stakeholder, you are required to complete the annual review, which includes:</p>\n                    <ul style=\"color: #333333; font-size: 16px; line-height: 1.8; margin: 0 0 20px 0;\"><li>Confirming your role as the current stakeholder</li><li>Reviewing and updating the scope of services</li><li>Verifying vendor contact information</li></ul>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{review_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Review Now</a></td></tr></table>\n                    <p style=\"margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;\">If you are no longer the stakeholder for this vendor, you can reassign it during the review process.</p>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p><p style=\"margin: 10px 0 0 0; color: #ffffff; font-size: 12px;\"><strong>Vendor:</strong> {{vendor_name}}</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Annual Vendor Review Due Today\n==========================================\n\nThe annual review for {{vendor_name}} is due today, {{due_date}}.\n\nAs the assigned stakeholder, you are required to complete the annual review, which includes:\n- Confirming your role as the current stakeholder\n- Reviewing and updating the scope of services\n- Verifying vendor contact information\n\nComplete your review here:\n{{review_url}}\n\nIf you are no longer the stakeholder for this vendor, you can reassign it during the review process.\n\n==========================================\nVendor: {{vendor_name}}\n\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"review_url\",\"due_date\",\"from_name\",\"company_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-11 21:21:35'),
(17,'stakeholder','overdue',NULL,'Annual Review - Overdue Notice','OVERDUE: Vendor Review Required - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #F8D7DA; border-left: 4px solid #DC3545; padding: 15px 30px;\"><p style=\"margin: 0; color: #721C24; font-size: 16px; font-weight: bold;\">Annual Vendor Review Overdue</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The annual review for <strong>{{vendor_name}}</strong> was due on <strong>{{due_date}}</strong> and is now <strong>{{days_overdue}} days overdue</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">As the assigned stakeholder, you are required to complete the annual review, which includes:</p>\n                    <ul style=\"color: #333333; font-size: 16px; line-height: 1.8; margin: 0 0 20px 0;\"><li>Confirming your role as the current stakeholder</li><li>Reviewing and updating the scope of services</li><li>Verifying vendor contact information</li></ul>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{review_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">Complete Review Now</a></td></tr></table>\n                    <p style=\"margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;\">If you are no longer the stakeholder for this vendor, you can reassign it during the review process.</p>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p><p style=\"margin: 10px 0 0 0; color: #ffffff; font-size: 12px;\"><strong>Vendor:</strong> {{vendor_name}}</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Annual Vendor Review Overdue\n==========================================\n\nThe annual review for {{vendor_name}} was due on {{due_date}} and is now {{days_overdue}} days overdue.\n\nAs the assigned stakeholder, you are required to complete the annual review, which includes:\n- Confirming your role as the current stakeholder\n- Reviewing and updating the scope of services\n- Verifying vendor contact information\n\nComplete your review here:\n{{review_url}}\n\nIf you are no longer the stakeholder for this vendor, you can reassign it during the review process.\n\n==========================================\nVendor: {{vendor_name}}\n\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"vendor_name\",\"review_url\",\"due_date\",\"days_overdue\",\"from_name\",\"company_name\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-11 21:21:35'),
(18,'procurement','contract_expiring',NULL,'Contract Expiring Soon','Contract Expiring Soon: {{contract_name}} - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #FFF3CD; border-left: 4px solid #FFC107; padding: 15px 30px;\"><p style=\"margin: 0; color: #856404; font-size: 16px; font-weight: bold;\">Contract Expiring Soon</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The contract <strong>{{contract_name}}</strong> for vendor <strong>{{vendor_name}}</strong> is expiring in <strong>{{days_until_expiry}} days</strong> on <strong>{{expiration_date}}</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Contract type: <strong>{{contract_type}}</strong></p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Please review this contract and take appropriate action before the expiration date.</p>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{contract_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">View Contract</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Contract Expiring Soon\n==========================================\n\nThe contract {{contract_name}} for vendor {{vendor_name}} is expiring in {{days_until_expiry}} days on {{expiration_date}}.\n\nContract type: {{contract_type}}\n\nPlease review this contract and take appropriate action before the expiration date.\n\nView contract: {{contract_url}}\n\n==========================================\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"contract_name\",\"vendor_name\",\"expiration_date\",\"days_until_expiry\",\"contract_type\",\"contract_url\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-11 21:21:35'),
(19,'procurement','contract_expired',NULL,'Contract Expired','Contract Expired: {{contract_name}} - {{vendor_name}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #F8D7DA; border-left: 4px solid #DC3545; padding: 15px 30px;\"><p style=\"margin: 0; color: #721C24; font-size: 16px; font-weight: bold;\">Contract Expired</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">The contract <strong>{{contract_name}}</strong> for vendor <strong>{{vendor_name}}</strong> expired on <strong>{{expiration_date}}</strong> and is now <strong>{{days_overdue}} days overdue</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Contract type: <strong>{{contract_type}}</strong></p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Immediate action is required to renew or address this expired contract.</p>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{contract_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">View Contract</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Contract Expired\n==========================================\n\nThe contract {{contract_name}} for vendor {{vendor_name}} expired on {{expiration_date}} and is now {{days_overdue}} days overdue.\n\nContract type: {{contract_type}}\n\nImmediate action is required to renew or address this expired contract.\n\nView contract: {{contract_url}}\n\n==========================================\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"contract_name\",\"vendor_name\",\"expiration_date\",\"days_overdue\",\"contract_type\",\"contract_url\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-11 21:21:35'),
(20,'procurement','case_assigned',NULL,'Contract Case Assigned','Contract Case Assigned: {{case_title}}','<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: #D1ECF1; border-left: 4px solid #17A2B8; padding: 15px 30px;\"><p style=\"margin: 0; color: #0C5460; font-size: 16px; font-weight: bold;\">Contract Case Assigned to You</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">A contract expiration case has been assigned to you by <strong>{{assigned_by}}</strong>.</p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Case: <strong>{{case_title}}</strong></p>\n                    <p style=\"margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;\">Vendor: <strong>{{vendor_name}}</strong></p>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{case_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">View Case</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>','Contract Case Assigned to You\n==========================================\n\nA contract expiration case has been assigned to you by {{assigned_by}}.\n\nCase: {{case_title}}\nVendor: {{vendor_name}}\n\nView case: {{case_url}}\n\n==========================================\nThis is an automated notification from {{system_title}}. Please do not reply to this email.','[\"case_title\",\"vendor_name\",\"assigned_by\",\"case_url\",\"header_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]',1,'2026-03-05 17:51:05','2026-03-11 21:21:35'),
(21,'grc','task_digest',NULL,'GRC Task Assignment Digest','GRC Task Summary: {{task_count}} task(s) assigned to you','<!DOCTYPE html>\n<html>\n<head><meta charset=\"utf-8\"></head>\n<body style=\"margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;\">\n<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f3f4f6;padding:24px 0;\">\n<tr><td align=\"center\">\n<table width=\"640\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);\">\n  <!-- Header -->\n  <tr><td style=\"background:{{header_color}};padding:24px 32px;\">\n    <h1 style=\"margin:0;color:#ffffff;font-size:20px;font-weight:600;\">GRC Task Assignment Summary</h1>\n  </td></tr>\n  <!-- Body -->\n  <tr><td style=\"padding:24px 32px;\">\n    <p style=\"margin:0 0 16px;color:#374151;font-size:14px;\">Hello {{recipient_name}},</p>\n    <p style=\"margin:0 0 16px;color:#374151;font-size:14px;\">You have <strong>{{task_count}} task(s)</strong> assigned to you in the GRC Assessment module.</p>\n    \n    <!-- Task Table -->\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;margin-bottom:20px;\">\n      <tr style=\"background:#f9fafb;\">\n        <th style=\"padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;\">Ref</th>\n        <th style=\"padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;\">Task</th>\n        <th style=\"padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;\">Assessment</th>\n        <th style=\"padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;\">Priority</th>\n        <th style=\"padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;\">Due</th>\n        <th style=\"padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;\">Status</th>\n      </tr>\n      {{task_rows_html}}\n    </table>\n    <p style=\"text-align:center;margin:20px 0;\">\n      <a href=\"{{assessment_url}}\" style=\"display:inline-block;background:{{button_color}};color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:14px;font-weight:600;\">View My Tasks</a>\n    </p>\n  </td></tr>\n  <!-- Footer -->\n  <tr><td style=\"background:{{footer_color}};padding:16px 32px;text-align:center;\">\n    <p style=\"margin:0;color:#ffffff;font-size:12px;opacity:0.8;\">{{system_title}}</p>\n  </td></tr>\n</table>\n</td></tr></table>\n</body></html>','GRC Task Assignment Summary\n\nHello {{recipient_name}},\n\nYou have {{task_count}} task(s) assigned to you in the GRC Assessment module.\n\nYour Tasks:\n{{task_rows_text}}\nView your tasks: {{assessment_url}}','recipient_name, task_count, urgent_count, critical_count, high_count, overdue_count, task_rows_html, task_rows_text, assessment_url, header_color, button_color, footer_color, system_title, logo_img, company_name',1,'2026-03-11 23:51:52','2026-03-12 03:14:15');
UNLOCK TABLES;

-- Breach/Cyber Alert email template (seed only if missing)
INSERT IGNORE INTO email_templates (id, template_category, template_key, assessment_template_id, display_name, email_subject, email_body_html, email_body_text, available_variables, is_active) VALUES (23, 'breach_alert', 'breach_notification', NULL, 'Breach / Cyber Alert Notification', '{{severity}} Alert: {{title}}', '<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: {{severity_color}}; border-left: 4px solid {{severity_border}}; padding: 15px 30px;\"><p style=\"margin: 0; color: {{severity_text_color}}; font-size: 16px; font-weight: bold;\">{{severity}} Breach / Cyber Alert</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <h2 style=\"margin: 0 0 15px 0; color: #333333; font-size: 18px;\">{{title}}</h2>\n                    <table width=\"100%\" style=\"margin-bottom: 20px; font-size: 14px;\">\n                        <tr><td style=\"padding: 6px 0; color: #666; width: 140px;\"><strong>Alert Type:</strong></td><td style=\"padding: 6px 0; color: #333;\">{{alert_type}}</td></tr>\n                        <tr><td style=\"padding: 6px 0; color: #666;\"><strong>Affected Entity:</strong></td><td style=\"padding: 6px 0; color: #333;\">{{affected_entity}}</td></tr>\n                        <tr><td style=\"padding: 6px 0; color: #666;\"><strong>Entity Type:</strong></td><td style=\"padding: 6px 0; color: #333;\">{{affected_entity_type}}</td></tr>\n                        <tr><td style=\"padding: 6px 0; color: #666;\"><strong>Vendors Affected:</strong></td><td style=\"padding: 6px 0; color: #333;\">{{vendor_count}}</td></tr>\n                        <tr><td style=\"padding: 6px 0; color: #666;\"><strong>Date Detected:</strong></td><td style=\"padding: 6px 0; color: #333;\">{{detected_date}}</td></tr>\n                    </table>\n                    <div style=\"background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 15px; margin-bottom: 20px;\">\n                        <p style=\"margin: 0 0 5px; font-weight: bold; color: #374151; font-size: 14px;\">Summary</p>\n                        <p style=\"margin: 0; color: #4b5563; font-size: 14px; line-height: 1.6;\">{{summary}}</p>\n                    </div>\n                    {{#ai_analysis}}\n                    <div style=\"background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 15px; margin-bottom: 20px;\">\n                        <p style=\"margin: 0 0 5px; font-weight: bold; color: #1e40af; font-size: 14px;\">Impact Analysis</p>\n                        <p style=\"margin: 0; color: #1e3a5f; font-size: 14px; line-height: 1.6;\">{{ai_analysis}}</p>\n                    </div>\n                    {{/ai_analysis}}\n                    <div style=\"background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 15px; margin-bottom: 20px;\">\n                        <p style=\"margin: 0 0 5px; font-weight: bold; color: #166534; font-size: 14px;\">Sources</p>\n                        {{source_links_html}}\n                    </div>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 30px 0;\"><tr><td align=\"center\"><a href=\"{{alert_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">View Alert Details</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>', '{{severity}} BREACH / CYBER ALERT\n==========================================\n\n{{title}}\n\nAlert Type: {{alert_type}}\nAffected Entity: {{affected_entity}}\nEntity Type: {{affected_entity_type}}\nVendors Affected: {{vendor_count}}\nDate Detected: {{detected_date}}\n\nSummary:\n{{summary}}\n\n{{#ai_analysis}}\nImpact Analysis:\n{{ai_analysis}}\n{{/ai_analysis}}\n\nSources:\n{{source_links_text}}\n\nView alert: {{alert_url}}\n\n==========================================\nThis is an automated notification from {{system_title}}. Please do not reply to this email.', '[\"severity\",\"title\",\"alert_type\",\"affected_entity\",\"affected_entity_type\",\"vendor_count\",\"detected_date\",\"summary\",\"ai_analysis\",\"source_links_html\",\"source_links_text\",\"alert_url\",\"header_color\",\"header_font_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\",\"severity_color\",\"severity_border\",\"severity_text_color\"]', 1);

INSERT IGNORE INTO email_templates (id, template_category, template_key, assessment_template_id, display_name, email_subject, email_body_html, email_body_text, available_variables, is_active) VALUES (24, 'breach_alert', 'breach_digest', NULL, 'Breach / Cyber Alert Digest', 'Breach Alert Digest: {{alert_count}} New Alert(s)', '<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"background-color: {{severity_color}}; border-left: 4px solid {{severity_border}}; padding: 15px 30px;\"><p style=\"margin: 0; color: {{severity_text_color}}; font-size: 16px; font-weight: bold;\">Breach / Cyber Alert Digest &mdash; {{alert_count}} New Alert(s)</p></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 10px 0; color: #333; font-size: 15px; line-height: 1.6;\">A scan on <strong>{{scan_date}}</strong> detected <strong>{{alert_count}}</strong> new breach/cyber alert(s): {{severity_summary}}.</p>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; margin: 20px 0;\">\n                        <tr><td style=\"background:#f9fafb;padding:10px 15px;font-weight:bold;color:#374151;font-size:14px;border-bottom:1px solid #e5e7eb;\">Alerts</td></tr>\n                        {{alert_rows_html}}\n                    </table>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 20px 0;\"><tr><td align=\"center\"><a href=\"{{alert_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">View All Alerts</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>', 'BREACH / CYBER ALERT DIGEST\n==========================================\n\nScan Date: {{scan_date}}\nNew Alerts: {{alert_count}} ({{severity_summary}})\n\n{{alert_rows_text}}\nView all alerts: {{alert_url}}\n\n==========================================\nThis is an automated notification from {{system_title}}. Please do not reply to this email.', '["alert_count","severity_summary","alert_rows_html","alert_rows_text","alert_url","scan_date","header_color","header_font_color","button_color","footer_color","system_title","logo_img","severity_color","severity_border","severity_text_color"]', 1);

-- ---------------------------------------------------------------------------
-- De-duplicate generic (default) email templates.
--
-- The UNIQUE KEY unique_template (template_category, template_key,
-- assessment_template_id) does NOT stop duplicates for the default templates
-- because they all have assessment_template_id = NULL, and MySQL/MariaDB treat
-- NULLs as DISTINCT in a unique index. Combined with the explicit-id INSERTs
-- above, upgrading an instance that already had these templates (seeded by the
-- app under different auto-increment ids) inserts a second copy of every
-- default template instead of being ignored.
--
-- Resolve it deterministically and non-destructively: when a (category, key)
-- has more than one generic row, drop only the legacy copies that do NOT use
-- the {{system_title}} branding placeholder, and only when a branding-aware
-- copy survives. This removes the obsolete hardcoded templates while always
-- preserving the branding-aware (customizable) row. Idempotent: re-running
-- finds nothing left to delete. Portable across MySQL and MariaDB.
-- ---------------------------------------------------------------------------
DELETE legacy FROM email_templates legacy
JOIN email_templates keep
  ON keep.template_category = legacy.template_category
 AND keep.template_key = legacy.template_key
 AND keep.assessment_template_id IS NULL
 AND legacy.assessment_template_id IS NULL
 AND keep.id <> legacy.id
WHERE keep.email_body_html LIKE '%{{system_title}}%'
  AND legacy.email_body_html NOT LIKE '%{{system_title}}%';

-- =============================================================================
-- SECTION 8: Assessment Templates, Sections, and Questions
-- Seed data for ISO 27001:2022, Tier 2, Vendor Onboarding, and AI Usage
-- assessment templates. Uses INSERT IGNORE for full idempotency.
-- =============================================================================

-- Assessment Templates
INSERT IGNORE INTO assessment_templates (name, slug, description, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('ISO 27001:2022 Assessment', 'iso-27001-2022', 'Comprehensive information security management system assessment based on ISO 27001:2022 standard.', 1, 'If your organization holds a valid ISO 27001:2022 certification, you may upload it here to skip the detailed assessment. Please ensure the certificate is current and includes your organization name.', 1);

INSERT IGNORE INTO assessment_templates (name, slug, description, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('Tier 2 Vendor Assessment', 'tier-2-vendor', 'Streamlined security assessment for lower-risk vendor relationships.', 0, NULL, 1);

INSERT IGNORE INTO assessment_templates (name, slug, description, category, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('Vendor Onboarding Request', 'vendor-onboarding', 'Standard vendor onboarding intake form for collecting vendor information, data handling practices, and risk assessment details.', 'onboarding', 0, NULL, 1);

INSERT IGNORE INTO assessment_templates (name, slug, description, category, allow_certificate_upload, certificate_upload_prompt, is_active) VALUES
('AI Usage', 'ai-usage', 'Assessment for evaluating vendor AI usage, data handling practices, and third-party AI provider risks.', 'vendor_assessment', 0, NULL, 1);

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

-- Tier 2 Sections
INSERT IGNORE INTO assessment_sections (template_id, name, description, sort_order) VALUES
((SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor'), 'Company Information', 'Basic company and contact details.', 1),
((SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor'), 'Security Basics', 'Fundamental security controls and practices.', 2),
((SELECT id FROM assessment_templates WHERE slug = 'tier-2-vendor'), 'Data Handling', 'How you handle and protect data.', 3);

-- Vendor Onboarding Sections
INSERT IGNORE INTO assessment_sections (template_id, name, description, sort_order) VALUES
((SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding'), 'Vendor Information', 'Basic vendor details, contacts, and relationship information.', 1),
((SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding'), 'Data & Risk Assessment', 'Data handling practices, security controls, and impact assessment.', 2),
((SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding'), 'Additional Information', 'Any other relevant details about the vendor engagement.', 3);

-- AI Usage Sections
INSERT IGNORE INTO assessment_sections (template_id, name, description, sort_order) VALUES
((SELECT id FROM assessment_templates WHERE slug = 'ai-usage'), 'AI Usage Overview', '', 1),
((SELECT id FROM assessment_templates WHERE slug = 'ai-usage'), 'Data Handling & Privacy', '', 2),
((SELECT id FROM assessment_templates WHERE slug = 'ai-usage'), 'Third-Party & Subprocessor Risk', '', 3);

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
-- Only update rows where depends_on_question_id is not yet set
UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1) AND sort_order = 1),
    depends_on_value = 'Yes'
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1)
  AND sort_order IN (2, 3)
  AND depends_on_question_id IS NULL;

UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1) AND sort_order = 1),
    depends_on_value = 'Yes'
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2)
  AND sort_order IN (1, 4)
  AND depends_on_question_id IS NULL;

UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1) AND sort_order = 1)
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2)
  AND sort_order IN (2, 3)
  AND depends_on_question_id IS NULL;

UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2) AND sort_order = 4),
    depends_on_value = 'Yes'
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 2)
  AND sort_order IN (5, 6)
  AND depends_on_question_id IS NULL;

UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1) AND sort_order = 1)
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3)
  AND sort_order IN (1, 3)
  AND depends_on_question_id IS NULL;

UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3) AND sort_order = 1),
    depends_on_value = 'Others'
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3)
  AND sort_order = 2
  AND depends_on_question_id IS NULL;

UPDATE assessment_questions SET
    depends_on_question_id = (SELECT id FROM assessment_questions WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 1) AND sort_order = 1),
    depends_on_value = 'Yes'
WHERE section_id = (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'ai-usage') AND sort_order = 3)
  AND sort_order = 5
  AND depends_on_question_id IS NULL;


-- =============================================================================
-- SECTION 9: Field References Seed Data
-- Built-in field references for assessment template field name management.
-- Maps assessment question field_name values to vendor_onboarding_requests columns.
-- =============================================================================

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
-- SECTION 10: GRC Recommended Controls Seed Data
-- 73 recommended security controls across all security domains.
-- =============================================================================

INSERT IGNORE INTO `grc_recommended_controls` VALUES
(1,'Endpoint Security','Antivirus / Anti-Malware (AV)','Signature and heuristic-based detection of malware on endpoints','detective','technical','[\"EPS\",\"OPS\"]','CrowdStrike Falcon, Microsoft Defender, SentinelOne, Sophos',1,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(2,'Endpoint Security','Endpoint Detection & Response (EDR)','Advanced endpoint monitoring with behavioral analysis and automated response','detective','technical','[\"EPS\",\"OPS\",\"INC\"]','CrowdStrike Falcon Insight, Microsoft Defender for Endpoint, Carbon Black, SentinelOne',2,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(3,'Endpoint Security','Extended Detection & Response (XDR)','Cross-layer detection correlating endpoint, network, email, and cloud telemetry','detective','technical','[\"EPS\",\"OPS\",\"NET\",\"INC\"]','Palo Alto Cortex XDR, Microsoft 365 Defender, Trend Micro Vision One',3,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(4,'Endpoint Security','Mobile Device Management (MDM)','Centralized management of mobile devices including policy enforcement and remote wipe','preventive','technical','[\"EPS\",\"IAM\"]','Microsoft Intune, Jamf Pro, VMware Workspace ONE, Kandji',4,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(5,'Endpoint Security','Patch Management','Automated deployment of OS and application security patches','corrective','technical','[\"EPS\",\"OPS\",\"APS\"]','Microsoft WSUS/SCCM, Ivanti, ManageEngine, Automox',5,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(6,'Endpoint Security','USB / Removable Media Control','Block or restrict USB mass storage and removable media on endpoints','preventive','technical','[\"EPS\",\"DSP\"]','Microsoft Intune Device Restrictions, CrowdStrike USB Control, Ivanti Device Control',6,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(7,'Endpoint Security','Full Disk Encryption','Encrypt entire disk volumes to protect data at rest on endpoints','preventive','technical','[\"EPS\",\"DSP\",\"CRY\"]','BitLocker (Windows), FileVault (macOS), LUKS (Linux), Sophos SafeGuard',7,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(8,'Endpoint Security','Application Whitelisting / Allowlisting','Restrict execution to only approved applications','preventive','technical','[\"EPS\",\"APS\"]','Microsoft AppLocker, Carbon Black App Control, Airlock Digital',8,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(9,'Endpoint Security','Host-based Firewall','Software firewall on individual endpoints controlling inbound/outbound traffic','preventive','technical','[\"EPS\",\"NET\"]','Windows Defender Firewall, iptables/nftables, Little Snitch (macOS)',9,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(10,'Endpoint Security','Device Trust / Posture Assessment','Verify device compliance and health before granting access to resources','preventive','technical','[\"EPS\",\"IAM\",\"NET\"]','Zscaler Device Posture, CrowdStrike Zero Trust, Microsoft Conditional Access',10,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(11,'Network Security','Next-Gen Firewall (NGFW)','Layer 7 firewalls with application awareness, IPS, and threat intelligence','preventive','technical','[\"NET\"]','Palo Alto Networks, Fortinet FortiGate, Check Point, Cisco Firepower',11,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(12,'Network Security','Intrusion Detection / Prevention (IDS/IPS)','Monitor and block malicious network activity using signatures and anomaly detection','detective','technical','[\"NET\",\"OPS\"]','Snort, Suricata, Palo Alto Threat Prevention, Cisco Secure IPS',12,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(13,'Network Security','Secure Access Service Edge (SASE)','Cloud-delivered convergence of WAN and security (CASB, SWG, ZTNA, FWaaS)','preventive','technical','[\"NET\",\"IAM\",\"DSP\"]','Zscaler, Palo Alto Prisma SASE, Netskope, Cato Networks',13,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(14,'Network Security','Security Service Edge (SSE)','Cloud security stack: CASB, SWG, and ZTNA without SD-WAN','preventive','technical','[\"NET\",\"IAM\",\"DSP\"]','Zscaler ZIA/ZPA, Netskope SSE, Palo Alto Prisma Access',14,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(15,'Network Security','Zero Trust Network Access (ZTNA)','Identity and context-based access to applications replacing traditional VPN','preventive','technical','[\"NET\",\"IAM\"]','Zscaler Private Access, Cloudflare Access, Palo Alto Prisma Access, Akamai EAA',15,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(16,'Network Security','Microsegmentation','Granular network segmentation at the workload level to limit lateral movement','preventive','technical','[\"NET\"]','Illumio, VMware NSX, Guardicore (Akamai), Cisco Secure Workload',16,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(17,'Network Security','DDoS Protection','Distributed denial-of-service mitigation for infrastructure and applications','preventive','technical','[\"NET\"]','Cloudflare, Akamai Prolexic, AWS Shield, Azure DDoS Protection',17,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(18,'Network Security','DNS Security','DNS filtering and threat blocking to prevent C2 callbacks and malicious domains','preventive','technical','[\"NET\"]','Cisco Umbrella, Infoblox BloxOne, Cloudflare Gateway, Zscaler ZIA',18,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(19,'Network Security','Network Access Control (NAC)','Enforce device authentication and policy compliance before network access','preventive','technical','[\"NET\",\"IAM\",\"EPS\"]','Cisco ISE, Aruba ClearPass, Forescout, Portnox',19,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(20,'Network Security','Secure Web Gateway (SWG)','Proxy-based web filtering with SSL inspection and threat protection','preventive','technical','[\"NET\",\"DSP\"]','Zscaler ZIA, Netskope, Forcepoint, Cisco Secure Web Appliance',20,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(21,'Network Security','VPN Gateway','Encrypted tunnel for remote access to corporate network resources','preventive','technical','[\"NET\",\"IAM\"]','Cisco AnyConnect, Palo Alto GlobalProtect, Fortinet FortiClient, OpenVPN',21,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(22,'Identity & Access Management','Multi-Factor Authentication (MFA)','Require two or more verification factors for user authentication','preventive','technical','[\"IAM\"]','Microsoft Authenticator, Duo Security, Okta Verify, Google Authenticator',22,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(23,'Identity & Access Management','Phishing-Resistant MFA (FIDO2/WebAuthn)','Hardware-bound authentication resistant to phishing attacks (passwordless)','preventive','technical','[\"IAM\"]','YubiKey (FIDO2), Windows Hello for Business, Apple Passkeys, Google Titan',23,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(24,'Identity & Access Management','Privileged Access Management (PAM)','Secure, manage, and monitor privileged accounts and sessions','preventive','technical','[\"IAM\"]','CyberArk, BeyondTrust, Delinea (Thycotic), HashiCorp Vault',24,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(25,'Identity & Access Management','Single Sign-On (SSO)','Centralized authentication enabling one credential set across multiple applications','preventive','technical','[\"IAM\"]','Okta, Microsoft Entra ID, Ping Identity, OneLogin',25,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(26,'Identity & Access Management','Identity Governance & Administration (IGA)','Lifecycle management of identities including provisioning, access reviews, and certifications','preventive','technical','[\"IAM\",\"GOV\"]','SailPoint, Saviynt, Microsoft Entra Identity Governance, One Identity',26,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(27,'Identity & Access Management','Password Manager (Enterprise)','Centralized secure storage and rotation of credentials','preventive','technical','[\"IAM\"]','1Password Business, LastPass Enterprise, Keeper, Bitwarden',27,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(28,'Identity & Access Management','Cloud Infrastructure Entitlement Management (CIEM)','Discover and remediate excessive cloud permissions and entitlements','detective','technical','[\"IAM\",\"OPS\"]','Zscaler CIEM, CrowdStrike Falcon Cloud Security, Ermetic, Wiz',28,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(29,'Cloud Security','Cloud Security Posture Management (CSPM)','Continuously assess cloud infrastructure for misconfigurations and compliance violations','detective','technical','[\"OPS\",\"NET\",\"CMP\"]','Wiz, Prisma Cloud, CrowdStrike Falcon Cloud, Microsoft Defender for Cloud',29,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(30,'Cloud Security','Cloud Workload Protection Platform (CWPP)','Runtime protection for cloud workloads including containers and serverless','detective','technical','[\"EPS\",\"OPS\"]','CrowdStrike Falcon Cloud, Aqua Security, Lacework, Sysdig',30,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(31,'Cloud Security','Cloud Access Security Broker (CASB)','Visibility and control over data flowing to/from cloud applications','preventive','technical','[\"DSP\",\"NET\",\"IAM\"]','Microsoft Defender for Cloud Apps, Netskope, Zscaler CASB, Palo Alto SaaS Security',31,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(32,'Cloud Security','Cloud-Native Application Protection Platform (CNAPP)','Unified cloud security combining CSPM, CWPP, CIEM, and code security','detective','technical','[\"OPS\",\"EPS\",\"APS\"]','Wiz, Prisma Cloud, CrowdStrike Falcon Cloud, Orca Security',32,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(33,'Cloud Security','Container Security','Scanning and runtime protection for container images and orchestration platforms','detective','technical','[\"APS\",\"EPS\",\"OPS\"]','Aqua Security, Sysdig, Prisma Cloud, Snyk Container',33,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(34,'Security Operations','Security Information & Event Management (SIEM)','Centralized log collection, correlation, alerting, and security analytics','detective','technical','[\"OPS\",\"INC\"]','Microsoft Sentinel, Splunk, IBM QRadar, Elastic Security, Chronicle',34,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(35,'Security Operations','Security Orchestration, Automation & Response (SOAR)','Automate incident response playbooks and security operations workflows','corrective','technical','[\"OPS\",\"INC\"]','Palo Alto XSOAR, Splunk SOAR, Microsoft Sentinel Automation, Swimlane',35,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(36,'Security Operations','Vulnerability Management','Continuous scanning and prioritization of vulnerabilities across infrastructure','detective','technical','[\"OPS\",\"EPS\",\"NET\"]','Tenable Nessus/IO, Qualys VMDR, Rapid7 InsightVM, Microsoft Defender VM',36,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(37,'Security Operations','Continuous Threat Exposure Management (CTEM)','Proactive, continuous assessment of attack surface and threat exposure','detective','technical','[\"OPS\",\"NET\"]','XM Cyber, Pentera, Cymulate, AttackIQ',37,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(38,'Security Operations','Attack Surface Management (ASM)','Discover and monitor external-facing assets for unknown exposure','detective','technical','[\"OPS\",\"NET\"]','CrowdStrike Falcon Surface, Mandiant ASM, Microsoft Defender EASM, Shodan',38,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(39,'Security Operations','Threat Intelligence Platform (TIP)','Aggregate, analyze, and operationalize threat intelligence feeds','detective','technical','[\"OPS\",\"INC\"]','Recorded Future, Mandiant Advantage, Anomali, MISP',39,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(40,'Security Operations','Penetration Testing Service','Authorized simulated attacks to identify vulnerabilities and validate defenses','detective','technical','[\"OPS\",\"APS\",\"NET\"]','Cobalt, HackerOne, Bugcrowd, Bishop Fox, Internal Red Team',40,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(41,'Data Security','Data Loss Prevention (DLP)','Monitor, detect, and prevent unauthorized data exfiltration across channels','preventive','technical','[\"DSP\"]','Microsoft Purview DLP, Symantec DLP, Digital Guardian, Zscaler DLP',41,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(42,'Data Security','Secure File Sharing with Access Restrictions','Controlled file sharing with print blocking, watermarking, and access expiration','preventive','technical','[\"DSP\"]','Microsoft Purview Information Protection, Box Shield, Kiteworks, Virtru',42,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(43,'Data Security','Database Activity Monitoring (DAM)','Monitor and audit database queries and access patterns','detective','technical','[\"DSP\",\"OPS\"]','Imperva, IBM Guardium, Oracle Audit Vault, SolarWinds DPA',43,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(44,'Data Security','Data Classification & Labeling','Automatically classify and label data based on sensitivity levels','directive','technical','[\"DSP\",\"GOV\"]','Microsoft Purview, Titus, Boldon James, Varonis',44,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(45,'Data Security','Backup & Recovery Solution','Regular automated backups with tested recovery procedures','corrective','technical','[\"BCP\",\"DSP\"]','Veeam, Commvault, Rubrik, Cohesity, AWS Backup',45,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(46,'Data Security','Data Masking / Tokenization','Replace sensitive data with non-sensitive equivalents for non-production use','preventive','technical','[\"DSP\",\"CRY\"]','Informatica, Delphix, IBM InfoSphere, TokenEx',46,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(47,'Application Security','Web Application Firewall (WAF)','Filter and monitor HTTP traffic to protect web applications from attacks','preventive','technical','[\"APS\",\"NET\"]','Cloudflare WAF, AWS WAF, ModSecurity, Imperva, Akamai Kona',47,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(48,'Application Security','Static Application Security Testing (SAST)','Analyze source code for security vulnerabilities during development','detective','technical','[\"APS\"]','SonarQube, Checkmarx, Veracode, Snyk Code, Semgrep',48,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(49,'Application Security','Dynamic Application Security Testing (DAST)','Test running applications for vulnerabilities by simulating attacks','detective','technical','[\"APS\"]','Burp Suite, OWASP ZAP, Invicti, Rapid7 AppSpider',49,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(50,'Application Security','Software Composition Analysis (SCA)','Identify vulnerabilities in open-source and third-party libraries','detective','technical','[\"APS\",\"SCM\"]','Snyk, Black Duck, Mend (WhiteSource), Sonatype Nexus',50,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(51,'Application Security','API Security Gateway','Protect, rate-limit, and monitor API traffic','preventive','technical','[\"APS\",\"NET\"]','Kong Gateway, Apigee, AWS API Gateway, Salt Security',51,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(52,'Application Security','Secrets Management','Securely store, rotate, and distribute secrets (API keys, passwords, certificates)','preventive','technical','[\"APS\",\"CRY\",\"IAM\"]','HashiCorp Vault, AWS Secrets Manager, Azure Key Vault, CyberArk Conjur',52,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(53,'Email Security','Email Security Gateway','Filter inbound/outbound email for spam, phishing, malware, and BEC','preventive','technical','[\"NET\",\"INC\"]','Proofpoint, Mimecast, Microsoft Defender for Office 365, Barracuda',53,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(54,'Email Security','DMARC / DKIM / SPF','Email authentication protocols preventing domain spoofing','preventive','technical','[\"NET\"]','dmarcian, Valimail, Agari, native DNS configuration',54,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(55,'Email Security','Security Awareness Training','Phishing simulations and cybersecurity training for employees','directive','administrative','[\"HRS\",\"INC\"]','KnowBe4, Proofpoint SAT, SANS Security Awareness, Cofense',55,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(56,'Physical Security','Physical Access Control System','Badge, biometric, or PIN-based facility access management','preventive','physical','[\"PHY\"]','HID Global, Lenel, Genetec, Brivo, Kisi',56,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(57,'Physical Security','CCTV / Video Surveillance','Camera-based monitoring of facilities with recording and alerting','detective','physical','[\"PHY\"]','Verkada, Axis Communications, Milestone, Avigilon',57,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(58,'Physical Security','Environmental Monitoring','Temperature, humidity, water, and power monitoring in data centers','detective','physical','[\"PHY\",\"BCP\"]','APC NetBotz, Vertiv, Sensaphone, AKCP',58,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(59,'Governance','GRC Platform','Integrated governance, risk, and compliance management','directive','administrative','[\"GOV\",\"CMP\"]','FairTPRM, ServiceNow GRC, Archer, OneTrust, Drata',59,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(60,'Governance','Policy Management System','Create, distribute, track acknowledgment, and review security policies','directive','administrative','[\"GOV\"]','PowerDMS, NAVEX, FairTPRM Policy Module, ServiceNow',60,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(61,'Governance','Risk Management Framework','Structured approach to identifying, assessing, and treating risks','directive','administrative','[\"GOV\",\"CMP\"]','NIST RMF, ISO 31000, FAIR Model, FairTPRM Risk Register',61,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(62,'Governance','Vendor Risk Management Platform','Assess, monitor, and manage third-party security risks','directive','administrative','[\"SCM\",\"GOV\"]','FairTPRM, BitSight, SecurityScorecard, OneTrust, Prevalent',62,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(63,'Governance','Audit Management System','Plan, execute, track findings, and manage audit lifecycle','directive','administrative','[\"CMP\",\"GOV\"]','AuditBoard, Workiva, Galvanize, FairTPRM Audit Module',63,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(64,'Cryptography','Public Key Infrastructure (PKI)','Manage digital certificates for authentication and encryption','preventive','technical','[\"CRY\",\"IAM\"]','DigiCert, Entrust, Microsoft AD CS, Let\'s Encrypt, Venafi',64,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(65,'Cryptography','Hardware Security Module (HSM)','Dedicated hardware for cryptographic key storage and operations','preventive','technical','[\"CRY\"]','Thales Luna, AWS CloudHSM, Azure Dedicated HSM, Utimaco',65,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(66,'Cryptography','TLS/SSL Certificate Management','Automated certificate lifecycle management and monitoring','preventive','technical','[\"CRY\",\"NET\"]','Venafi, DigiCert CertCentral, Keyfactor, cert-manager',66,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(67,'Cryptography','Key Management System (KMS)','Centralized key generation, rotation, and access management','preventive','technical','[\"CRY\",\"DSP\"]','AWS KMS, Azure Key Vault, Google Cloud KMS, HashiCorp Vault',67,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(68,'Business Continuity','Disaster Recovery as a Service (DRaaS)','Cloud-based disaster recovery with automated failover','corrective','technical','[\"BCP\"]','Zerto, VMware Site Recovery, Azure Site Recovery, AWS Elastic DR',68,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(69,'Business Continuity','High Availability / Clustering','Redundant infrastructure for continuous availability','preventive','technical','[\"BCP\",\"NET\"]','VMware HA, AWS Multi-AZ, Azure Availability Zones, Kubernetes HA',69,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(70,'Business Continuity','Uninterruptible Power Supply (UPS)','Battery backup and power conditioning for critical infrastructure','preventive','physical','[\"BCP\",\"PHY\"]','APC Smart-UPS, Eaton, Vertiv Liebert, CyberPower',70,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(71,'Incident Response','Digital Forensics Tools','Collect, preserve, and analyze digital evidence during investigations','detective','technical','[\"INC\"]','EnCase, FTK, Autopsy, Velociraptor, AXIOM',71,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(72,'Incident Response','Managed Detection & Response (MDR)','Outsourced 24/7 threat monitoring, detection, and response','detective','technical','[\"INC\",\"OPS\"]','CrowdStrike Falcon Complete, Arctic Wolf, Expel, Red Canary',72,1,'2026-03-09 13:56:16','2026-03-09 13:56:16'),
(73,'Incident Response','Incident Communication Platform','Coordinated incident communication with stakeholders and regulators','directive','administrative','[\"INC\"]','PagerDuty, Opsgenie, Statuspage, xMatters',73,1,'2026-03-09 13:56:16','2026-03-09 13:56:16');


-- =============================================================================
-- SECTION 11: GRC Scopes Seed Data
-- Default organizational scopes for GRC assessments. Uses INSERT IGNORE
-- with the UNIQUE KEY on name to avoid duplicates on re-run.
-- =============================================================================

INSERT IGNORE INTO `grc_scopes` (`name`, `description`, `color`, `sort_order`, `is_active`) VALUES
('Enterprise-wide', 'Applies to the entire organization across all departments and locations.', '#3B82F6', 1, 1),
('IT Department', 'Scoped to IT infrastructure, systems, and technology operations.', '#8B5CF6', 2, 1),
('Finance & Accounting', 'Scoped to financial systems, reporting, and SOX-related controls.', '#10B981', 3, 1),
('Human Resources', 'Scoped to HR systems, employee data, and personnel processes.', '#F59E0B', 4, 1),
('Cloud Infrastructure', 'Scoped to cloud-hosted environments (AWS, Azure, GCP).', '#06B6D4', 5, 1),
('Customer Data', 'Scoped to systems and processes handling customer PII/SPII.', '#EF4444', 6, 1),
('Third-Party / Vendors', 'Scoped to vendor and third-party risk management.', '#F97316', 7, 1),
('Physical Facilities', 'Scoped to physical security of offices, data centers, and facilities.', '#6B7280', 8, 1),
('Application Development', 'Scoped to software development lifecycle and application security.', '#EC4899', 9, 1);


-- =============================================================================
-- SECTION 12: Assessment Workflow Rules
-- Automated rules that trigger assessments based on vendor onboarding answers.
-- Uses NOT EXISTS to avoid creating duplicate rules on re-run.
-- =============================================================================

-- Migrate any existing question-level triggers to the workflow rules table
INSERT IGNORE INTO assessment_workflow_rules (name, description, source_template_id, question_id, condition_value, target_template_id, is_active)
SELECT CONCAT('Migrated: ', t.name, ' -> ', t2.name), NULL, s.template_id, q.id, q.triggers_on_value, q.triggers_assessment_template_id, 1
FROM assessment_questions q
JOIN assessment_sections s ON q.section_id = s.id
JOIN assessment_templates t ON s.template_id = t.id
JOIN assessment_templates t2 ON q.triggers_assessment_template_id = t2.id
WHERE q.triggers_assessment_template_id IS NOT NULL;

-- AI Usage workflow rule: auto-trigger AI Usage assessment when vendor says AI is used
INSERT IGNORE INTO assessment_workflow_rules (name, description, source_template_id, question_id, condition_operator, condition_value, target_template_id, assign_to, is_active)
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
  AND (q.field_name = 'vendor_use_ai'
       OR q.question_text LIKE '%use AI%'
       OR q.question_text LIKE '%Artificial Intelligence%')
  AND t_target.slug = 'ai-usage'
  AND NOT EXISTS (
    SELECT 1 FROM assessment_workflow_rules r
    WHERE r.source_template_id = t_source.id
      AND r.target_template_id = t_target.id
      AND r.condition_value = 'Yes'
  )
LIMIT 1;


-- =============================================================================
-- SECTION 13: Vendor Onboarding Field Name Mappings
-- Sets field_name on vendor-onboarding assessment questions so answers map
-- to vendor_onboarding_requests columns. Only updates when field_name is
-- not already set, making this safe to re-run.
-- =============================================================================

-- Section 1: Vendor Information (16 questions)
UPDATE assessment_questions SET field_name = 'vendor_name' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 1;
UPDATE assessment_questions SET field_name = 'vendor_domain' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 2;
UPDATE assessment_questions SET field_name = 'vendor_sisterdomains' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 3;
UPDATE assessment_questions SET field_name = 'vsu_onboarded' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 4;
UPDATE assessment_questions SET field_name = 'vendor_id' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 5;
UPDATE assessment_questions SET field_name = 'vendor_type' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 6;
UPDATE assessment_questions SET field_name = 'relationship_manager' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 7;
UPDATE assessment_questions SET field_name = 'expected_procurement_date' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 8;
UPDATE assessment_questions SET field_name = 'product_service_description' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 9;
UPDATE assessment_questions SET field_name = 'target_user_count' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 10;
UPDATE assessment_questions SET field_name = 'primary_contact_email' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 11;
UPDATE assessment_questions SET field_name = 'primary_contact_details' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 12;
UPDATE assessment_questions SET field_name = 'primary_contact_title' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 13;
UPDATE assessment_questions SET field_name = 'primary_contact_phone' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 14;
UPDATE assessment_questions SET field_name = 'nda_in_place' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 15;
UPDATE assessment_questions SET field_name = 'vendor_competitors' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 1) AND sort_order = 16;

-- Section 2: Data & Risk Assessment (23 questions)
UPDATE assessment_questions SET field_name = 'pii_record_count' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 1;
UPDATE assessment_questions SET field_name = 'spii_record_count' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 2;
UPDATE assessment_questions SET field_name = 'sox_record_count' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 3;
UPDATE assessment_questions SET field_name = 'business_impact' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 4;
UPDATE assessment_questions SET field_name = 'confidential_info_shared' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 5;
UPDATE assessment_questions SET field_name = 'confidential_info_justification' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 6;
UPDATE assessment_questions SET field_name = 'cross_border_transfer' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 7;
UPDATE assessment_questions SET field_name = 'cross_border_justification' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 8;
UPDATE assessment_questions SET field_name = 'offsite_data_hosting' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 9;
UPDATE assessment_questions SET field_name = 'offsite_data_justification' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 10;
UPDATE assessment_questions SET field_name = 'remote_network_access' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 11;
UPDATE assessment_questions SET field_name = 'remote_access_justification' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 12;
UPDATE assessment_questions SET field_name = 'source_code_access' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 13;
UPDATE assessment_questions SET field_name = 'source_code_justification' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 14;
UPDATE assessment_questions SET field_name = 'critical_business_function' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 15;
UPDATE assessment_questions SET field_name = 'critical_function_justification' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 16;
UPDATE assessment_questions SET field_name = 'unauthorized_disclosure_impact' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 17;
UPDATE assessment_questions SET field_name = 'unauthorized_disclosure_justification' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 18;
UPDATE assessment_questions SET field_name = 'unauthorized_modification_impact' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 19;
UPDATE assessment_questions SET field_name = 'disruption_impact' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 20;
UPDATE assessment_questions SET field_name = 'saml_sso_support' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 21;
UPDATE assessment_questions SET field_name = 'is_saas' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 22;
UPDATE assessment_questions SET field_name = 'vendor_use_ai' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 2) AND sort_order = 23;

-- Section 3: Additional Information
UPDATE assessment_questions SET field_name = 'additional_information' WHERE field_name IS NULL AND section_id IN (SELECT id FROM assessment_sections WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding') AND sort_order = 3) AND sort_order = 1;

-- Label rename: drop the institution-specific "VSU" prefix from the onboarding question text (idempotent).
UPDATE assessment_questions SET question_text = 'Has this vendor completed Procurement Onboarding?' WHERE field_name = 'vsu_onboarded' AND question_text = 'Has this vendor completed VSU Procurement Onboarding?';


-- =============================================================================
-- SECTION 14: Legacy Scoring Views
-- Convenience views used by the scoring dashboard. CREATE OR REPLACE is
-- inherently idempotent and compatible with MySQL 8.0+ and MariaDB 10.5+.
-- =============================================================================

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
-- SECTION 14b: Database Views
-- Views used by the GRC dashboard, control coverage reporting, and CVE search.
-- CREATE OR REPLACE is idempotent - safe to run on every container start.
-- =============================================================================

-- View: view_grc_compliance_status
-- Used by GRCService::getDashboardStats() for the GRC dashboard overview
CREATE OR REPLACE VIEW `view_grc_compliance_status` AS
SELECT
    f.id AS framework_id,
    f.code AS framework_code,
    f.name AS framework_name,
    COUNT(DISTINCT fr.id) AS total_requirements,
    COUNT(DISTINCT CASE WHEN ci.implementation_status = 'implemented' AND crm.coverage = 'full' THEN fr.id END) AS fully_implemented,
    COUNT(DISTINCT CASE WHEN ci.implementation_status = 'implemented' AND crm.coverage = 'partial' THEN fr.id END) AS partially_implemented,
    COUNT(DISTINCT CASE WHEN ci.implementation_status = 'in_progress' THEN fr.id END) AS in_progress,
    COUNT(DISTINCT CASE WHEN ci.implementation_status = 'planned' THEN fr.id END) AS planned,
    COUNT(DISTINCT CASE WHEN ci.implementation_status = 'not_applicable' THEN fr.id END) AS not_applicable,
    ROUND(
        COUNT(DISTINCT CASE WHEN ci.implementation_status = 'implemented' THEN fr.id END) * 100.0
        / NULLIF(
            COUNT(DISTINCT fr.id) - COUNT(DISTINCT CASE WHEN ci.implementation_status = 'not_applicable' THEN fr.id END),
            0
        ), 2
    ) AS compliance_percentage
FROM grc_frameworks f
LEFT JOIN grc_framework_requirements fr ON fr.framework_id = f.id
LEFT JOIN grc_control_requirement_map crm ON crm.requirement_id = fr.id
LEFT JOIN grc_internal_controls ic ON ic.id = crm.control_id AND ic.is_active = 1
LEFT JOIN grc_control_implementations ci ON ci.control_id = ic.id AND ci.framework_id = f.id
WHERE f.is_active = 1
GROUP BY f.id, f.code, f.name;

-- View: view_grc_control_coverage
-- Used for control coverage analysis across frameworks
CREATE OR REPLACE VIEW `view_grc_control_coverage` AS
SELECT
    ic.id AS control_id,
    ic.control_ref AS control_ref,
    ic.title AS control_title,
    ic.implementation_status AS implementation_status,
    GROUP_CONCAT(DISTINCT f.code ORDER BY f.code ASC SEPARATOR ', ') AS mapped_frameworks,
    COUNT(DISTINCT f.id) AS framework_count,
    COUNT(DISTINCT fr.id) AS requirement_count,
    COUNT(DISTINCT e.id) AS evidence_count
FROM grc_internal_controls ic
LEFT JOIN grc_control_requirement_map crm ON crm.control_id = ic.id
LEFT JOIN grc_framework_requirements fr ON fr.id = crm.requirement_id
LEFT JOIN grc_frameworks f ON f.id = fr.framework_id
LEFT JOIN grc_evidence_control_map ecm ON ecm.control_id = ic.id
LEFT JOIN grc_evidence e ON e.id = ecm.evidence_id AND e.status = 'current'
WHERE ic.is_active = 1
GROUP BY ic.id, ic.control_ref, ic.title, ic.implementation_status;

-- View: view_cve_search
-- Unified CVE search across Shodan findings with UpGuard cross-reference
CREATE OR REPLACE VIEW `view_cve_search` AS
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
-- SECTION 15: Schema Compatibility (safe ALTER for existing databases)
-- Compatible with MySQL 8.0+ and MariaDB 10.5+.
-- Uses information_schema checks via PREPARE/EXECUTE so adding an existing
-- column or index is a no-op rather than an error on either platform.
-- =============================================================================

-- assessment_sections.visible_roles (per-section onboarding role visibility)
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'assessment_sections' AND column_name = 'visible_roles') = 0,
  'ALTER TABLE `assessment_sections` ADD COLUMN `visible_roles` TEXT DEFAULT NULL AFTER `sort_order`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- vendor_scheduled_actions.assignees (multi-assign cyber_tprm users for Action Plan)
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_scheduled_actions' AND column_name = 'assignees') = 0,
  'ALTER TABLE `vendor_scheduled_actions` ADD COLUMN `assignees` TEXT DEFAULT NULL AFTER `description`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- vendor_scheduled_actions.notify_assignees (email assignees when the action fires)
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_scheduled_actions' AND column_name = 'notify_assignees') = 0,
  'ALTER TABLE `vendor_scheduled_actions` ADD COLUMN `notify_assignees` TINYINT(1) NOT NULL DEFAULT 0 AFTER `assignees`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- vendor_scheduled_actions.notify_emails (editable notification addresses)
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_scheduled_actions' AND column_name = 'notify_emails') = 0,
  'ALTER TABLE `vendor_scheduled_actions` ADD COLUMN `notify_emails` TEXT DEFAULT NULL AFTER `notify_assignees`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- vendor_scheduled_actions.status: move from the original execution-lifecycle enum
-- (pending/executed/cancelled/failed) to a user-editable workflow enum. Firing is
-- now tracked by executed_at, so legacy executed->in_progress, failed->problem.
-- Only runs when the table exists; widen, migrate, then narrow (idempotent).
SET @vsa_exists = (SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'vendor_scheduled_actions');
SET @sql = IF(@vsa_exists = 1,
  "ALTER TABLE `vendor_scheduled_actions` MODIFY COLUMN `status` ENUM('pending','in_progress','problem','completed','cancelled','executed','failed') NOT NULL DEFAULT 'pending'",
  'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
UPDATE `vendor_scheduled_actions` SET `status` = 'in_progress' WHERE `status` = 'executed';
UPDATE `vendor_scheduled_actions` SET `status` = 'problem'     WHERE `status` = 'failed';
SET @sql = IF(@vsa_exists = 1,
  "ALTER TABLE `vendor_scheduled_actions` MODIFY COLUMN `status` ENUM('pending','in_progress','problem','completed','cancelled') NOT NULL DEFAULT 'pending'",
  'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- assessment_questions.include_in_minimal -- MUST precede visible_roles below, which
-- is added AFTER include_in_minimal. The master schema's assessment_questions predates
-- this column, so the earlier CREATE TABLE IF NOT EXISTS is a no-op on installs whose
-- table already exists; without adding it here first, the visible_roles/editable_roles
-- ALTERs fail with "Unknown column 'include_in_minimal'" on every fresh seed.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'assessment_questions' AND column_name = 'include_in_minimal') = 0,
  'ALTER TABLE `assessment_questions` ADD COLUMN `include_in_minimal` TINYINT(1) DEFAULT 0 COMMENT ''When the template is in minimal certificate mode, only questions flagged here are presented/required''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- assessment_questions.visible_roles (per-question/field onboarding role visibility)
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'assessment_questions' AND column_name = 'visible_roles') = 0,
  'ALTER TABLE `assessment_questions` ADD COLUMN `visible_roles` TEXT DEFAULT NULL AFTER `include_in_minimal`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- assessment_sections.editable_roles (per-section onboarding edit grant; empty = no one)
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'assessment_sections' AND column_name = 'editable_roles') = 0,
  'ALTER TABLE `assessment_sections` ADD COLUMN `editable_roles` TEXT DEFAULT NULL AFTER `visible_roles`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- assessment_questions.editable_roles (per-question/field onboarding edit grant; empty = no one)
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'assessment_questions' AND column_name = 'editable_roles') = 0,
  'ALTER TABLE `assessment_questions` ADD COLUMN `editable_roles` TEXT DEFAULT NULL AFTER `visible_roles`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- grc_frameworks.compliance_year
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'grc_frameworks' AND column_name = 'compliance_year') = 0,
  'ALTER TABLE `grc_frameworks` ADD COLUMN `compliance_year` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_active`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- grc_frameworks idx_year index
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'grc_frameworks' AND index_name = 'idx_year') = 0,
  'ALTER TABLE `grc_frameworks` ADD INDEX `idx_year` (`compliance_year`)',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- grc_frameworks.generated_from
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'grc_frameworks' AND column_name = 'generated_from') = 0,
  'ALTER TABLE `grc_frameworks` ADD COLUMN `generated_from` VARCHAR(50) DEFAULT NULL',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- cyber_breach_alerts.investigating_by
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'cyber_breach_alerts' AND column_name = 'investigating_by') = 0,
  'ALTER TABLE `cyber_breach_alerts` ADD COLUMN `investigating_by` INT UNSIGNED DEFAULT NULL AFTER `resolved_at`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- cyber_breach_alerts.investigating_at
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'cyber_breach_alerts' AND column_name = 'investigating_at') = 0,
  'ALTER TABLE `cyber_breach_alerts` ADD COLUMN `investigating_at` DATETIME DEFAULT NULL AFTER `investigating_by`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Extend breach alert ENUM types (MODIFY is safe - adds new values, preserves existing data)
ALTER TABLE `cyber_breach_alerts`
    MODIFY COLUMN `alert_type` ENUM('data_breach','cyber_attack','vulnerability','supply_chain','ransomware','osint_exposure','other') NOT NULL DEFAULT 'other';

ALTER TABLE `cyber_breach_alerts`
    MODIFY COLUMN `affected_entity_type` ENUM('vendor','subprocessor','technology','organization','shadow_saas') NOT NULL DEFAULT 'vendor';

-- cyber_breach_alerts.impacted_user_count - number of users potentially impacted,
-- populated by the Grip breach feed (GripService). For a Grip "Shadow SaaS"
-- breach (no onboarded vendor) this drives the "Shadow SaaS / N users" indicator.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'cyber_breach_alerts' AND column_name = 'impacted_user_count') = 0,
  'ALTER TABLE `cyber_breach_alerts` ADD COLUMN `impacted_user_count` INT UNSIGNED DEFAULT NULL AFTER `vendor_count`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- cyber_breach_alerts.grip_saas_id - the Grip SaaS application id behind a Grip
-- breach. Links the breach to its impacted-user snapshot (shadow_saas_grip_app_users)
-- so the Breach Alerts UI can drill into the affected users.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'cyber_breach_alerts' AND column_name = 'grip_saas_id') = 0,
  'ALTER TABLE `cyber_breach_alerts` ADD COLUMN `grip_saas_id` VARCHAR(64) DEFAULT NULL AFTER `impacted_user_count`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'cyber_breach_alerts' AND index_name = 'idx_grip_saas_id') = 0,
  'ALTER TABLE `cyber_breach_alerts` ADD INDEX `idx_grip_saas_id` (`grip_saas_id`)',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- cyber_breach_alerts.affected_domain - the affected application's registrable
-- domain (Grip url telemetry). Lets the Breach UI label Grip alerts as
-- "Company (domain.com)" even when the app isn't in the local Shadow SaaS list.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'cyber_breach_alerts' AND column_name = 'affected_domain') = 0,
  'ALTER TABLE `cyber_breach_alerts` ADD COLUMN `affected_domain` VARCHAR(255) DEFAULT NULL AFTER `grip_saas_id`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- =============================================================================
-- vendor_onboarding_requests: Cost Center and Project (Engagement Details)
-- =============================================================================

-- vendor_onboarding_requests.cost_center
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_onboarding_requests' AND column_name = 'cost_center') = 0,
  'ALTER TABLE `vendor_onboarding_requests` ADD COLUMN `cost_center` INT(10) UNSIGNED DEFAULT NULL COMMENT ''Accounting cost center (integer)'' AFTER `target_user_count`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- vendor_onboarding_requests.project
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_onboarding_requests' AND column_name = 'project') = 0,
  'ALTER TABLE `vendor_onboarding_requests` ADD COLUMN `project` VARCHAR(255) DEFAULT NULL COMMENT ''Project name / identifier'' AFTER `cost_center`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Index to speed up search/filter on cost_center and project
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'vendor_onboarding_requests' AND index_name = 'idx_project') = 0,
  'ALTER TABLE `vendor_onboarding_requests` ADD INDEX `idx_project` (`project`)',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'vendor_onboarding_requests' AND index_name = 'idx_cost_center') = 0,
  'ALTER TABLE `vendor_onboarding_requests` ADD INDEX `idx_cost_center` (`cost_center`)',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Seed field_references entries for Cost Center, Project, and Services Use AI
-- (vendor_use_ai column already exists; this just documents it in field-reference UI)
INSERT IGNORE INTO `field_references` (`field_name`, `column_type`, `section`, `category`, `description`, `is_builtin`) VALUES
  ('cost_center',  'INT(10) UNSIGNED',   'Engagement Details',     'onboarding', 'Accounting cost center (integer)', 1),
  ('project',      'VARCHAR(255)',       'Engagement Details',     'onboarding', 'Project name / identifier', 1),
  ('vendor_use_ai','ENUM(''yes'',''no'','''')', 'Data & Risk Assessment', 'onboarding', 'Does vendor solution use AI? (Services Use AI checkbox on onboarding)', 1);

-- =============================================================================
-- vendor_onboarding_requests: VAT number (EU VAT, surfaced on the onboarding page
-- and mappable from a "vat" Template Builder question via field_name).
-- =============================================================================
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_onboarding_requests' AND column_name = 'vat_number') = 0,
  'ALTER TABLE `vendor_onboarding_requests` ADD COLUMN `vat_number` VARCHAR(64) DEFAULT NULL COMMENT ''EU VAT number (canonical, e.g. DE123456789)'' AFTER `primary_contact_phone`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Document vat_number in the field-reference UI so it can be mapped from templates.
INSERT IGNORE INTO `field_references` (`field_name`, `column_type`, `section`, `category`, `description`, `is_builtin`) VALUES
  ('vat_number',   'VARCHAR(64)',        'Vendor Information',     'onboarding', 'EU VAT number (validated against VIES)', 1);

-- =============================================================================
-- Default Vendor Onboarding template: switch the primary-contact phone question
-- from a plain text box to the new "phone" type. Idempotent and scoped to the
-- built-in vendor-onboarding template's primary_contact_phone question, so a
-- custom text override on a different template is left untouched.
-- =============================================================================
UPDATE assessment_questions
   SET question_type = 'phone'
 WHERE field_name = 'primary_contact_phone'
   AND question_type = 'text'
   AND section_id IN (
     SELECT id FROM assessment_sections
      WHERE template_id = (SELECT id FROM assessment_templates WHERE slug = 'vendor-onboarding')
   );

-- =============================================================================
-- grc_risk_register: vendor_id link (for per-vendor AI risk tracking from SRS list)
-- =============================================================================

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'grc_risk_register' AND column_name = 'vendor_id') = 0,
  'ALTER TABLE `grc_risk_register` ADD COLUMN `vendor_id` INT(10) UNSIGNED DEFAULT NULL COMMENT ''vendor_onboarding_requests.id this risk is associated with'' AFTER `created_by`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'grc_risk_register' AND index_name = 'idx_vendor_id') = 0,
  'ALTER TABLE `grc_risk_register` ADD INDEX `idx_vendor_id` (`vendor_id`)',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- =============================================================================
-- Grip Security integration (Shadow SaaS): mirror tables, shadow_saas link
-- columns, and app_config defaults. Idempotent - safe to re-run.
-- =============================================================================

-- Mirror tables for the Grip Security public API. Created here so fresh
-- installs get them; existing installs are unaffected because of IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `shadow_saas_grip_apps` (
  `grip_id` varchar(64) NOT NULL,
  `name` varchar(500) DEFAULT NULL,
  `url` varchar(2048) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL COMMENT 'Normalized registrable domain extracted from url',
  `logo_url` varchar(2048) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `risk_score` int(10) unsigned DEFAULT NULL,
  `sanction_tag` varchar(50) DEFAULT NULL,
  `number_of_users` int(10) unsigned DEFAULT NULL,
  `number_of_onboarded_users` int(10) unsigned DEFAULT NULL,
  `number_of_offboarded_users` int(10) unsigned DEFAULT NULL,
  `sso_percentage` int(10) unsigned DEFAULT NULL,
  `access_removal_support` varchar(50) DEFAULT NULL,
  `business_owner` text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `app_instances_count` int(10) unsigned DEFAULT NULL,
  `last_known_usage` datetime DEFAULT NULL,
  `first_event_time` datetime DEFAULT NULL,
  `latest_event_time` datetime DEFAULT NULL,
  `unique_scopes_count` int(10) unsigned DEFAULT NULL,
  `mfa_supported` text DEFAULT NULL,
  `ai_depth_level` varchar(255) DEFAULT NULL,
  `ai_depth_description` text DEFAULT NULL,
  `oauth_total` int(10) unsigned DEFAULT NULL,
  `oauth_high` int(10) unsigned DEFAULT NULL,
  `oauth_medium` int(10) unsigned DEFAULT NULL,
  `oauth_low` int(10) unsigned DEFAULT NULL,
  `primary_contact_name` text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `primary_contact_email` text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `primary_contact_user_id` text DEFAULT NULL COMMENT 'Grip userId (email-format); PII: AES-encrypted at rest',
  `labels` longtext DEFAULT NULL COMMENT 'JSON array',
  `source_platforms` longtext DEFAULT NULL COMMENT 'JSON array',
  `assets` longtext DEFAULT NULL COMMENT 'JSON array',
  `compliances` longtext DEFAULT NULL COMMENT 'JSON array',
  `saml_supported` longtext DEFAULT NULL COMMENT 'JSON object: idp -> doc url',
  `saas_users_roles` longtext DEFAULT NULL COMMENT 'JSON array',
  `terms_questions` longtext DEFAULT NULL COMMENT 'JSON',
  `terms_references` longtext DEFAULT NULL COMMENT 'JSON',
  `oauth_scopes_high` longtext DEFAULT NULL COMMENT 'JSON array',
  `oauth_scopes_medium` longtext DEFAULT NULL COMMENT 'JSON array',
  `oauth_scopes_low` longtext DEFAULT NULL COMMENT 'JSON array',
  `raw_payload` longtext DEFAULT NULL COMMENT 'Full original JSON',
  `roster_signature` varchar(80) DEFAULT NULL COMMENT 'Delta-hydration cache key: user counts + activity timestamps; unchanged => skip per-app roster re-fetch',
  `first_seen_at` timestamp NULL DEFAULT current_timestamp(),
  `last_synced_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grip_id`),
  KEY `idx_name` (`name`(191)),
  KEY `idx_domain` (`domain`),
  KEY `idx_category` (`category`),
  KEY `idx_risk_score` (`risk_score`),
  KEY `idx_sanction_tag` (`sanction_tag`),
  KEY `idx_last_synced_at` (`last_synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shadow_saas_grip_users` (
  `grip_id` varchar(64) NOT NULL,
  `mail` text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `aliases` longtext DEFAULT NULL COMMENT 'JSON array; PII: AES-encrypted at rest',
  `full_name` text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `display_name` text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `organizational_unit` text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `manager_email` text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `user_type` varchar(64) DEFAULT NULL,
  `number_of_saas` int(10) unsigned DEFAULT NULL,
  `sso_percentage` int(10) unsigned DEFAULT NULL,
  `has_active_mailbox` tinyint(1) DEFAULT NULL,
  `last_usage` datetime DEFAULT NULL,
  `first_event_time` datetime DEFAULT NULL,
  `latest_event_time` datetime DEFAULT NULL,
  `offboarding_workflow_status` varchar(64) DEFAULT NULL,
  `offboarding_workflow_timestamp` datetime DEFAULT NULL,
  `roles` longtext DEFAULT NULL COMMENT 'JSON array',
  `labels` longtext DEFAULT NULL COMMENT 'JSON array',
  `platforms_activity` longtext DEFAULT NULL COMMENT 'JSON array',
  `custom_fields` longtext DEFAULT NULL COMMENT 'JSON; PII: AES-encrypted at rest',
  `raw_payload` longtext DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `first_seen_at` timestamp NULL DEFAULT current_timestamp(),
  `last_synced_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grip_id`),
  -- PII columns are encrypted at rest (random IV), so they are not indexable or
  -- sortable in SQL; the former idx_mail/idx_full_name/idx_org_unit are dropped.
  KEY `idx_last_synced_at` (`last_synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shadow_saas_grip_alerts` (
  `alert_id` varchar(64) NOT NULL,
  `alert_type` varchar(255) DEFAULT NULL,
  `alert_severity` varchar(50) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `mitigation_steps` longtext DEFAULT NULL COMMENT 'JSON',
  `potential_impacts` longtext DEFAULT NULL COMMENT 'JSON array',
  `unique_fields_data` longtext DEFAULT NULL COMMENT 'JSON',
  `related_entity_id` varchar(64) DEFAULT NULL,
  `related_entity_name` varchar(500) DEFAULT NULL,
  `related_entity_type` varchar(64) DEFAULT NULL,
  `alert_url` varchar(2048) DEFAULT NULL,
  `created_at_grip` datetime DEFAULT NULL,
  `updated_at_grip` datetime DEFAULT NULL,
  `raw_payload` longtext DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT current_timestamp(),
  `last_synced_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`alert_id`),
  KEY `idx_alert_type` (`alert_type`),
  KEY `idx_alert_severity` (`alert_severity`),
  KEY `idx_status` (`status`),
  KEY `idx_related_entity` (`related_entity_id`),
  KEY `idx_created_at_grip` (`created_at_grip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shadow_saas_grip_sync_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `trigger_source` varchar(20) NOT NULL DEFAULT 'cron' COMMENT 'cron|manual|test',
  `status` varchar(20) NOT NULL DEFAULT 'running' COMMENT 'running|success|partial|error',
  `apps_fetched` int(10) unsigned DEFAULT 0,
  `users_fetched` int(10) unsigned DEFAULT 0,
  `alerts_fetched` int(10) unsigned DEFAULT 0,
  `shadow_saas_upserted` int(10) unsigned DEFAULT 0,
  `roster_done` int(10) unsigned DEFAULT 0 COMMENT 'Per-app roster hydration progress: apps processed',
  `roster_total` int(10) unsigned DEFAULT 0 COMMENT 'Per-app roster hydration progress: apps with users to process',
  `error_message` text DEFAULT NULL,
  `triggered_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-SaaS-app user snapshot (Grip GET /public/saas/{id}/users). Hydrated by
-- GripService for apps that have a Grip security incident, so the Breach Alerts
-- UI can drill from a breach into the users potentially impacted. Keyed by the
-- Grip SaaS app id + the Grip user id.
CREATE TABLE IF NOT EXISTS `shadow_saas_grip_app_users` (
  `grip_saas_id`        varchar(64) NOT NULL COMMENT 'Grip SaaS application id',
  `user_grip_id`        varchar(64) NOT NULL COMMENT 'Grip user id',
  `mail`                text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `full_name`           text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `display_name`        text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `organizational_unit` text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `manager_email`       text DEFAULT NULL COMMENT 'PII: AES-encrypted at rest',
  `user_type`           varchar(64) DEFAULT NULL,
  `authentication_type` varchar(64) DEFAULT NULL,
  `sso`                 tinyint(1) DEFAULT NULL,
  `unique_scopes_count` int(10) unsigned DEFAULT NULL,
  `number_of_saas`      int(10) unsigned DEFAULT NULL,
  `has_active_mailbox`  tinyint(1) DEFAULT NULL,
  `first_event_time`    datetime DEFAULT NULL COMMENT 'Per-app first event (saasUser.firstEventTime)',
  `latest_event_time`   datetime DEFAULT NULL,
  `last_usage`          datetime DEFAULT NULL,
  `platforms_activity`  longtext DEFAULT NULL COMMENT 'JSON array',
  -- raw_payload intentionally omitted: it was an encrypted copy of the full entry
  -- JSON that nothing renders yet dominated row size (~80%). The flattened columns
  -- above carry everything the UI/API need.
  `last_synced_at`      timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  -- The per-app lookup (WHERE grip_saas_id = ?) is served by the PRIMARY KEY's
  -- leading column, so no separate index on grip_saas_id is needed. mail/full_name/
  -- etc are encrypted at rest (random IV) -> not indexable; idx_mail dropped.
  PRIMARY KEY (`grip_saas_id`,`user_grip_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- HERO Security mirror tables (parallel to shadow_saas_grip_*). Hydrated by
-- HeroService; Grip and Hero are mutually exclusive. Vendor key = canonical
-- domain (HERO has no opaque vendor id).
-- =============================================================================
CREATE TABLE IF NOT EXISTS `shadow_saas_hero_vendors` (
  `hero_id` varchar(255) NOT NULL COMMENT 'HERO canonical_domain (natural key)',
  `commercial_name` varchar(500) DEFAULT NULL,
  `canonical_domain` varchar(255) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL COMMENT 'Normalized registrable domain',
  `associated_domains` longtext DEFAULT NULL COMMENT 'JSON array',
  `status` varchar(50) DEFAULT NULL COMMENT 'observed|operative|inoperative',
  `commercial_engagement` varchar(50) DEFAULT NULL,
  `authorization` varchar(50) DEFAULT NULL,
  `activity` varchar(50) DEFAULT NULL,
  `hero_url` varchar(2048) DEFAULT NULL,
  `open_issue_count` int(10) unsigned DEFAULT 0,
  `worst_issue_severity` varchar(20) DEFAULT NULL,
  `derived_risk_score` tinyint(3) unsigned DEFAULT NULL COMMENT '1-5, derived from worst open issue',
  `updated_at_hero` datetime DEFAULT NULL,
  `raw_payload` longtext DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT current_timestamp(),
  `last_synced_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`hero_id`),
  KEY `idx_domain` (`domain`),
  KEY `idx_status` (`status`),
  KEY `idx_derived_risk_score` (`derived_risk_score`),
  KEY `idx_last_synced_at` (`last_synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shadow_saas_hero_issues` (
  `issue_id` varchar(255) NOT NULL,
  `type` varchar(64) DEFAULT NULL COMMENT 'shadow_it|unrevoked_permissions|bec|misconfiguration|dormancy',
  `severity` varchar(20) DEFAULT NULL COMMENT 'low|medium|high|critical',
  `status` varchar(20) DEFAULT NULL COMMENT 'open|resolved',
  `title` varchar(500) DEFAULT NULL,
  `vendor_domain` varchar(255) DEFAULT NULL COMMENT 'Normalized registrable domain',
  `vendor_name` varchar(500) DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `updated_at_hero` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution` longtext DEFAULT NULL COMMENT 'JSON',
  `raw_payload` longtext DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT current_timestamp(),
  `last_synced_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`issue_id`),
  KEY `idx_type` (`type`),
  KEY `idx_severity` (`severity`),
  KEY `idx_status` (`status`),
  KEY `idx_vendor_domain` (`vendor_domain`),
  KEY `idx_opened_at` (`opened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shadow_saas_hero_users` (
  `user_key` char(40) NOT NULL COMMENT 'sha1(vendor_domain|lower(email)) — fixed-length surrogate PK',
  `vendor_domain` varchar(255) DEFAULT NULL,
  `email` varchar(320) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email_count` int(10) unsigned DEFAULT 0,
  `sources` longtext DEFAULT NULL COMMENT 'JSON array (type/provider/last_seen)',
  `raw_payload` longtext DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT current_timestamp(),
  `last_synced_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_key`),
  KEY `idx_vendor_domain` (`vendor_domain`),
  KEY `idx_email` (`email`),
  KEY `idx_last_synced_at` (`last_synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shadow_saas_hero_sync_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `trigger_source` varchar(20) NOT NULL DEFAULT 'cron' COMMENT 'cron|manual|test',
  `status` varchar(20) NOT NULL DEFAULT 'running' COMMENT 'running|success|partial|error',
  `vendors_fetched` int(10) unsigned DEFAULT 0,
  `users_fetched` int(10) unsigned DEFAULT 0,
  `issues_fetched` int(10) unsigned DEFAULT 0,
  `shadow_saas_upserted` int(10) unsigned DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `triggered_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- shadow_saas link columns used by GripService::projectAppsToShadowSaas().

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'shadow_saas' AND column_name = 'grip_id') = 0,
  'ALTER TABLE `shadow_saas` ADD COLUMN `grip_id` VARCHAR(64) DEFAULT NULL COMMENT ''Grip Security UUID for matching upserts''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'shadow_saas' AND index_name = 'idx_grip_id') = 0,
  'ALTER TABLE `shadow_saas` ADD INDEX `idx_grip_id` (`grip_id`)',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- HERO link column used by HeroService::projectVendorsToShadowSaas() (= canonical_domain).
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'shadow_saas' AND column_name = 'hero_id') = 0,
  'ALTER TABLE `shadow_saas` ADD COLUMN `hero_id` VARCHAR(255) DEFAULT NULL COMMENT ''HERO canonical domain for matching upserts''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'shadow_saas' AND index_name = 'idx_hero_id') = 0,
  'ALTER TABLE `shadow_saas` ADD INDEX `idx_hero_id` (`hero_id`)',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'shadow_saas' AND column_name = 'source') = 0,
  'ALTER TABLE `shadow_saas` ADD COLUMN `source` VARCHAR(20) DEFAULT ''csv'' COMMENT ''Origin of this row: csv | grip | hero | manual''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- shadow_saas.security_scorecard_rating - SecurityScorecard letter grade
-- (A/B/C/D/F) from Grip telemetry. Grip exposes no numeric SSC score, only
-- this rating; shown as the "SSC" badge on the Shadow SaaS and vendor SRS lists.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'shadow_saas' AND column_name = 'security_scorecard_rating') = 0,
  'ALTER TABLE `shadow_saas` ADD COLUMN `security_scorecard_rating` VARCHAR(2) DEFAULT NULL COMMENT ''SecurityScorecard rating A/B/C/D/F (Grip)''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- vendor_onboarding_requests.security_scorecard_rating - same Grip SSC letter
-- grade for ONBOARDED vendors (matched by domain during sync), shown as the
-- sortable "SSC" column on vendor-srs-list.php.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_onboarding_requests' AND column_name = 'security_scorecard_rating') = 0,
  'ALTER TABLE `vendor_onboarding_requests` ADD COLUMN `security_scorecard_rating` VARCHAR(2) DEFAULT NULL COMMENT ''SecurityScorecard rating A/B/C/D/F (Grip)''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- vendor_onboarding_requests.grip_app_data - curated Grip SaaS telemetry (JSON)
-- for the matched app: first discovered, active accounts, last usage, sanction,
-- category, AI depth, compliances, SAML/MFA support. Powers the "SaaS Data" tab
-- on vendor-onboarding.php. Stamped during the Grip sync (domain match).
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_onboarding_requests' AND column_name = 'grip_app_data') = 0,
  'ALTER TABLE `vendor_onboarding_requests` ADD COLUMN `grip_app_data` TEXT DEFAULT NULL COMMENT ''Curated Grip SaaS app telemetry (JSON)''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Zscaler block state: tracks vendors whose domain has been pushed into
-- the configured ZIA URL Category via the Deny button. The Allow button
-- in shadow-saas.php is rendered iff is_zscaler_blocked = 1.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'shadow_saas' AND column_name = 'is_zscaler_blocked') = 0,
  'ALTER TABLE `shadow_saas` ADD COLUMN `is_zscaler_blocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = domain currently in Zscaler URL Category''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'shadow_saas' AND column_name = 'zscaler_blocked_at') = 0,
  'ALTER TABLE `shadow_saas` ADD COLUMN `zscaler_blocked_at` DATETIME DEFAULT NULL COMMENT ''When the domain was last added to Zscaler URL Category''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Default app_config rows so fresh installs render the admin page with
-- predictable values. INSERT IGNORE leaves any operator-customized values alone.
-- grip_base_url is intentionally NOT seeded - it's tenant-specific and the
-- admin enters it in the UI; the PHP loader falls back to a sensible value
-- when the row is absent.
INSERT IGNORE INTO `app_config` (`config_key`, `config_value`, `is_encrypted`) VALUES
  ('grip_enabled',         '0',     0),
  ('grip_api_token',       '',      1),
  ('grip_cron_enabled',    '0',     0),
  ('grip_cron_frequency',  'daily', 0),
  -- Data source for Grip Shadow SaaS views: 'live' = query the Grip API on
  -- every page view (default, current behavior); 'local' = serve the hydrated
  -- shadow_saas_grip_* DB snapshot (caching, lighter on the API) and full-refresh
  -- (truncate + re-hydrate the mirror tables) on each sync.
  ('grip_data_source',     'live',  0);

-- HERO Security config (parallel to grip_*; mutually exclusive with Grip).
-- client_secret encrypted; base_url seeded to the fixed public API host (not
-- tenant-specific); disabled by default.
INSERT IGNORE INTO `app_config` (`config_key`, `config_value`, `is_encrypted`) VALUES
  ('hero_enabled',        '0',                                  0),
  ('hero_base_url',       'https://api.herosecurity.ai/stable', 0),
  ('hero_client_id',      '',                                   0),
  ('hero_client_secret',  '',                                   1),
  ('hero_throttle_ms',    '1100',                               0),
  ('hero_users_timeout',  '15',                                 0);

-- Convert shadow_saas Grip risk scores from 0-100 to the stored 1-5 scale by
-- re-deriving from the authoritative 0-100 value in the mirror table. Idempotent:
-- recomputes from shadow_saas_grip_apps.risk_score every run, so re-applying never
-- double-buckets. (Hero rows are written as 1-5 directly by HeroService.)
UPDATE `shadow_saas` `ss`
  JOIN `shadow_saas_grip_apps` `ga` ON `ga`.`grip_id` = `ss`.`grip_id`
  SET `ss`.`risk_score` = CASE
        WHEN `ga`.`risk_score` IS NULL THEN NULL
        WHEN `ga`.`risk_score` <= 0  THEN 0
        WHEN `ga`.`risk_score` <= 20 THEN 1
        WHEN `ga`.`risk_score` <= 40 THEN 2
        WHEN `ga`.`risk_score` <= 60 THEN 3
        WHEN `ga`.`risk_score` <= 80 THEN 4
        ELSE 5 END
  WHERE `ss`.`source` = 'grip';

-- Repair any rows that were already saved with NULL config_value (the bug
-- this migration accompanies could create those for grip_cron_frequency
-- in earlier installs of v2.5.8). NOT NULL prevents future occurrences.
UPDATE `app_config` SET `config_value` = 'daily' WHERE `config_key` = 'grip_cron_frequency' AND `config_value` IS NULL;
UPDATE `app_config` SET `config_value` = '0'     WHERE `config_key` = 'grip_cron_enabled'   AND `config_value` IS NULL;
UPDATE `app_config` SET `config_value` = ''      WHERE `config_key` = 'grip_api_token'      AND `config_value` IS NULL;

-- Shared Shadow SaaS rehydration schedule. Replaces the old grip_cron_* card,
-- which only rendered a copy-paste crontab hint and was never installed. Stored
-- under the cron_* convention so includes/cron-jobs.php's regenerateCrontab()
-- installs it as the hidden `shadow_saas_rehydrate` job (managed on the Shadow
-- SaaS admin page, not the Scheduler page). Seeded DISABLED: the old schedule
-- never actually ran, so we require an explicit opt-in rather than silently
-- starting a now-functional cron job on upgrade. INSERT IGNORE preserves any
-- value an admin already set.
INSERT IGNORE INTO `app_config` (`config_key`, `config_value`, `is_encrypted`) VALUES
  ('cron_shadow_saas_rehydrate_enabled',  '0',         0),
  ('cron_shadow_saas_rehydrate_schedule', '0 2 * * *', 0);

-- Carry over the operator's old cadence preference (grip_cron_frequency) to the
-- new schedule, best-effort, ONLY while the new schedule is still at its seeded
-- default - so it never overwrites a value set on the new card, and re-running is
-- safe. The enabled flag is intentionally NOT migrated (see above). MySQL/MariaDB
-- compatible (multi-table UPDATE + CASE).
UPDATE `app_config` `dst`
  JOIN `app_config` `fr` ON `fr`.`config_key` = 'grip_cron_frequency'
  SET `dst`.`config_value` = CASE `fr`.`config_value`
        WHEN 'hourly'    THEN '0 * * * *'
        WHEN 'every_6h'  THEN '0 */6 * * *'
        WHEN 'every_12h' THEN '0 */12 * * *'
        WHEN 'weekly'    THEN '0 2 * * 0'
        ELSE '0 2 * * *' END
  WHERE `dst`.`config_key` = 'cron_shadow_saas_rehydrate_schedule'
    AND `dst`.`config_value` = '0 2 * * *';

-- shadow_saas.status: ensure the enum includes 'unsanctioned'. Earlier installs
-- only had ('pending','onboarded','dismissed'); the Deny button in shadow-saas.php
-- now flips rows to 'unsanctioned' as a local equivalent of Grip's sanctionTag.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'shadow_saas'
     AND column_name = 'status' AND column_type LIKE '%unsanctioned%') = 0,
  'ALTER TABLE `shadow_saas` MODIFY COLUMN `status` ENUM(''pending'',''onboarded'',''dismissed'',''unsanctioned'') DEFAULT ''pending''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- =============================================================================
-- audit_log lockout index (folded in from the former v2.5.8_login_throttling
-- migration). The PHP-layer login lockout runs IP-keyed COUNT queries against
-- audit_log on every login attempt; without this composite index those queries
-- full-scan a growing table. CREATE INDEX is not natively idempotent on older
-- MariaDB (<10.6), so guard via INFORMATION_SCHEMA to keep re-runs safe.
-- =============================================================================
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'audit_log'
      AND index_name   = 'idx_audit_ip_created'
);
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX idx_audit_ip_created ON audit_log (ip_address, created_at)',
    'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- =============================================================================
-- Multi-language (i18n) support: per-user language preference + app defaults.
-- users.preferred_language stores the user's chosen UI language (NULL = use the
-- system default). The two app_config rows seed the admin-configurable default
-- and the set of languages offered in the profile picker. INSERT IGNORE leaves
-- any values an admin has already customised untouched.
-- =============================================================================

-- users.preferred_language
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'preferred_language') = 0,
  'ALTER TABLE `users` ADD COLUMN `preferred_language` VARCHAR(10) DEFAULT NULL COMMENT ''User UI language code (e.g. en, es, zh-Hans); NULL = use system default''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

INSERT IGNORE INTO `app_config` (`config_key`, `config_value`, `is_encrypted`) VALUES
  ('default_language', 'en', 0),
  ('enabled_languages', '["en","es","it","uk","zh-Hans","hi","fr","pt"]', 0);

-- Cached machine translations of assessment content for the public vendor form
-- (see the same table in master_schema.sql). CREATE TABLE IF NOT EXISTS is
-- naturally idempotent and never touches existing rows.
CREATE TABLE IF NOT EXISTS `assessment_translations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `source_type` VARCHAR(32) NOT NULL COMMENT 'question_text | help_text | options | section_name | section_description | template_name | template_description',
  `source_id` INT UNSIGNED NOT NULL COMMENT 'question/section/template id the text belongs to',
  `language_code` VARCHAR(10) NOT NULL,
  `source_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 of the source text; invalidates the cache when the source changes',
  `translated_text` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_translation` (`source_type`, `source_id`, `language_code`),
  KEY `idx_lookup` (`source_type`, `source_id`, `language_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Vendor assessment submitter attestation: name/title/email/phone, client IP,
-- and a truthfulness attestation flag, captured at final submission (both the
-- full questionnaire submit and the certificate-skip upload). Added together as
-- a set, so a single INFORMATION_SCHEMA guard on the sentinel column keeps
-- re-runs safe on both MySQL and MariaDB.
-- =============================================================================
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_assessments' AND column_name = 'submitter_name') = 0,
  'ALTER TABLE `vendor_assessments`
     ADD COLUMN `submitter_name` VARCHAR(255) DEFAULT NULL,
     ADD COLUMN `submitter_title` VARCHAR(255) DEFAULT NULL,
     ADD COLUMN `submitter_email` VARCHAR(255) DEFAULT NULL,
     ADD COLUMN `submitter_phone` VARCHAR(50) DEFAULT NULL,
     ADD COLUMN `submitter_ip_address` VARCHAR(45) DEFAULT NULL,
     ADD COLUMN `submitter_attested` TINYINT(1) DEFAULT 0,
     ADD COLUMN `attested_at` DATETIME DEFAULT NULL',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- =============================================================================
-- Procurement Cyber Status feature: new "AI Review" onboarding status, per-vendor
-- procurement update notes, and the procurement update email digest. Mirrors the
-- breach-alert config/storage pattern (app_config rows + branding-aware template).
-- =============================================================================

-- Add 'ai_review' to the onboarding status enum. MODIFY COLUMN re-applies the
-- full enum definition; guarded on COLUMN_TYPE so re-runs (and instances that
-- already carry the value) are no-ops. Portable across MySQL and MariaDB.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_onboarding_requests'
     AND column_name = 'status' AND COLUMN_TYPE LIKE '%''ai_review''%') = 0,
  'ALTER TABLE `vendor_onboarding_requests` MODIFY COLUMN `status` enum(''draft'',''submitted'',''in_review'',''ai_review'',''approved'',''rejected'',''inactive'',''evaluation'') DEFAULT ''draft''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Per-vendor procurement update notes (dated text shown in Cyber Status + digest).
CREATE TABLE IF NOT EXISTS `vendor_procurement_updates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL COMMENT 'FK to vendor_onboarding_requests',
  `update_text` text NOT NULL,
  `status_at_update` varchar(20) DEFAULT NULL COMMENT 'Onboarding status at the time the update was provided',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `edited_at` timestamp NULL DEFAULT NULL COMMENT 'When the note was last edited (cyber-status note edit feature)',
  `edited_by` int(10) unsigned DEFAULT NULL COMMENT 'User who last edited the note',
  PRIMARY KEY (`id`),
  KEY `idx_request_created` (`request_id`,`created_at`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `vendor_procurement_updates_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `vendor_onboarding_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_procurement_updates_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- vendor_procurement_updates edit-trail columns for existing installs whose table
-- predates them (the CREATE above only covers fresh installs). Idempotent; runs
-- here -- AFTER the table is guaranteed to exist -- not in the ALTER section above.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_procurement_updates' AND column_name = 'edited_at') = 0,
  'ALTER TABLE `vendor_procurement_updates` ADD COLUMN `edited_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_procurement_updates' AND column_name = 'edited_by') = 0,
  'ALTER TABLE `vendor_procurement_updates` ADD COLUMN `edited_by` INT(10) UNSIGNED DEFAULT NULL AFTER `edited_at`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Procurement digest configuration (comma-separated recipients, like breach alerts).
-- INSERT IGNORE preserves any values an admin has already customised.
INSERT IGNORE INTO `app_config` (`config_key`, `config_value`) VALUES
  ('procurement_digest_enabled', '0'),
  ('procurement_digest_recipients', ''),
  ('procurement_digest_last_run', ''),
  ('cron_procurement_digest_enabled', '1'),
  ('cron_procurement_digest_schedule', '0 7 * * 1');

-- Procurement update digest email template (branding-aware; seed only if missing).
-- The generic-template de-duplication above keeps the {{system_title}} copy.
INSERT IGNORE INTO email_templates (id, template_category, template_key, assessment_template_id, display_name, email_subject, email_body_html, email_body_text, available_variables, is_active) VALUES (25, 'procurement_digest', 'procurement_update_digest', NULL, 'Procurement Update Digest', 'Procurement Update Digest: {{vendor_count}} Vendor(s) In Review', '<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"></head>\n<body style=\"margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #f4f4f4; padding: 20px;\">\n        <tr><td align=\"center\">\n            <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">\n                <tr><td style=\"background-color: {{header_color}}; padding: 30px; text-align: center;\">{{logo_img}}<h1 style=\"margin: 10px 0 0 0; color: {{header_font_color}}; font-size: 20px;\">{{system_title}}</h1></td></tr>\n                <tr><td style=\"padding: 30px;\">\n                    <p style=\"margin: 0 0 10px 0; color: #333; font-size: 15px; line-height: 1.6;\">As of <strong>{{digest_date}}</strong>, <strong>{{vendor_count}}</strong> vendor(s) in Review / AI Review have procurement updates:</p>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; margin: 20px 0;\">\n                        <tr><td style=\"background:#f9fafb;padding:10px 15px;font-weight:bold;color:#374151;font-size:14px;border-bottom:1px solid #e5e7eb;\">Latest Updates</td></tr>\n                        {{update_rows_html}}\n                    </table>\n                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin: 20px 0;\"><tr><td align=\"center\"><a href=\"{{app_url}}\" style=\"display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;\">View Cyber Status</a></td></tr></table>\n                </td></tr>\n                <tr><td style=\"background-color: {{footer_color}}; padding: 20px 30px;\"><p style=\"margin: 0; color: #ffffff; font-size: 12px;\">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>\n            </table>\n        </td></tr>\n    </table>\n</body>\n</html>', 'PROCUREMENT UPDATE DIGEST\n==========================================\n\nAs of {{digest_date}}, {{vendor_count}} vendor(s) in Review / AI Review have procurement updates:\n\n{{update_rows_text}}\nView Cyber Status: {{app_url}}\n\n==========================================\nThis is an automated notification from {{system_title}}. Please do not reply to this email.', '[\"vendor_count\",\"digest_date\",\"update_rows_html\",\"update_rows_text\",\"app_url\",\"header_color\",\"header_font_color\",\"button_color\",\"footer_color\",\"system_title\",\"logo_img\"]', 1);

-- =============================================================================
-- Certificate upload mode: replaces the boolean allow_certificate_upload with a
-- three-state mode on the template (none / skip / minimal) plus a per-question
-- flag marking which questions make up the "minimal" set. allow_certificate_upload
-- is retained and kept in sync (1 for skip and minimal) so existing checks that
-- gate the cert-upload UI keep working. Guarded on INFORMATION_SCHEMA so re-runs
-- are no-ops on both MySQL and MariaDB.
-- =============================================================================
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'assessment_templates' AND column_name = 'certificate_upload_mode') = 0,
  'ALTER TABLE `assessment_templates` ADD COLUMN `certificate_upload_mode` VARCHAR(20) DEFAULT ''none'' COMMENT ''none = full assessment; skip = cert upload completes; minimal = cert upload + selected questions''',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Backfill: any template that already permitted certificate upload is a "skip"
-- template. Only touch rows still on the default so we never clobber an admin's
-- explicit later choice; safe to re-run.
UPDATE `assessment_templates`
   SET `certificate_upload_mode` = 'skip'
 WHERE `allow_certificate_upload` = 1 AND (`certificate_upload_mode` IS NULL OR `certificate_upload_mode` = 'none');

-- =============================================================================
-- Expose the {{assessment_name}} merge tag on the vendor assessment request and
-- reminder templates. On already-deployed instances the INSERT IGNORE seeds above
-- are no-ops (rows exist), so add the token to available_variables here. The token
-- is inserted right after vendor_name to match the seed ordering. Subjects are left
-- untouched so admin-customised subject lines are never clobbered; admins opt in by
-- adding {{assessment_name}} where they want it. Guarded by NOT LIKE so re-runs are
-- no-ops, and covers per-assessment-type variants (assessment_template_id NOT NULL).
-- =============================================================================
UPDATE email_templates
   SET available_variables = REPLACE(available_variables, '["vendor_name","assessment_url"', '["vendor_name","assessment_name","assessment_url"')
 WHERE template_category = 'vendor'
   AND template_key IN ('assessment_request','assessment_7_days_before','assessment_3_days_before','assessment_expiry_day')
   AND available_variables LIKE '["vendor_name","assessment_url"%'
   AND available_variables NOT LIKE '%"assessment_name"%';

-- =============================================================================
-- Custom ACL groups: mark the built-in (system) groups so they can be protected
-- from deletion/renaming while still allowing admins to create custom groups and
-- clone permissions from an existing group. `is_system = 1` means "shipped default,
-- non-deletable, frozen"; `is_system = 0` (the column default) means "admin-created,
-- editable, deletable". Guarded via information_schema so re-runs are no-ops on both
-- MySQL 8.0+ and MariaDB 10.5+.
-- =============================================================================
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'acl_groups' AND column_name = 'is_system') = 0,
  'ALTER TABLE `acl_groups` ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Flag the seven shipped default groups as system groups. Idempotent: the WHERE
-- clause matches by the canonical group_name, and re-running simply re-asserts the
-- value. Custom groups created later are left at is_system = 0.
UPDATE `acl_groups`
   SET `is_system` = 1
 WHERE `group_name` IN (
   'administrator', 'cyber_tprm', 'procurement', 'stakeholder',
   'auditor', 'cyber_grc', 'grc_contributors'
 );

-- =============================================================================
-- Grip Shadow SaaS PII-at-rest encryption.
-- The Grip mirror tables hold personal data (emails, names, org units, manager,
-- and the full raw payload). GripService now encrypts those columns with the
-- app encryption key (config.php) before storing, and the UI decrypts on read.
-- Encrypted blobs are base64 and exceed the old varchar widths, and random-IV
-- ciphertext is neither indexable nor sortable, so for EXISTING databases we:
--   1. drop the now-useless PII indexes (idx_mail / idx_full_name / idx_org_unit),
--   2. widen the PII columns to TEXT.
-- Guarded via information_schema so re-runs are no-ops on MySQL 8.0+ / MariaDB 10.5+.
-- Fresh installs already get TEXT + no PII index from the CREATE TABLEs above.
-- =============================================================================

-- 1) Drop PII indexes (can't index TEXT; meaningless on ciphertext anyway).
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_users' AND index_name='idx_mail')>0,
  'ALTER TABLE `shadow_saas_grip_users` DROP INDEX `idx_mail`', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_users' AND index_name='idx_full_name')>0,
  'ALTER TABLE `shadow_saas_grip_users` DROP INDEX `idx_full_name`', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_users' AND index_name='idx_org_unit')>0,
  'ALTER TABLE `shadow_saas_grip_users` DROP INDEX `idx_org_unit`', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_app_users' AND index_name='idx_mail')>0,
  'ALTER TABLE `shadow_saas_grip_app_users` DROP INDEX `idx_mail`', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- 2) Widen PII columns to TEXT (one ALTER per table; proxy-guarded on the first col).
SET @sql = IF((SELECT data_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_users' AND column_name='mail')='varchar',
  'ALTER TABLE `shadow_saas_grip_users` MODIFY `mail` TEXT DEFAULT NULL, MODIFY `full_name` TEXT DEFAULT NULL, MODIFY `display_name` TEXT DEFAULT NULL, MODIFY `organizational_unit` TEXT DEFAULT NULL, MODIFY `manager_email` TEXT DEFAULT NULL', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
SET @sql = IF((SELECT data_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_app_users' AND column_name='mail')='varchar',
  'ALTER TABLE `shadow_saas_grip_app_users` MODIFY `mail` TEXT DEFAULT NULL, MODIFY `full_name` TEXT DEFAULT NULL, MODIFY `display_name` TEXT DEFAULT NULL, MODIFY `organizational_unit` TEXT DEFAULT NULL, MODIFY `manager_email` TEXT DEFAULT NULL', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
SET @sql = IF((SELECT data_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_apps' AND column_name='primary_contact_email')='varchar',
  'ALTER TABLE `shadow_saas_grip_apps` MODIFY `business_owner` TEXT DEFAULT NULL, MODIFY `primary_contact_name` TEXT DEFAULT NULL, MODIFY `primary_contact_email` TEXT DEFAULT NULL, MODIFY `primary_contact_user_id` TEXT DEFAULT NULL', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Delta-hydration + live-progress columns (added if not already present).
-- roster_signature lets the Grip sync skip re-fetching an app's roster when it
-- hasn't changed; roster_done/roster_total drive the live % on the Last Sync card.
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_apps' AND column_name='roster_signature')=0,
  'ALTER TABLE `shadow_saas_grip_apps` ADD COLUMN `roster_signature` varchar(80) DEFAULT NULL COMMENT ''Delta-hydration cache key'' AFTER `raw_payload`', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_sync_log' AND column_name='roster_done')=0,
  'ALTER TABLE `shadow_saas_grip_sync_log` ADD COLUMN `roster_done` int(10) unsigned DEFAULT 0 AFTER `shadow_saas_upserted`', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_sync_log' AND column_name='roster_total')=0,
  'ALTER TABLE `shadow_saas_grip_sync_log` ADD COLUMN `roster_total` int(10) unsigned DEFAULT 0 AFTER `roster_done`', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Roster-table slimming (one-time space reclaim on upgrade):
--  1) Drop raw_payload - an encrypted full-JSON copy nothing renders that made up
--     ~80% of the table. The DROP rebuilds the table, immediately reclaiming the
--     space (e.g. ~800 MB -> ~180 MB on a fully-hydrated large tenant).
--  2) Drop idx_grip_saas_id - redundant with the PRIMARY KEY's leading column.
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_app_users' AND index_name='idx_grip_saas_id')>0,
  'ALTER TABLE `shadow_saas_grip_app_users` DROP INDEX `idx_grip_saas_id`', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
-- ", FORCE" makes the drop REBUILD the table so the freed space is actually
-- reclaimed. Without it, MariaDB 10.11 drops the column instantly (metadata only)
-- and the raw_payload bytes stay on disk until the rows are next rewritten. The
-- rebuild is one-time (this guard is false once the column is gone) and fast
-- (~16s for a ~300k-row / 1 GB roster; instant for small tenants).
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='shadow_saas_grip_app_users' AND column_name='raw_payload')>0,
  'ALTER TABLE `shadow_saas_grip_app_users` DROP COLUMN `raw_payload`, FORCE', 'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- tprm_results encrypted content columns: TEXT -> MEDIUMTEXT.
-- The FAIR analysis save (fair-analysis.php) encrypts each free-text field
-- before storing it, and base64 + IV/HMAC inflate the value ~1.35x. A large
-- paste (~48 KB+ of plaintext, e.g. a full configuration_data dump) then
-- encrypts past TEXT's 64 KB limit, and under STRICT_TRANS_TABLES the entire
-- save aborts with "Data too long for column 'configuration_data'" -- surfaced
-- to the user as "Failed to save analysis. Please try again." MEDIUMTEXT (16 MB)
-- gives ample headroom. Guarded so the one-time table rebuild runs only while
-- any column is still TEXT (idempotent no-op once widened; also a no-op if the
-- table is absent). MySQL + MariaDB compatible.
SET @needs_widen = (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'tprm_results' AND data_type = 'text'
    AND column_name IN ('msa','scope_of_work','medium_of_data','certifications','compliance',
      'security_governance','incident_response_plan','continuous_monitoring','supply_chain_risk_mgmt',
      'security_awareness_training','vulnerability_management','patch_management','access_controls',
      'data_encryption','network_security','vulnerability_data','configuration_data','compliance_data',
      'risk_assessment','threat_intelligence','vendor_risk_assessment','security_questionnaire',
      'compliance_questionnaire','business_impact','vendor_performance','third_party_vendor_list',
      'third_party_risk_assessment','third_party_security_questionnaire','third_party_compliance_questionnaire',
      'vendor_cyber_insurance_coverage'));
SET @sql = IF(@needs_widen > 0,
  'ALTER TABLE `tprm_results`
     MODIFY COLUMN `msa` MEDIUMTEXT NULL,
     MODIFY COLUMN `scope_of_work` MEDIUMTEXT NULL,
     MODIFY COLUMN `medium_of_data` MEDIUMTEXT NULL,
     MODIFY COLUMN `certifications` MEDIUMTEXT NULL,
     MODIFY COLUMN `compliance` MEDIUMTEXT NULL,
     MODIFY COLUMN `security_governance` MEDIUMTEXT NULL,
     MODIFY COLUMN `incident_response_plan` MEDIUMTEXT NULL,
     MODIFY COLUMN `continuous_monitoring` MEDIUMTEXT NULL,
     MODIFY COLUMN `supply_chain_risk_mgmt` MEDIUMTEXT NULL,
     MODIFY COLUMN `security_awareness_training` MEDIUMTEXT NULL,
     MODIFY COLUMN `vulnerability_management` MEDIUMTEXT NULL,
     MODIFY COLUMN `patch_management` MEDIUMTEXT NULL,
     MODIFY COLUMN `access_controls` MEDIUMTEXT NULL,
     MODIFY COLUMN `data_encryption` MEDIUMTEXT NULL,
     MODIFY COLUMN `network_security` MEDIUMTEXT NULL,
     MODIFY COLUMN `vulnerability_data` MEDIUMTEXT NULL,
     MODIFY COLUMN `configuration_data` MEDIUMTEXT NULL,
     MODIFY COLUMN `compliance_data` MEDIUMTEXT NULL,
     MODIFY COLUMN `risk_assessment` MEDIUMTEXT NULL,
     MODIFY COLUMN `threat_intelligence` MEDIUMTEXT NULL,
     MODIFY COLUMN `vendor_risk_assessment` MEDIUMTEXT NULL,
     MODIFY COLUMN `security_questionnaire` MEDIUMTEXT NULL,
     MODIFY COLUMN `compliance_questionnaire` MEDIUMTEXT NULL,
     MODIFY COLUMN `business_impact` MEDIUMTEXT NULL,
     MODIFY COLUMN `vendor_performance` MEDIUMTEXT NULL,
     MODIFY COLUMN `third_party_vendor_list` MEDIUMTEXT NULL,
     MODIFY COLUMN `third_party_risk_assessment` MEDIUMTEXT NULL,
     MODIFY COLUMN `third_party_security_questionnaire` MEDIUMTEXT NULL,
     MODIFY COLUMN `third_party_compliance_questionnaire` MEDIUMTEXT NULL,
     MODIFY COLUMN `vendor_cyber_insurance_coverage` MEDIUMTEXT NULL',
  'DO 0');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- vendor_shodan_findings.verified (CVE fidelity: 1 = Shodan-confirmed, 0 = version-
-- inferred/low-fidelity shown-but-not-scored, NULL = not applicable). Placed here so
-- the ADD runs after the table's CREATE earlier in this file.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'vendor_shodan_findings' AND column_name = 'verified') = 0,
  'ALTER TABLE `vendor_shodan_findings` ADD COLUMN `verified` TINYINT(1) DEFAULT NULL AFTER `confidence`',
  'DO 0'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- =============================================================================
-- FairTPRM v2.6.2 schema and seed data complete
-- =============================================================================
