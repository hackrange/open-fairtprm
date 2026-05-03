-- =============================================================================
-- FairTPRM v2.5.8 - Login lockout migration
--
-- Authoritative state for the PHP-layer authentication lockout (max attempts
-- and lockout window) plus cleanup of two dead lockout mechanisms:
--
--   1. The WAF login-endpoint throttle (waf_login_rate_limit_*) — removed
--      because an IP-keyed volumetric counter inevitably DoS's any tenant
--      whose egress shares an IP with an attacker.
--   2. The auth_failed_attempts table — replaced by querying audit_log
--      directly. audit_log already records every login_failed,
--      totp_verification_failed, and *_success event with the IP, user_id,
--      user_agent, username (in new_values), and timestamp — everything the
--      lockout logic needs. Maintaining a parallel insert path was a
--      footgun: when the parallel insert silently failed, the lockout
--      stopped firing while the audit log kept showing failures.
--
-- Pattern (smart upsert that preserves operator customizations):
--   - INSERT IGNORE seeds rows on fresh installs.
--   - Conditional UPDATE only rewrites values that still match the OLD
--     shipped default, so operators who explicitly tuned a value keep it.
--   - DELETE removes orphaned config keys.
--   - DROP TABLE removes the obsolete schema.
--   - INDEX additions speed up the new audit_log lockout queries.
--
-- Re-applied automatically when this file's md5 changes (entrypoint hash
-- tracking). Compatible with MySQL 8.0+ and MariaDB 10.5+. Idempotent.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Seed PHP-layer auth lockout defaults on fresh installs.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `app_config` (`config_key`, `config_value`, `is_encrypted`, `description`) VALUES
('lockout_duration',   '300', 0, 'Account lockout duration in seconds'),
('max_login_attempts', '5',   0, 'Maximum failed login attempts before lockout');

-- ---------------------------------------------------------------------------
-- 2. Push the new lockout window default to deployments still running the
--    prior original (1800s = 30 min). Operators who customized keep theirs.
-- ---------------------------------------------------------------------------
UPDATE `app_config` SET `config_value` = '300'
 WHERE `config_key` = 'lockout_duration' AND `config_value` = '1800';

-- ---------------------------------------------------------------------------
-- 3. Tear down the deprecated WAF login-endpoint throttle keys.
-- ---------------------------------------------------------------------------
DELETE FROM `app_config`
 WHERE `config_key` IN (
     'waf_login_rate_limit_enabled',
     'waf_login_rate_limit_requests',
     'waf_login_rate_limit_period',
     'waf_login_rate_limit_block'
 );

-- ---------------------------------------------------------------------------
-- 4. Drop the obsolete auth_failed_attempts table. The lockout logic now
--    reads from audit_log directly, so this table no longer has any
--    writers OR readers.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `auth_failed_attempts`;

-- ---------------------------------------------------------------------------
-- 5. Index audit_log for the new lockout queries. Every login attempt now
--    runs three counts filtered by ip_address (+ optionally user_agent or
--    JSON-extracted username). Without an index on ip_address the queries
--    full-scan a growing audit_log table on every login. The composite
--    (ip_address, created_at) covers both the equality filter and the
--    time-window filter that ALL lockout queries share.
--
--    CREATE INDEX is not natively idempotent on older MariaDB (no IF NOT
--    EXISTS until 10.6+); guard via INFORMATION_SCHEMA so re-applying this
--    migration is safe across versions.
-- ---------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'audit_log'
      AND index_name   = 'idx_audit_ip_created'
);
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX idx_audit_ip_created ON audit_log (ip_address, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
