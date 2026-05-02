-- =============================================================================
-- FairTPRM v2.5.8 - Login throttling defaults
--
-- Seeds the WAF login-endpoint rate-limit configuration so POST /login.php
-- is protected against credential stuffing on every deployment, including
-- existing volumes that come up after a `docker compose down && up`.
--
-- Compatible with MySQL 8.0+ and MariaDB 10.5+.
-- Idempotent: INSERT IGNORE preserves any operator-customized values.
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `app_config` (`config_key`, `config_value`, `is_encrypted`, `description`) VALUES
('waf_login_rate_limit_enabled', '1', 0, 'Enable WAF rate limiting on POST /login.php'),
('waf_login_rate_limit_requests', '20', 0, 'Max POST /login.php requests per IP per period'),
('waf_login_rate_limit_period', '60', 0, 'Login rate-limit window in seconds'),
('waf_login_rate_limit_block', '600', 0, 'Login rate-limit block duration in seconds');
