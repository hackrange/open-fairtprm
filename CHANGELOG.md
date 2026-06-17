# Changelog

All notable changes to the Fair TPRM & GRC Platform will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this
project adheres to [Semantic Versioning](https://semver.org/).

## [2.6.1] - 2026-06-17

### Added
- **Procurement Cyber Status**: New Procurement → Cyber Status page listing every vendor whose onboarding status is *In Review* or *AI Review*, with a per-vendor procurement update history (5 most recent, paginated).
- **"AI Review" Onboarding Status**: New `ai_review` status for vendors whose services use AI. Entered via a **Force AI Review** link shown next to the *Services Use AI* toggle on the vendor onboarding page (cyber_tprm/admin only).
- **Combined "Review" Pill**: The vendor onboarding list's *In Review* pill is now **Review**, showing both *In Review* and *AI Review* vendors in one view.
- **Provide Procurement with Update**: cyber_tprm/administrators can multi-select vendors (from the onboarding list under the Review pill, or from the Cyber Status page) and record a dated procurement update — with an optional status change applied in the same action. The modal stays open until explicitly closed.
- **Status Notes Pill**: A "Status Notes" pill next to each vendor name shows the update count and opens a read-only viewer of the 5 most recent notes (date, author, status, text). Visible to stakeholders for their own assigned vendors.
- **Procurement Update Email Digest**: Configurable digest (Enable + Procurement Recipients) in Admin → Email Settings → Procurement, with a weekly cron (`cron/procurement-digest.php`) and a **Send digest now** button. Mirrors the breach/cyber alert recipient model.

### Fixed
- **Docker Image Manifest Compatibility**: The published image is now a single Docker schema-2 manifest (built with `--provenance=false --sbom=false`) instead of a BuildKit OCI image index. The in-app upgrader (`admin.php?section=version`) on deployed v2.5.x/v2.6.x containers can again resolve the tag instead of failing with *"Could not fetch manifest from registry"*.
- **Default Email Template Duplication**: De-duplicate generic (branding-aware) email templates on upgraded instances where the NULL-distinct unique index allowed explicit-id `INSERT IGNORE` to seed a second copy.

### Security
- **WAF Whitelisting**: New page and API endpoints (`procurement-cyber-status.php`, `api/procurement-update-save.php`, `api/procurement-notes-list.php`) added to the positive-security allowlist, with a targeted SSTI-rule exemption for the free-text procurement update field.
- All new client-side behavior uses nonce'd scripts and `addEventListener`/data-attribute delegation (CSP compliant), with rotating CSRF tokens on every AJAX call.

## [2.6.0] - 2026-05-16

### Added
- **SAML 2.0 Single Sign-On**: SP-initiated login, ACS, SP metadata, and Single Logout built on the `onelogin/php-saml` library, with an admin configuration panel, IdP group→ACL mappings, and break-glass local login. SAML is off by default.
- **Positive-Security WAF (Lockdown)**: ModSecurity-backed allowlist of permitted paths/prefixes generated from `LockdownService`, with learning and enforcing modes and an admin Block Log.

### Changed
- **Consolidated Database Schema**: A single authoritative, idempotent `sql_updates/v2.6.1.sql` (compatible with both MySQL and MariaDB) replaces the per-version migration chain; the master schema seeds hardened defaults for fresh installs.
- **Hardened Secure Defaults**: SAML disabled, local login enabled with break-glass, and the WAF shipped in a safe default mode.

## [2.5.8] - 2026-04-18

### Security
- **CSP & Cross-Origin Headers**: Added Content-Security-Policy with per-request nonces plus Cross-Origin-Opener-Policy and Cross-Origin-Resource-Policy (same-origin); stripped PHP-fingerprint cache headers.
- **Dependency & Fingerprint Hardening**: Upgraded bundled jQuery 3.2.1 → 3.7.1 (CVE-2019-11358); scrubbed Bootstrap/Popper/Select2/Lightgallery fingerprint banners; locked the `/app/template/` tree and blocked theme demo pages.
- **Authentication Hardening**: PHP-layer login throttling with hybrid (user, IP) account lockout, branded lockout pages, and a paginated Block Log sourced from `audit_log`.
- **Injection & Access Control**: CSRF now returns 403 on a missing token (not just a wrong one); BFLA role checks enforced on POST handlers; DOM-XSS sinks reject `javascript:`/`data:`/`vbscript:` URLs; `data-action` dispatch gated by an allowlist; unknown HTTP methods rejected to close verb-tampering.
- **Apache & Image Hardening**: Anti-fingerprinting/path-enumeration Apache config, removal of the default Debian index, setup script relocated out of the webroot, and the Docker image squashed to a single filesystem layer.

### Fixed
- ModSecurity SSTI rule no longer blocks email template updates; fixed CSRF token desync on assessment actions; the email recipients modal no longer closes on overlay click; inline `onclick` handlers replaced with `addEventListener` for CSP compliance; AI/GRC tasks moved to a background queue so they no longer block web users.

## [2.5.5] - 2026-03-14

### Changed
- **All-in-One Docker Image**: Single-container architecture (Apache2 + PHP 8.5 + MariaDB) with auto-generated credentials, persistent config/branding via bind mount, and a named volume for MariaDB data.
- **New Entrypoint**: Handles the full lifecycle — MariaDB init, password generation, config injection, schema seeding, and SQL migration application.
- **PHP 8.5 Upgrade**: Upgraded from PHP 8.3 to PHP 8.5, with an app-local PHP hardening ini symlinked into conf.d.
- **Multi-Tenant Setup Script**: New `setup` script for isolated tenant deployments with unique ports and Docker subnets.

## [2.5.4] - 2026-02-14

### Added
- **Modernized FAIR Analysis & Results**: Sidebar navigation, top bar, compact grid layout, vendor enrichment on typeahead selection, and a single `api/enrich-fair-vendor.php` call that gathers SRS scores, technologies, documents, and Shodan findings.
- **Combined Scoring with Custom Score Support**: Unified score display across all pages averaging UpGuard, Shodan, and an optional manual Custom score (Admin → SRS → Custom Scoring).
- **Assessment Workflow Rules Engine**: Auto-create follow-up assessments when conditions are met (e.g., onboarding completed and *Uses AI = Yes* → create an AI Risk Assessment).

### Fixed
- Filtered stale Shodan port data past a configurable freshness window; corrected SSLv2/SSLv3 disabled-protocol detection; hardened typeahead search against a missing `custom_score` column.

## [2.5.3] - 2026-01-17

### Added
- **Web-Based Version Upgrades**: Admin → Maintenance → Version page applies upgrades with an automatic pre-upgrade database backup; archive-based (CSV manifest + tar.gz) so no git dependency is required on production servers.
- **Background Rescoring (Cron Mode)**: Optional cron-based UpGuard/Shodan scoring with an on-demand rescore queue, lock-file protection, and AJAX status polling.

## [2.5.2] - 2025-12-20

### Added
- **Contract Expiry Notifications**: Cron-driven procurement reminders with configurable warning windows, auto-created case-management entries, and customizable email templates.
- **Audit Logging & Activity Log**: Comprehensive old/new-value audit trail across vendor, assessment, FAIR, case, document, and admin actions, with a filterable, paginated, CSV-exportable Activity Log.
- **Vendor Documents & Annual Reviews**: Encrypted document storage with expiration monitoring, and automated annual review cycles with 30-day / due / overdue reminders.

## [2.5.1] - 2025-11-22

### Added
- **4th Party Risk**: Technology inventory and supply-chain concentration analysis — search by vendor to see detected technologies or by technology to see blast radius across all vendors.
- **CVE Search**: Severity-filtered, sortable CVE search with NVD links, automatic technology/CVE extraction from existing Shodan scan data (no extra API calls), and CSV export across all views.

## [2.5.0] - 2025-10-25

### Added
- **Vendor Dashboard**: Converted the vendor onboarding page to a card-based read-only dashboard with single-button inline editing and assessment-to-vendor field sync.
- **Cyber Todo & Tier Management**: Consolidated action items (expiring certs, overdue rescores, score drops, pending reviews) with threaded cases and vendor risk-tier assignment with justification and audit trail.

## [2.4.0] - 2025-09-20

### Added
- **SAML SSO & SCIM 2.0 (initial)**: First Service-Provider SAML implementation and an RFC 7643/7644 `/scim/v2/Users` provisioning endpoint with an admin configuration panel and IdP integration guides for Entra ID, Okta, Ping, and Auth0.

## [2.3.1] - 2025-08-16

### Added
- **CVE Waivers**: Mass and inline CVE waive/unwaive with wildcard prefix expansion, scoring integration (waived CVEs excluded from Shodan scoring), and a default rolling 5-year CVE window to reduce banner-based false positives.

## [2.3.0] - 2025-07-19

### Added
- **GRC & TPRM Platform Foundation**: Vendor onboarding, assessment templates and questionnaires, UpGuard/Shodan security ratings (SRS), FAIR-based risk analysis, role-based access control (ACL groups), and the core admin configuration suite.
