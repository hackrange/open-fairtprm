-- =============================================================================
-- FairTPRM v2.5.8 - Login throttling and lockout defaults
--
-- Seeds the WAF login-endpoint rate-limit configuration AND the auth-layer
-- account lockout duration so a `docker compose down && up -d` on existing
-- deployments automatically picks up the looser, less-DoS-prone defaults.
--
-- Pattern (smart upsert that preserves operator customizations):
--   1. INSERT IGNORE seeds the row on fresh installs.
--   2. A conditional UPDATE only rewrites values that still match the OLD
--      shipped default, so operators who explicitly tuned a value (tighter
--      OR looser) keep their setting. Anything matching the prior default
--      is treated as untouched and gets the new default.
--
-- Re-applied automatically when this file's md5 changes (entrypoint hash
-- tracking), so future default tweaks ship by editing this file alone.
--
-- Compatible with MySQL 8.0+ and MariaDB 10.5+. Idempotent.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Seed rows on fresh installs. INSERT IGNORE no-ops if a row with the
--    same config_key already exists, regardless of value.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `app_config` (`config_key`, `config_value`, `is_encrypted`, `description`) VALUES
('waf_login_rate_limit_enabled',  '0',   0, 'Enable WAF rate limiting on POST /login.php (default off — login throttling is handled in PHP)'),
('waf_login_rate_limit_requests', '40',  0, 'Max POST /login.php requests per IP per period'),
('waf_login_rate_limit_period',   '60',  0, 'Login rate-limit window in seconds'),
('waf_login_rate_limit_block',    '120', 0, 'Login rate-limit block duration in seconds'),
('lockout_duration',              '300', 0, 'Account lockout duration in seconds'),
('max_login_attempts',            '5',   0, 'Maximum failed login attempts before lockout');

-- ---------------------------------------------------------------------------
-- 2. Push new defaults to deployments still running the prior originals.
--    Each WHERE clause matches the OLD shipped default; operators who set
--    something else keep their value untouched.
-- ---------------------------------------------------------------------------

-- WAF login rate limit: original was 20 req / 60s window / 600s block.
UPDATE `app_config` SET `config_value` = '40'
 WHERE `config_key` = 'waf_login_rate_limit_requests' AND `config_value` = '20';

UPDATE `app_config` SET `config_value` = '120'
 WHERE `config_key` = 'waf_login_rate_limit_block'    AND `config_value` = '600';

-- Auth-layer lockout window: original master-schema default was 1800s (30 min).
-- Lower to 300s (5 min) so a fumbling user isn't punished beyond what's needed
-- to crush a credential-stuffer's effective rate.
UPDATE `app_config` SET `config_value` = '300'
 WHERE `config_key` = 'lockout_duration' AND `config_value` = '1800';

-- WAF login-endpoint throttle: was originally enabled (value '1'). Now defaults
-- to disabled because the WAF rule was a coarse IP-only counter that produced
-- cross-tenant 429s when Apache's mod_remoteip hadn't rewritten REMOTE_ADDR
-- before mod_security2's phase:1 ran (ip collection keyed on the upstream
-- proxy IP, shared across every tenant behind it). Login brute-force /
-- password-spray detection is fully handled at the PHP layer in Auth::login()
-- via three progressively narrower lockouts that are username-aware and
-- correctly scoped to the real client IP. Operators who explicitly disabled
-- or re-enabled the WAF rule keep their setting.
UPDATE `app_config` SET `config_value` = '0'
 WHERE `config_key` = 'waf_login_rate_limit_enabled' AND `config_value` = '1';
