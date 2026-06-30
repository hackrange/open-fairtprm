# Changelog

All notable changes to the Fair TPRM & GRC Platform will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this
project adheres to [Semantic Versioning](https://semver.org/).

## [2.6.2] - 2026-06-28

> **Pre-release.** `2.6.2` is a bleeding-edge line ahead of the stable `2.6.1`
> release; it ships to the `v2.6.2` image tag and the `2.6.2` branch only.

### Added

- **Access control - custom ACL groups.** Alongside the shipped default groups
  (administrator, cyber_tprm, procurement, stakeholder, auditor, cyber_grc,
  grc_contributors), super administrators can create custom groups, **copy
  ("clone") permissions** from any existing group, and tune a per-permission
  matrix with **Read vs Read/Write** capability presets. The default groups are
  protected - non-deletable, name-locked, and their permission set is read-only.
- **Grip Shadow SaaS - Live vs Local (Hydrated/cached) data source.** A toggle to
  serve Grip data either live from the API per view or entirely from the hydrated
  local database snapshot.
- **Grip Shadow SaaS breach feed.** Grip "Security Incident Detected" alerts flow
  into Breach / Cyber Alerts, with impacted-user counts, originating Grip SaaS app,
  and affected domain; un-onboarded apps surface as "Shadow SaaS / N users", and a
  breach drills into the affected-users roster (fast paged drill-down with filters).
  A Shadow SaaS alert is promoted to a vendor breach if that app is later onboarded.
- **Breach / Cyber Alerts bulk actions.** A multi-select toolbar on the Breach
  Alerts list to acknowledge, mark false-positive, or delete several alerts at once
  (delete is admin-only).
- **Grip "Truncate Data" admin action.** One click in Shadow SaaS → Grip → Last Sync
  clears all locally-served Grip data (mirror tables, Grip-projected `shadow_saas`
  rows, and the curated telemetry stamped on vendor records), leaving non-Grip data
  and sync history intact.
- **Grip sync live progress.** The Shadow SaaS → Grip → Last Sync card shows a live
  roster-hydration percentage (apps processed / total) above the Stop Sync button,
  updating while a sync runs.
- **Grip PII encrypted at rest.** Personal data in the Grip mirror tables (emails,
  names, organizational unit, manager, primary contact, and the raw payload) is
  encrypted with the application key (`config.php`) before being written, and
  decrypted on read for the web GUI and API. Columns widen to `TEXT`, their plaintext
  indexes are dropped, and sorting on those columns moves to the application layer.
- **SecurityScorecard (SSC) rating.** An SSC letter-grade column on the Shadow SaaS
  and vendor SRS lists, and on the vendor onboarding **SaaS Data** tab (Grip-sourced).
- **Vendor onboarding "SaaS Data" tab.** Vendors matched to a Grip Shadow SaaS app
  get a read-only SaaS Data tab on their onboarding record. It surfaces the curated
  Grip telemetry stamped during sync: first-discovered date, active-account count
  (linking to the affected-users drill-down), risk and category signals, and the
  SecurityScorecard rating, all without leaving the vendor page.
- **Vendor Action Plan.** A per-vendor Action Plan tab plus a Vendor Remediation
  Schedule cron: scheduled actions with due dates, multi-assignee support, email
  notifications (editable recipient addresses), and editable/deletable status notes.
- **Onboarding role-based field gating.** Per-section and per-question/field
  **visibility** and **edit** grants (`visible_roles` / `editable_roles`), with
  custom onboarding fields editable on the vendor page and hidden-by-default.
- **Custom Data field types.** Multi-select (checkbox / button group), Phone, and
  VAT question types render with their dedicated widgets.
- **Custom vendor onboarding fields.** Onboarding templates can define custom
  fields that have no standard vendor column; their values are captured per vendor
  and editable on the vendor's **Custom Data** tab. Visibility and editing follow
  the onboarding role-based field gating above.
- **Custom onboarding fields in export and the API.** Custom field values are
  surfaced beyond the UI: the vendor onboarding **CSV export** adds one
  `custom:<field_name>` column per field (the union across exported vendors), and
  the **REST API** single-vendor response returns a `custom_onboarding_data` array
  (field_name, label, value, type, section, template_name). Both read from the same
  source as the Custom Data tab and apply the same role-based visibility.
- **4th-Party Risk.** Surface subprocessor concentration across your vendors and
  act on it: bulk-assign assessments that send emails and track reminders, plus a
  **Send Assessment** flow that picks the vendors leveraging a given subprocessor
  and sends them an assessment survey (an RFI-style request for information).

### Changed

- **Grip Local (Hydrated/cached) mode is now strictly database-only.** Pages serve
  exclusively from the hydrated snapshot with **no per-view Grip API calls**; an
  empty snapshot renders empty until the next sync, rather than lazily re-fetching.
- **Grip sync hydrates per-app user rosters in Local mode, incrementally.** A sync
  snapshots the roster for **all** apps with users (not just breached/on-demand
  apps), so Local mode serves every roster from the DB - but re-fetches an app's
  roster only when it has **changed** (keyed on user counts + activity timestamps
  already returned by the app list). Unchanged apps are skipped with **no API
  call**, cutting a steady-state re-sync from ~one-call-per-app down to just the
  apps that changed. Roster fetches now **retry with exponential backoff** and honor
  `Retry-After` on timeouts / 429 / 5xx, are gently throttled to ease load on the
  Grip API, and **replace each roster atomically** so a failed fetch keeps the
  cached roster instead of clearing it. Switching Live ↔ Local never deletes the
  cache (only the Truncate action does).
- **MariaDB tuning - InnoDB buffer pool 128M → 512M.** The stock pool cached only a
  fraction of a data-heavy tenant's DB; 512M keeps the hot working set in RAM. The
  pool is allocated lazily, so the many small tenants sharing the image are
  unaffected.
- **Grip roster table slimmed.** The per-app roster no longer stores the unused
  encrypted `raw_payload` copy (the flattened columns carry everything rendered),
  and a redundant index was dropped - shrinking a fully-hydrated roster table ~4-5x
  (≈1 GB → ≈225 MB on a large tenant; the upgrade reclaims the space in place).
- **`grip-saas-users`** resolves the app name for onboarded vendors, with a live
  fallback in Live mode.
- **Version cutover to 2.6.2** - Dockerfile reconcile, REST API version aligned to
  2.6.2, and removal of a dead seed loop.

### Fixed

- **FairSRS false positives reduced.** The Shodan-based security rating no longer
  reports "Server Version Disclosed" for managed-edge `Server` headers the vendor
  cannot change (e.g. AWS ELB's `awselb/2.0`, Cloudflare, CloudFront). It drops all
  findings for RFC1918 / reserved IPs, which are not routable from the Internet. And
  it discards CVEs attributed to multi-tenant CDN edges (Cloudflare, CloudFront,
  Akamai, Fastly, Imperva, Sucuri, StackPath), where Shodan fingerprints the shared
  edge fleet rather than the vendor's origin. Single-tenant load balancers (awselb,
  bigip) still keep their proxied-port findings for triage.
- **FairSRS: infrastructure-mitigated CVEs on managed-cloud hosts.** On enterprise-
  cloud (AWS / Azure / GCP / etc.) and managed-edge hosts, a curated set of
  version-inference / edge-mitigated CVEs (HTTP/2 DoS family including Rapid Reset
  CVE-2023-44487 and the HTTP/2 Bomb CVE-2026-49975, Slowloris, the nginx-resolver
  off-by-one CVE-2021-23017, and ALPACA CVE-2021-3618) is no longer counted, since
  the provider patches or mitigates them at the platform layer or they require
  preconditions a managed host does not expose. Genuinely origin-exploitable CVEs on
  the same host are kept. The list is extendable at runtime via the app_config key
  `shodan_infra_mitigated_cves` (JSON array of CVE IDs).
- **Grip rosters silently blanked on API timeouts.** In Local mode a per-app roster
  was deleted *before* its re-fetch, so a Grip API timeout mid-sync left the app
  with an empty roster (and a full hydration could blank hundreds of apps). Rosters
  are now fetched first and replaced atomically, and a hard fetch failure preserves
  the existing rows.
- **Scheduled-action email link** now deep-links to the vendor's Action Plan tab.
- **Grip SaaS users page**: the Dashboard nav link rendered the raw translation key
  `chrome.dashboard` instead of a localized label; the key is now defined in all 8
  locales.
- Custom Data badge rendering and the vendor-srs-list View Score / View Vendor links.
- **Custom onboarding field values silently failed to save** while the UI reported
  success. When a vendor had no onboarding assessment (or only one for a different
  onboarding template), the save path created a holder against the lowest-id
  onboarding template, which did not contain the edited custom questions, so every
  write found no target and persisted nothing - yet "Custom data updated
  successfully" was still shown. Saves are now anchored to each field's own
  template_id (a holder is ensured per template), and success is reported only when
  at least one value is actually written.

### Security

- **Admin section handlers** refuse direct web access (must be dispatched via
  `admin.php`).
- **BOLA/IDOR remediation** across object-access paths, plus OS package updates.

## [2.6.1] - 2026-06-17

> **Later additions within 2.6.1 (2026-06-21)**
>
> **Added**
> - **Hero Shadow SaaS Integration**: New **HERO Security** provider for the Shadow SaaS list, parallel to Grip and **mutually exclusive** with it (enabling one disables the other). OAuth 2.0 client-credentials auth, cursor pagination, and per-vendor discovery via `HeroService` and the `shadow_saas_hero_*` mirror tables. Each vendor projects into the shared Shadow SaaS list with a **1–5 risk score** derived from its worst open HERO issue, a **Relationship Manager** (the vendor's highest-email-activity contact), a **Number of Users** count, and a Risk Type summary. Admin → Shadow SaaS gains a **Hero** tab and a HERO Security Connection card; the bundled HERO OpenAPI spec is included for reference.
> - **Shared Scheduled Rehydration as a real cron job**: The Shadow SaaS rehydration schedule is now an editable cron expression installed into the system scheduler automatically (single source of truth in `includes/cron-jobs.php`, shared by `scheduler.php` and `sync-crontab.php`). It is shared by whichever provider (Grip or Hero) is enabled, with a live plain-English schedule summary.
> - **Stop/Abort running sync**: The Last Sync card shows a **Stop Sync** button while a sync is running; it cooperatively cancels the run (Grip or Hero) and releases its lock.
> - **Config-file local-login override**: `auth.local_login_enabled` in `config.php` is a no-SQL break-glass control that takes precedence over the database toggle, plus a matching **Allow local login** checkbox on Admin → SAML.
>
> **Changed**
> - **Shadow SaaS risk scores are stored 1–5** in the database for both providers (Grip now buckets its 0–100 API score at write time via an idempotent migration; Hero derives 1–5 from issue severity) rather than converting only at render.
> - **HERO rate-limit pacing**: the Hero sync paces per-vendor requests (~1.1s) and honours `Retry-After` with backoff to stay within HERO's ~60 requests/minute account-wide limit; per-vendor users are sampled first-page-only to keep a full refresh tractable.
> - **Documentation**: renamed the *Grip Security Integration* section to **Grip Shadow SaaS Integration** and added a **Hero Shadow SaaS Integration** section (TOC, nav, glossary, FAQ).
>
> **Fixed**
> - **Scheduled Rehydration was orphaned**: it previously only rendered a copy-paste crontab hint and was never installed, so the schedule did nothing (only *Run Now* worked). It is now registered with the entrypoint and runs on the configured schedule.
> - **Shadow SaaS admin tab no longer resets**: Save/Test/Run Now keep the admin on the current provider tab instead of bouncing back to Grip.
>
> **Security**
> - **API documentation locked down**: `/api/v2/swagger`, `/api/v2/openapi`, and `/api/v2/postman` were publicly accessible and now require an authenticated **admin session or a valid API token**. The bundled Grip/Hero OpenAPI & Postman files are served only through the admin-gated `reference-doc.php`; direct access to the static `app/*-reference/` files is denied (`.htaccess` + Apache).

> **Later additions within 2.6.1 (2026-06-20)**
>
> **Added**
> - **Phone question type**: Template Builder field with a country-code + flag selector (US first, then alphabetical) that normalises any input to canonical E.164 (e.g. `+13144445544`). The default Vendor Onboarding Request form's primary contact phone and the assessment submitter-attestation phone now use it.
> - **VAT question type**: EU VAT field requiring double entry (typo guard), stored canonical (e.g. `DE123456789`), with free live validation against the official EU **VIES** service (advisory — saved even when unverifiable, with a clear notice). On a confirmed number an info (ⓘ) button opens a modal with the registered company name/address. The VIES call uses a generous timeout with one retry and distinguishes "temporarily unavailable" from "not valid".
> - **`vat_number` vendor field**: New column on `vendor_onboarding_requests` (idempotent migration + `field_references` seed), shown on the vendor page; mappable from a VAT question (auto-selected when the VAT type is chosen). An **"+ Add VAT"** button appears when none is on file.
> - **Vendor quick search by VAT** (column-guarded), and **large database backup/restore** support (multi-GB dumps and ~1 GB rows).
> - **Procurement Onboarding scoring banner**: when *Procurement Onboarding = No*, a banner notes that automated vendor scoring is disabled until it is completed — on the vendor page and beneath the assessment question, toggling live.
>
> **Changed**
> - **Assessment submit** now validates that all required questions are answered *before* prompting for the submitter attestation (new `validate_only` preflight), so a missing field no longer forces re-entering attestation.
> - **Quick vendor search ranking**: direct vendor name/domain/VAT matches are surfaced ahead of rows matched only via a shared stakeholder email — fixing a case where a stakeholder email on the org's own domain matched ~all vendors and buried the searched vendor past `LIMIT 10`.
> - **Large-restore tuning**: MariaDB `max_allowed_packet` 1G, larger redo log and network timeouts; PHP upload/post limits and Apache request timeout raised; backup/restore no longer abort on the default execution-time/memory limits.
>
> **Fixed**
> - **Intermittent assessment autosave "Error saving"**: the per-request CSRF token rotation raced with concurrent autosaves, occasionally rejecting an in-flight request with a stale-token 403.
>
> **Security**
> - The high-frequency, UUID-authenticated assessment AJAX endpoints (autosave, batch-save, file upload) now **validate the CSRF token without rotating it** (stable per-session token) to remove the concurrency race; the token remains secret, per-session, and required. (Other endpoints still rotate per request.)
> - VIES lookups contact only the fixed EU host (no user-controlled URL — no SSRF), with strict EU-VAT format validation and a per-session rate limit.

### Added
- **Procurement Cyber Status**: New Procurement → Cyber Status page listing every vendor whose onboarding status is *In Review* or *AI Review*, with a per-vendor procurement update history (5 most recent, paginated).
- **"AI Review" Onboarding Status**: New `ai_review` status for vendors whose services use AI. Entered via a **Force AI Review** link shown next to the *Services Use AI* toggle on the vendor onboarding page (cyber_tprm/admin only).
- **Combined "Review" Pill**: The vendor onboarding list's *In Review* pill is now **Review**, showing both *In Review* and *AI Review* vendors in one view.
- **Provide Procurement with Update**: cyber_tprm/administrators can multi-select vendors (from the onboarding list under the Review pill, or from the Cyber Status page) and record a dated procurement update — with an optional status change applied in the same action. The modal stays open until explicitly closed.
- **Status Notes Pill**: A "Status Notes" pill next to each vendor name shows the update count and opens a read-only viewer of the 5 most recent notes (date, author, status, text). Visible to stakeholders for their own assigned vendors.
- **Procurement Update Email Digest**: Configurable digest (Enable + Procurement Recipients) in Admin → Email Settings → Procurement, with a weekly cron (`cron/procurement-digest.php`) and a **Send digest now** button. Mirrors the breach/cyber alert recipient model.
- **Assessment Pre-Fill from Prior Answers**: When a vendor receives a new assessment, it is automatically pre-populated with the responses from their most recent prior assessment, so vendors only update what has changed instead of re-answering from scratch.
- **Inline WAF Disable/Enable on Email Templates**: Each email template tab (Vendor, Stakeholder, Procurement, GRC, Breach/Cyber Alerts) now shows a helper banner with the current WAF status and one-click **Disable WAF** / **Enable WAF** buttons (CSRF-protected and audited) — an escape hatch if a WAF content rule ever blocks a template save.

### Internationalization
- **Full-App Multi-Language UI**: Complete interface localization across every page in 8 languages — English, Spanish, French, Italian, Portuguese, Ukrainian, Hindi, and Simplified Chinese — via a lightweight `t()` translation helper backed by per-language string catalogs (`includes/i18n/`).
- **Per-User Language Preference**: Each user selects their interface language from their profile (`users.preferred_language`); administrators set the system default and the set of offered languages (`default_language`, `enabled_languages`).
- **Assessment Content Translation**: Vendor assessment questionnaires use browser-native translation, backed by a cached machine-translation layer (`assessment_translations`) keyed by a SHA-256 source hash so cached strings invalidate automatically when the source text changes.

### Fixed
- **Docker Image Manifest Compatibility**: The published image is now a single Docker schema-2 manifest (built with `--provenance=false --sbom=false`) instead of a BuildKit OCI image index. The in-app upgrader (`admin.php?section=version`) on deployed v2.5.x/v2.6.x containers can again resolve the tag instead of failing with *"Could not fetch manifest from registry"*.
- **Default Email Template Duplication**: De-duplicate generic (branding-aware) email templates on upgraded instances where the NULL-distinct unique index allowed explicit-id `INSERT IGNORE` to seed a second copy.
- **Email Template Save Blocked by WAF (403)**: Saving an email template (`admin.php?section=email`) was denied by the ModSecurity SSTI rule (`400026`) because it matched the `{{placeholder}}` markers in the body. The admin exemption never fired — it tested `REQUEST_URI`, which includes the `?section=email` query string, so `@streq /admin.php` never matched. The exemption now matches `REQUEST_FILENAME` (path only) and also covers the XSS POST-body rule (`400031`), so HTML/`{{placeholder}}` template content saves correctly; SQLi/RCE body detection remains active. The scanner config regenerates from source on container start, so the fix applies on restart.

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
