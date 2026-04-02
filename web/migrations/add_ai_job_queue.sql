-- Migration: Add AI Job Queue table
-- Moves AI tasks (FAIR analysis, contract pricing, assessment autofill) to a
-- background queue so they don't block Apache web workers.

CREATE TABLE IF NOT EXISTS ai_job_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(50) NOT NULL COMMENT 'fair_analysis, contract_pricing, assessment_autofill',
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    user_id INT UNSIGNED NOT NULL,
    request_payload LONGTEXT NOT NULL COMMENT 'JSON: messages, options, and context needed to run the AI call',
    result_payload LONGTEXT COMMENT 'JSON: full result returned to the frontend',
    error_message TEXT COMMENT 'Error details if failed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME COMMENT 'When processing began',
    completed_at DATETIME COMMENT 'When processing finished',
    INDEX idx_aiq_status (status),
    INDEX idx_aiq_user_id (user_id),
    INDEX idx_aiq_created_at (created_at),
    INDEX idx_aiq_type_status (job_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
