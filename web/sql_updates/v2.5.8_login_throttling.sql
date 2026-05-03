-- =============================================================================
-- FairTPRM v2.5.8 - Login lockout defaults + WAF throttle teardown
--
-- Authoritative state for the PHP-layer authentication lockout (max attempts
-- and lockout window) and the cleanup of the deprecated WAF login-endpoint
-- throttle keys. The WAF rule was removed in this commit — it was IP-keyed
-- and volumetric, so a scanner hammering one IP took out every tenant whose
-- egress flowed through the same path. Brute-force / password-spray
-- detection is fully owned by Auth::login()'s three layers
-- (per-(IP, UA) fingerprint, per-(username, IP), per-IP fallback) which are
-- username-aware and DoS-safe by construction.
--
-- Pattern (smart upsert that preserves operator customizations):
--   1. INSERT IGNORE seeds the row on fresh installs.
--   2. A conditional UPDATE only rewrites values that still match the OLD
--      shipped default, so operators who explicitly tuned a value (tighter
--      OR looser) keep their setting.
--   3. DELETE removes the orphaned waf_login_rate_limit_* keys so they
--      don't reappear in any future config dump or audit.
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
-- 3. Tear down the deprecated WAF login-endpoint throttle. Keys are removed
--    so they can't be accidentally re-enabled by a UI toggle, a stale dump,
--    or a config import. SECTION 11 of scanner-detection.conf is no longer
--    generated either.
-- ---------------------------------------------------------------------------
DELETE FROM `app_config`
 WHERE `config_key` IN (
     'waf_login_rate_limit_enabled',
     'waf_login_rate_limit_requests',
     'waf_login_rate_limit_period',
     'waf_login_rate_limit_block'
 );
