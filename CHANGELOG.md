# Changelog

All notable changes to the Fair TPRM & GRC Platform will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this
project adheres to [Semantic Versioning](https://semver.org/).

## [2.6.2] - 2026-06-28 - Current

> **Stable.** `2.6.2` is the current stable release, superseding `2.6.1`. It
> ships to the `v2.6.2` image tag and to both the `master` and `2.6.2` branches.

### Added

- **Questionnaire respondent for a vendor.** A vendor's Custom Data tab has a
  Questionnaire Respondent name and email with a "use as the default recipient
  for this vendor's assessments" checkbox. Assessments sent to the respondent
  greet them by their own name. When ticked, new assessments for the vendor
  go to that address instead of the primary contact: the New Assessment form,
  assessments sent from Action Plans, and follow-up assessments triggered by
  another assessment. The Custom Data tab now shows for every vendor so the
  field is always there. Changes are audited.

- **Privacy risk increase alerts, by email.** Admin > Email has a new
  Shadow SaaS tab: enable the alert, enter recipients as a comma-separated
  list, and choose to alert on any rise or only on rises to High. After each
  review run, one digest email lists every vendor whose privacy or file-sharing
  rating went up, with the quoted evidence and a link to Shadow SaaS. A first
  rating never alerts, so the first full pass does not flood inboxes. The
  email's subject and look are an editable template like the other tabs, with
  reset to default and a test send.
- **AI privacy ratings as Grip labels.** With Grip write-back on, apps are
  labeled "FairTPRM: Privacy High / Medium / Low" and "FairTPRM: File Sharing
  High / Medium / Low" from the review. Labels change when a rating changes,
  Unknown ratings get none, and other labels are never touched. A switch on the
  Grip settings turns just these labels off.
- **Opened Shadow SaaS rows stay in place.** Opening a row (Applications and
  New) scrolls it so the row and its details are on screen, and after an
  action reloads the page the row you had open opens again where it was.
- **Review privacy now, for the vendors you pick.** Shadow SaaS (Applications
  and New) has a Review Privacy Now action for selected rows, and a vendor's
  SaaS Data tab has a Review privacy now button, including for vendors never
  reviewed. Requests (up to 25 at a time) run in the background straight away,
  re-read the policy even if it has not changed, and show "Review queued"
  until the result lands. Administrators and Cyber TPRM only; audited.
- **Privacy results as Risk Types.** The Shadow SaaS Risk Type filter offers
  Privacy: High / Medium / Low and File Sharing: High / Medium / Low, the Risk
  Type column lists them, and searching "Privacy: High" or "file sharing" finds
  them. They are computed from the review, not stored in the Risk Type field.
- **Waive a failing compliance control.** On Compliance Posture, a failing
  control can be waived with a written compensating control (for example a
  DLP gateway that already blocks card numbers and SSNs) and an optional
  expiry of up to three years. A waived control shows as Waived with its
  justification, offers no Add to Risk Register, and its checks leave that
  framework's score. Waivers can be revoked; both are audited. Administrators
  and Cyber TPRM.
- **AI vendor privacy and file-sharing review.** A new "Use AI" setting on
  Admin > Shadow SaaS > Grip (available when an AI platform is enabled) reads
  each vendor's own privacy policy and terms and rates two risks. Privacy risk
  covers whether the vendor trains AI on customer data, claims a broad license
  to it, sells or shares it for advertising, or keeps it indefinitely. Sharing
  risk covers whether employees can upload files and whether those files are
  processed by AI or can be shared outside the company. Every Shadow SaaS app
  is reviewed, including Tolerated and Unsanctioned ones, and so is every
  onboarded vendor. Findings only count when the quoted sentence is found on
  the vendor's own page; Grip's privacy answers fill gaps, and file-feature
  answers from the AI's product knowledge are labeled as such. Ratings come
  from fixed rules, not from the AI. Results show as Privacy and Sharing badges
  on Shadow SaaS rows, with the quotes and links in the row details and on the
  vendor's SaaS Data tab. Vendors are re-checked every 30 days (configurable);
  when the policy text has not changed the AI step is skipped, so ongoing cost
  follows policy changes rather than vendor count. Policies are found from
  Grip's links and the vendor's own site, so it works on Amazon Bedrock, which
  has no web search; AI web search is an optional extra on platforms that
  support it. The Shadow SaaS File Sharing field is filled from the review
  unless a person set it.
- **AI usage, cost and monthly limit.** Every AI call in the platform is now
  logged with its feature, model, tokens and cost. Admin > API Integration
  takes your provider's rates (per 1M input and output tokens and per 1,000
  web searches; blank uses the model's list price) and shows usage by feature,
  by month and by day, with a month-end projection. A monthly limit can be set
  in US dollars or tokens: when it is reached, all AI stops until the month
  resets. Background AI jobs can also pause at the warning level (default 80%)
  to keep the rest for people using AI.

- **Pick a vendor's subprocessor from Shadow SaaS.** In a vendor's
  Subprocessors tab, the Add Subprocessor search now also lists Shadow SaaS
  apps that are not onboarded (for example one you tolerate because only a
  vendor uses it), each with its status (Pending, Tolerated or Dismissed,
  Unsanctioned). Picking one fills the name and domain. Shown to
  administrators and Cyber TPRM. The link then shows on both sides: the
  subprocessor card carries a "Shadow SaaS: <status>" badge that opens the
  entry, and the Shadow SaaS row (Applications and New) carries a
  Subprocessor badge and lists, in its details, the vendors it is a
  subprocessor of. Matching is by domain, or by exact name when the
  subprocessor has no domain.
- **Shadow SaaS sorts by Avg Score.** The Avg Score column header now sorts
  the whole list (across pages) by the same score the column shows, with
  unscored apps last in either direction.
- **Waive Grip posture findings and ITDR threats, with a required
  justification.** Administrators and Cyber TPRM can waive selected findings
  of a posture check, or an ITDR threat, by writing why it is accepted (at
  least 10 characters) and optionally setting an expiry (default one year, at
  most three). Waived findings show the justification, who waived them and
  when, and can be revoked. A failing check whose current findings are all
  waived shows as Waived: it leaves the Fail list and the posture score (like
  Grip's own Excluded) and no longer fails its Compliance Posture controls.
  Coverage is re-checked against Grip's live findings after every change and
  on every sync, so a new finding on a waived check makes it fail again, and
  an expired waiver stops applying on its own. Waived threats show as waived
  and leave the exposed-identity counts. Every waive and revoke is audited;
  auditors see waivers but cannot change them. Waivers are kept in FairTPRM
  because Grip's public API has no exclusion write.
- **Newly detected Grip apps reach Shadow SaaS within minutes.** A new
  "Grip New-App Discovery" job (every 15 minutes by default) reads Grip's
  "New Unmanaged App / AI App Detected" alerts and adds any app the mirror
  does not hold yet, so a new AI tool no longer waits for the nightly
  rehydration. It costs a couple of API calls per run, never overlaps a full
  sync, and is enabled and scheduled under Admin > Shadow SaaS > Grip, next to
  the rehydration schedule.
- **AI apps are flagged across Shadow SaaS.** An "AI" badge marks every app
  whose Grip AI depth (or the "AI: ..." part of its risk type) is anything
  other than "No AI", on the Applications list and the New tab, and a new AI
  filter shows apps that use AI or those that do not. Grip's AI depth level
  and description are stored on each Shadow SaaS row (backfilled from the
  Grip mirror on upgrade) and shown in the row details.
- **Shadow SaaS New tab rows open the full details.** Clicking an app on the
  New tab opens the same details panel as the Applications list (scores,
  breach history, MFA, usage, AI, users link); onboarded vendors link to the
  vendor record.
- **Grip breach history, MFA enforcement and personal/work usage on Shadow
  SaaS.** The Grip sync now stores each app's breach history, whether the app
  actually enforces MFA for your users (Enabled, Not Enabled, Partial,
  Unknown) and how it is used (work, or mixed personal and work). Shadow SaaS
  shows "Personal + work" and "No MFA" / "MFA partial" badges, adds MFA
  (Grip) and Usage (Grip) filters, and lists the breach history (date, types,
  source link) in the row details. "Breaches in 3 years" is now filled from
  Grip, so the onboarding PII/PHI justification uses Grip's record.
- **Grip justification requests.** Admins and Cyber TPRM can select users on
  an app's Grip users page and ask them, by Email, Slack or Microsoft Teams,
  why they use the app. Each request and its status (Sent, Received,
  Responded, Canceled, Reminded) is shown per user and refreshed on every
  sync. User ids are re-checked against the app's roster on the server, at
  most 50 users go per request, auditors stay read-only, the user email is
  encrypted at rest, and a send is never retried after a timeout so nobody is
  messaged twice.
- **TPRM status written back to Grip (opt-in).** With "Write TPRM status back
  to Grip" turned on under Admin > Shadow SaaS > Grip, FairTPRM labels Grip
  apps "FairTPRM: Onboarded" plus "FairTPRM: Tier N" for onboarded vendors,
  and "FairTPRM: Tolerated", "FairTPRM: Denied" or "FairTPRM: Under Review"
  for Shadow SaaS decisions, and sets each onboarded vendor's relationship
  manager as the app's Grip primary contact. Changes push immediately on
  Shadow SaaS actions and are reconciled on every sync (only differences are
  written, at most 300 writes per run), so tier and relationship manager edits
  made anywhere reach Grip. Only labels starting with "FairTPRM: " are ever
  touched. The admin tab lists vendors whose relationship manager could not be
  set and why (not an email or FairTPRM user, or not a Grip user).
- **Deny marks the app Unsanctioned in Grip.** Deny now updates Grip like
  Dismiss and Restore do, and the sync brings a denied app back to Pending
  when someone in Grip moves it to Sanctioned, Under Review or New.
- **Grip alerts use the v2 API.** The sync reads `/v2/alerts` (falling back to
  v1 when a tenant lacks it) and stores the alert name, the Policy Center
  policy that raised it, the plain-text mitigation, the identity involved
  (encrypted at rest) and last-used time. Grip breach alerts now include the
  recommended action and policy number, and the Shadow SaaS New tab shows the
  mitigation on hover.
- **Grip API reference refreshed, with a standards-compliant OpenAPI 3.0 JSON
  and links to your tenant's own docs.** Admin > Shadow SaaS > Grip now offers
  an OpenAPI 3.0.3 JSON (view and download) alongside the OpenAPI 3.1 YAML and
  the Postman v2.1 collection, all regenerated from a Grip tenant's live
  specification (59 paths, adding SaaS access removal, non-communication
  justification and AI Agents). Grip's live spec fails OpenAPI validation
  because its pagination response headers omit `schema`; the bundled files
  correct that and pass `openapi-spec-validator` and Postman's v2.1 schema.
  The bundled description now documents the short-page pagination behavior
  correctly. When a tenant is configured, the page also links that tenant's
  Swagger UI (`/public/docs`) and live spec (`/public/openapi.json`). The
  bundled files stay admin-only through `reference-doc.php` and contain only
  the `tenant` placeholder host.
- **Shadow SaaS status and Grip sanction state stay in sync.** With Grip
  connected, Dismiss and Dismiss Selected mark Grip apps Tolerated in Grip,
  and Restore marks them Under Review so they return to Pending to be denied
  or onboarded. Each Grip write happens only when the local status actually
  changed; a Grip failure shows as a warning, never undoes the local change,
  and is audited (`shadow_saas_grip_sanction`). In the other direction, the
  Grip sync files apps Grip tags Tolerated under Dismissed, and a dismissed
  app whose Grip state moves off Tolerated (Sanctioned, Unsanctioned, Under
  Review) comes back out of Dismissed. Dismissed apps whose Grip state has not
  changed are left alone.
- **Shadow SaaS uses Grip's wording when Grip is connected.** The Dismissed
  card, status filter and badge read "Tolerated", and the row and bulk buttons
  and their confirmations read "Tolerate", in all eight languages. The stored
  status and the `?status=dismissed` link are unchanged, and nothing changes
  when Grip is not connected.
- **Onboarding a Grip app from Shadow SaaS now marks it Sanctioned in Grip.**
  Onboard Selected and the Allow button call Grip's
  `POST /saas/{id}/sanction_status` with `new_sanction_state=Sanctioned` for
  every onboarded row that came from Grip, with a comment naming the FairTPRM
  vendor and the user. The vendor is already onboarded by then, so a Grip
  failure (for example a read-only API token) shows as a warning and never
  undoes the onboarding. Every attempt is written to the audit log
  (`shadow_saas_grip_sanction`), and the onboard confirmation mentions the Grip
  update when Grip is connected.
- **"Sanctioned in Grip, not in TPRM" view on Shadow SaaS.** Apps the business
  has already sanctioned in Grip but never onboarded as vendors are a TPRM
  visibility gap. The page now has a stat tile counting them (excluding apps
  that match an active vendor), a "Grip sanction" filter for any Grip sanction
  tag, and a "Grip: Sanctioned" badge on those rows so they can be selected
  and onboarded. Grip's sanction tag is stored on `shadow_saas.grip_sanction_tag`
  during every sync, backfilled from the Grip mirror on upgrade, and included
  in the CSV export.
- **Shadow SaaS > Posture > Compliance Posture, with Add to Risk Register.**
  Grip's posture checks rolled up by the compliance frameworks they map to
  (NIST CSF 1.1 / 2.0, NIST 800-53, ISO 27001:2022, SOC 2, CIS v8, HITRUST,
  CMMC, GDPR, HIPAA, PCI, SOX and more). Each framework card shows the share of
  evaluated controls passing and counts of failing, passing and not-evaluated
  controls; a control fails when any mapped check fails. Opening a framework
  lists its controls, failing first, with the checks behind each and links into
  Posture Management. Failing controls have an "Add to Risk Register" button
  (administrators and Cyber GRC only, re-checked on the server along with the
  control still failing) that creates a compliance risk: likelihood and impact
  from the worst failing check's severity, description and treatment plan from
  the checks' risk text and fix steps, and links to any internal controls
  mapped to the matching FairTPRM framework requirement. A control that
  already has an open risk shows its RSK link instead of the button.
- **Shadow SaaS > AI Security > AI Dashboard.** A one-page AI risk summary:
  AI apps (total, by AI depth, training on customer data, not onboarded, total
  users), AI agents (total, awaiting review, high risk, internet-connected,
  by sanction state), the most used and most recently discovered AI apps, and
  recent "New Unmanaged AI App" alerts, each linking to the filtered view.
- **Grouped Shadow SaaS tabs.** The tab strip is now Applications | New | AI
  Security | Posture | ITDR, with a second row for the pages inside AI Security
  (Dashboard, Applications, Agents) and Posture (Compliance Posture, Posture
  Management), matching the sidebar.
- **Shadow SaaS > AI Security: AI Applications and AI Agents.** A new AI
  Security group in the Shadow SaaS navigation. AI Applications lists every
  Grip app with AI capability (AI-Native, AI Copilot Experience, AI
  Features/Add-on) with its AI privacy profile from Grip's terms analysis:
  trains on customer data, data retention, third-party sharing, storage
  location and encryption, plus users, Grip sanction and FairTPRM state.
  Headline tiles count apps by AI depth, apps that train on customer data, and
  AI apps not onboarded; filters cover AI depth, training, sanction and
  FairTPRM state, with paging and sortable columns. Expanding an app shows the
  full privacy Q&A, policy links (https only) and certifications. AI Agents
  lists the agents Grip discovered with owner, risk score, sanction state,
  internet access, sharing, knowledge files, who they are shared with and the
  SaaS scopes they hold. Agents are mirrored from `GET /public/ai-agents` (served
  by tenants though absent from Grip's published spec) into the new
  `shadow_saas_grip_ai_agents` table on every sync or with "Refresh from
  Grip"; owner emails, file names and recipients are encrypted at rest.
- **Shared Shadow SaaS tab strip.** The section tabs (Applications, New, AI
  Apps, AI Agents, Posture, ITDR) now come from one partial,
  `includes/partials/shadow-saas-tabs.php`, instead of a copy in each page.
- **Shadow SaaS > Identity Threat Detection & Response (ITDR).** A new page
  mirrors Grip's ITDR view: active threats by severity and by status, the top
  exposed identities (threat count, worst severity, and total SaaS accounts
  from the Grip users mirror), and every threat with its type, category,
  identity, detection time and status. Expanding a threat shows the
  description, potential impacts, mitigation steps, threat details and the
  triggering event records (time, event, IP, country), with a link to the
  alert in Grip; filter by severity, status, category or text. Threats are
  mirrored into the new `shadow_saas_grip_threats` table on every Grip sync
  (one API call) or with "Refresh from Grip"; the identity name and email, the
  event logs and the threat details are encrypted at rest.
- **Collapsible Shadow SaaS navigation.** With Grip connected, Shadow SaaS in
  the left navigation becomes a collapsible group: Applications, New, Posture
  (Posture Management) and Identity Threat Detection & Response. The group
  opens automatically on its own pages and uses native `<details>`, so it
  needs no script. The tab strip across the Shadow SaaS pages gains ITDR.
- **Shadow SaaS > New tab: SaaS discovered in the last N days.** Lists every
  Grip application first seen within a chosen window (default 30 days; 7 / 30 /
  90 shortcuts or any 1-365), newest first, with first-seen date and age, user
  count (linked to the user list), category, AI depth, Grip sanction tag and
  risk, the app's FairTPRM state (pending, unsanctioned, dismissed, or onboarded
  with a link to the vendor), how Grip detected it (source platforms) and the
  Grip discovery alert. Patient zero, the earliest user Grip observed on the
  app, is shown with their first event and sign-in type, with same-day ties
  counted. Pending apps can be selected and onboarded from the tab through the
  existing onboarding path (which also marks them Sanctioned in Grip). In Live
  data mode the Grip sync now snapshots the user lists of apps discovered in the
  last 90 days (only those whose numbers changed), and "Refresh from Grip"
  fetches any that are missing. The day window is computed in the app time zone,
  which is the zone Grip timestamps are stored in.
- **Posture: Top Failed Security Domains open in a new tab.** Each domain links
  to that domain's failed checks in a new browser tab.
- **Shadow SaaS > Posture tab (Grip SaaS security posture).** When Grip is
  connected, Shadow SaaS gains Applications / Posture tabs. Posture mirrors
  Grip's Posture Management view: the general posture score (Pass / (Pass +
  Fail), the same figure Grip shows), the lowest-scoring tenants, the security
  domains with the most failed checks, and every check with status, severity,
  tenant, module and findings count, filterable by status, severity, domain,
  module and text. Expanding a check shows its risk, mitigation steps,
  compliance controls, report details and any overrides. "View findings" lists
  the violating users and resources, fetched live from Grip and never stored.
  Checks are mirrored into the new `shadow_saas_grip_posture_checks` table on
  every Grip sync (one API call), or on demand with "Refresh from Grip";
  free-text report details are encrypted at rest, and Grip text that carries
  HTML is reduced to plain text before display.
- **Apps unsanctioned in Grip show as Unsanctioned in Shadow SaaS.** A pending
  app that Grip tags unsanctioned now counts under the Unsanctioned tile and
  filter (not Pending), reads "Unsanctioned in Grip" in the status column, and
  carries a red "Grip: Unsanctioned" badge. It is derived from Grip's tag
  rather than stored, so it follows Grip if the tag changes; a dismissed app
  stays dismissed. These rows get an "Unsanction here" button that marks the
  app unsanctioned in FairTPRM and, when Zscaler is configured, adds the domain
  to the URL Category, plus Allow to onboard instead.
- **Breach alerts are now checked against their own evidence before anyone is
  told about them.** AI breach research was producing mis-attributed alerts, and
  the old guard - "at least two credible source URLs" - counted URLs without
  reading them. Two source types defeated it completely. Rolling roundup pages
  ("2026 Data Breaches", "recent breaches", "week in breach", "updated list")
  rank for practically every "<company> data breach" query and are re-dated as
  they are appended to, so they looked both credible and fresh while naming
  dozens of unrelated companies; and bare homepages or section indexes
  (`https://www.securityweek.com/`, CISA's advisories listing) satisfied the
  count while proving nothing at all. A new `BreachEvidenceValidator` grades
  every AI-researched alert in three layers: it classifies each cited URL and
  requires at least one link to coverage of *this* incident, it fetches those
  articles and requires the affected entity to actually be named on the page
  alongside incident language, and it refuses to re-raise a narrative a reviewer
  already dismissed. The same classifier also strips roundup and index hits out
  of the web-search evidence before the research model ever sees them, and the
  research prompt now lists previously dismissed narratives and spells out both
  failure modes. Replayed against the full alert history, 29 of 33 alerts a
  reviewer had marked false positive no longer generate an email, and no alert
  a reviewer had accepted is rejected.
- **Alerts that cannot be corroborated are held back from email instead of being
  sent or silently dropped.** An alert whose sources are structurally fine but
  whose pages could not be retrieved (paywall, bot wall, network error), or
  whose affected entity is not named in its own headline, is recorded with a
  `verification_status` of `unverified`, shown in Breach / Cyber Alerts with an
  "Unverified" badge and the reason, and left out of the digest email. The
  headline check is what catches name-collision mis-attribution, where genuine
  coverage of one company is filed against a similarly-named monitored vendor -
  for example, a security advisory about one company attributed to a vendor
  whose name merely starts the same way. Alerts
  refused outright are written to a new `cyber_breach_alert_rejections` table
  with the reason, so a filter that is too tight is visible rather than looking
  like a quiet scanner. Tunable through `breach_verify_enabled`,
  `breach_verify_fetch` (set to 0 on an air-gapped install),
  `breach_verify_min_articles`, `breach_verify_suppress_fp` and
  `breach_verify_fp_similarity`. Corroboration fetches are SSRF-guarded: public
  addresses only, every redirect hop re-validated, the resolved address pinned,
  and the response size capped. The Grip Shadow SaaS feed and GitHub OSINT
  findings carry their own provenance and bypass verification unchanged.
- **Marking a breach alert a false positive now actually stops it coming back.**
  Deduplication keyed on affected entity plus `alert_type`, and the
  entity-level check deliberately ignored resolved and false-positive rows, so
  the same story returned a week later under a different `alert_type` - which is
  how a vendor's "Unauthorized Access via Exposed GitHub Credentials" alert was
  raised twice, dismissed both times. A dismissed entity/headline pair is now
  remembered: a new alert for the same entity telling the same story is refused,
  with the narrative compared on meaning rather than wording, so "Data Breach
  via Exposed Developer Credentials" and "Unauthorized Access via Exposed GitHub
  Personal Access Token" are recognized as one claim. A genuinely different
  incident at the same vendor still comes through.

- **Each policy chooses which ACL groups have to acknowledge it.** Acknowledgment
  used to be addressed to every active user, which made the acknowledged
  percentage meaningless for a policy that only concerns one team. A policy can
  now name any number of ACL groups, picked when it is created and changeable
  afterwards from the policy page; the audience is the union of those groups'
  members. This scopes everything downstream: the "N of M acknowledged" figure
  and its progress bar count only the audience, the Pending Acknowledgments list
  shows only people who actually owe a signature, and a user's pending-policy
  queue no longer includes policies aimed at teams they are not in. Users
  outside the audience are not offered the acknowledge button and cannot record
  one, so a policy's compliance record stays limited to the people it was
  addressed to. A policy with no groups selected is addressed to every active
  user, which is how policies behaved before, so existing policies are
  unaffected. Changing the audience never deletes acknowledgments already given.
  Translated into all seven supported languages.
- **Policies can be retired, reinstated, or (for administrators) force deleted.**
  Retiring a policy takes it out of force without destroying anything: it drops
  off the default list, stops appearing in anyone's pending acknowledgments, and
  stops generating review reminders, while every acknowledgment record is kept.
  A "Show Retired" checkbox in the filter bar brings retired policies back into
  view, and Reinstate returns one to circulation (to published if it has a
  published version, otherwise to draft). Retiring is available for system
  policies too - `is_system` means "cannot be destroyed", not "must stay in
  force". For the case where a policy genuinely has to go despite carrying
  acknowledgments, administrators (and only administrators) get a Force Delete
  in a separate red danger zone, behind a banner stating exactly how many signed
  attestations will be destroyed. Force delete writes the policy and every
  acknowledgment it carried - including signature hashes and who destroyed them
  - to the audit log before removing anything, and aborts without deleting if
  that audit record cannot be written. It still refuses system policies. All new
  strings are translated into the seven supported languages.
- **Policies can be deleted when nothing is tied to them.** Policy Management
  previously had no way to remove a policy, so a typo, a duplicate, or an
  abandoned experiment stayed on the list forever. The policy detail page now
  offers a Delete Policy button, guarded by two rules that can never be
  bypassed. System policies (the new `is_system` flag, set on any policy that
  ships with the product) are permanent and are never deletable. Any policy a
  user has acknowledged is also permanent, because the acknowledgment is a
  digital signature an auditor may ask for long after the policy stops
  mattering; retire those instead. Everything else is a user-created policy
  nobody is tied to, and deleting it also removes its version history,
  acknowledgment records, and review schedule. Where the button would be, a
  blocked policy shows the reason instead. Both conditions are re-checked on
  the server, so a hand-crafted request cannot delete what the page declines to
  offer. Translated into all seven supported languages.
- **Vendor SRS list hides inactive vendors by default, with a "Show Inactive"
  toggle.** The Vendor Security Scores board no longer lists vendors whose status
  is `inactive` unless the new "Show Inactive" checkbox in the filter bar is
  ticked; the default view and the Reset link both exclude them. Inactive vendors
  were already excluded from rescoring and bulk actions, so this aligns the list
  with the rest of the page. The toggle is translated into all seven supported
  languages.
- **SRS / Shodan external-scan accuracy - fewer false positives.** The Shodan
  scoring engine now actively TCP-verifies every reported open port (except the
  standard web ports 80/443) with a real concurrent connect and drops any that
  do not complete a handshake, along with the services, banners, and CVEs tied
  to those ports - so a port Shodan lists as open but that is actually filtered
  no longer counts against a vendor. CVE findings are split by fidelity: only
  vulnerabilities Shodan actively confirmed (its `verified` flag) affect the
  score, while version/banner-inferred CVEs - the dominant external-scan false
  positive - are shown for awareness, labeled "low fidelity - not scored," and
  excluded from the grade (so a vendor scored only on inferred CVEs may see its
  score rise on the next rescan). Host attribution is cross-checked against live
  authoritative DNS, dropping stale Shodan-cached attributions (a failed lookup
  fails open, so a DNS hiccup never discards data). Both the active port check
  and the DNS attribution check are on by default and can be disabled per
  deployment from Admin -> SRS -> Shodan (Scan Accuracy). On the vendor SRS
  details page, each negative Security Signal now expands to list the hosts that
  carry it and, below, the scanned hosts that do not.
- **Full application internationalization (i18n).** Every user-facing string
  across ~40 admin and vendor pages - including Shadow SaaS, Grip, the GRC suite
  (dashboard, assessments, audits, frameworks, controls, crosswalk, risks,
  FAIRScore, reports), SRS/Shodan-SRS, SAML, Lockdown, Scheduler, Users, Groups,
  Workflows, Backup, Email, API Tokens, Field Reference, Assessment Templates,
  vendor onboarding, fourth-party risk, vendor assessments, and the procurement
  cyber-status pages - is now routed through the `t()` translation layer instead
  of being hardcoded in English (~2,900 new catalog keys). Every key is
  translated into all seven supported languages (Spanish, French, Italian,
  Portuguese, Ukrainian, Hindi, and Simplified Chinese); any key without a
  translation falls back to English, so no page can regress to a raw key.
  Inline HTML, printf placeholders, and product/brand names are preserved
  verbatim across all locales.
- **AI "Auto-Fill from Certifications" documentation.** The in-app
  documentation now covers filling assessment questionnaire answers from a
  vendor's current certification documents when an AI provider is configured,
  and the documentation's anchor navigation and language FAQ were corrected.
- **License - Fair TPRM Community License 1.0.** The project now carries an
  explicit `LICENSE.md`. It is a source-available license: the software is free
  to use and to modify, including tailoring it to the needs of the person or
  company using it, with no obligation to distribute changes. The software may
  not be sold or resold for profit without the prior written permission of Fair
  TPRM, and all authorship and attribution notices must be preserved. (Not an
  OSI "open source" license, because it restricts selling.) The license also
  ships inside the product image at `/var/www/html/LICENSE.md`.
- **Cyber Status notes - edit & delete.** On the Procurement Cyber Status page, a
  procurement update/note can now be edited or deleted by an administrator, any
  cyber_tprm member, or the note's original author (a per-object check, re-validated
  server-side). Edited notes show an "edited" timestamp, and every edit and delete is
  audit-logged.
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
- **Grip affected-users search.** The affected-users drill-down
  (`grip-saas-users.php`) gains a search box to find a user by first name, last
  name, or email address. Because the roster's name and email columns are
  encrypted at rest, the match runs over the decrypted snapshot in the
  application layer (like the column sorting); it composes with the existing
  authentication filters and is preserved across sorting and pagination.
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
- **Vendor assessments: download as a fillable Excel workbook (.xlsx).** Alongside
  the fillable PDF, an assessment can be downloaded as a real `.xlsx` workbook and
  filled in Excel, Google Sheets, or LibreOffice. The Assessment sheet is
  unprotected for easy editing, single-choice questions get in-cell dropdowns, and
  conditional questions gray out when they do not apply (a formula-driven rule that
  reacts live as the controlling answer changes). The assessment's Reference lives
  on a hidden "Internal Use" sheet so the file can be matched back on import. Built
  with no third-party library (hand-written OOXML).
- **Unified assessment import (PDF, Excel, or CSV).** The import control on the
  vendor assessment view accepts a completed PDF, Excel (.xlsx), or CSV in a single
  upload; the format is detected automatically and answers are merged into the
  existing responses. The file's Reference must match the assessment before
  anything is written.
- **`setup_docker_install.sh` - version, network exposure, and reverse proxy.**
  Run with no arguments the installer prompts for the version to install
  (`2.6.2` and `v2.6.2` are both accepted and normalize to the `v2.6.2` image
  tag; the tag is validated against the registry before anything is downloaded),
  whether to publish port 8080 to the network or to the host only, and whether to
  configure an nginx reverse proxy. Every prompt has an equivalent flag
  (`--version`, `--expose host|network`, `--port`, `--nginx` / `--no-nginx`,
  `--domain`, `--email`, `--tls`, `--yes`), and passing any flag makes the run
  fully unattended, so the script is usable from cloud-init and configuration
  management. With no arguments it installs `v2.6.2` and publishes 8080 on
  `127.0.0.1` only, as before. `--nginx` terminates on port 80 (optionally with a
  Let's Encrypt certificate via `--tls`) and keeps 8080 bound to the loopback
  interface. Prompts read from `/dev/tty`, so the documented
  `curl ... | sudo bash` bootstrap still works.
- **`setup_docker_install.sh` verifies the admin credentials it prints.** After
  the container is healthy the installer checks the generated password against
  the bcrypt hash in the database and says so. If they disagree it prints a
  tested reset recipe instead of a password that will not work.

### Changed

- **Compliance Posture shows only what Grip can observe.** Checks Grip
  reports as Excluded, Pending or Undetectable are left out, and so is any
  control, or framework, with nothing passing or failing. The framework score
  is now the share of observed checks that pass, instead of controls with no
  failing check, so one failing check no longer zeroes out a broad control.
  A framework's page notes how many mapped controls were hidden.

- **Bundled Grip API reference refreshed from Grip's public spec.** The admin-only
  OpenAPI and Postman files under `app/grip-reference/` are now generated from
  the export published on Grip's documentation portal instead of a customer
  tenant, and pick up the Threats, ITDR Actions and CyberArk password-reset
  endpoints.
- **`init-db/` is now built from the repository rather than inherited from the
  base image.** The three seed-schema files were previously outside the image
  overlay, so edits to them in the repository never reached a build and the two
  copies drifted apart unnoticed. They are now copied from source like every
  other shipped file, which makes the repository authoritative and lets the
  drift checker enforce that they match. `init-db/01-grc_schema.sql` is also
  brought up to date with the `is_system` column and the `grc_policy_ack_groups`
  table. That file is a reference copy that nothing loads at runtime -- the
  entrypoint seeds from `00-master_schema.sql` and `grant_remote_access.sql`,
  then applies `sql_updates/*.sql` -- but it is the file a reader is most likely
  to consult, so it should be accurate. All `.sql` paths remain denied over HTTP.
- **Promoted 2.6.2 to the stable release line.** `2.6.2` supersedes `2.6.1` (now
  frozen as the final 2.6.1 codeset) and is published to both the `master` and
  `2.6.2` branches. Removed the "pre-release / not-yet-production-ready" language
  from the README and CHANGELOG accordingly.
- **Vendor assessment certificate upload is now template-driven, not ISO-only.** The
  "have a certificate?" modal on the vendor assessment page now shows the template's
  **Certificate Upload Instructions** as its body text (falling back to the generic
  description when none are set), so a template can invite any certificate - ISO
  27001, SOC 2 Type 2, etc. - rather than only ISO 27001:2022. The modal heading,
  the upload panel title, and the post-upload confirmation title were generalized
  from "...ISO 27001:2022 Certificate..." to "...Certificate..." across all eight
  locales (en, fr, es, it, pt, uk, hi, zh-Hans).
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
- **Fillable assessment PDF now works in web browsers, not only Adobe Acrobat.**
  Checkboxes carry baked appearance streams so they render and toggle in Chrome,
  Edge, and other PDFium-based viewers (they previously relied on
  `/NeedAppearances`, which only Adobe honors). A typed-name signature field lets a
  signer sign in any viewer; the Adobe-only digital-signature and date-signed
  fields are hidden in browsers and revealed only in Adobe, which can actually use
  them.
- **Assessment Templates: deactivated templates are hidden by default.** The admin
  Assessment Templates list now shows only active templates; a "Show Deactivated (N)"
  button reveals the deactivated ones on demand (and toggles back to "Hide"), keeping
  a long-lived tenant's list focused on templates in use without losing access to
  retired ones. Localized in all eight locales.

### Fixed

- **FAIR breach costs no longer scale linearly with record count.** A flat
  per-record rate times the record count overstated large breaches: one vendor
  with 120,000 PII records was priced at $19.2M primary loss. Per-record
  averages (IBM/Ponemon) fold fixed response costs into each record, and
  published fits of real breach costs grow sublinearly with size. Records up to
  a threshold (default 10,000) now cost the full rate; beyond it, cost grows
  by a configurable exponent (default 0.76, the Jacobs 2014 fit; 1.0 restores
  the linear model). The AI-assisted FAIR analysis prices records with the same
  formula, and its primary and secondary losses are held to within 20% of the
  server's values. Admin > FAIR Defaults adds the threshold, the exponent and
  an optional per-incident loss ceiling (off by default). The FAIR form now
  shows the scaled rate and asks for unique people, not raw rows. Existing
  analyses keep their stored results until re-saved.
- **The FAIR Defaults revenue-cap preview never updated.** Its script was
  blocked by the Content Security Policy; it now runs as you type.
- **Onboarding a Grip app from Shadow SaaS lost its Grip data.** The new
  vendor's SaaS Data tab showed only the privacy and file-sharing review: the
  Grip telemetry (active accounts, first discovered, last used, sanction tag,
  category, compliances, SAML/MFA) and the SecurityScorecard rating were not
  copied when the Shadow SaaS row was replaced, and only reappeared after the
  next Grip sync matched the vendor by domain. Onboard Selected and Allow now
  fetch the app from Grip and stamp the vendor right away; if Grip is
  unreachable, the onboarding still succeeds and the next sync fills the data in.
- **A sidebar click could look like it did nothing.** Every sidebar link
  (admin and platform) sent two requests for the page: the menu script
  navigated without cancelling the link's own navigation, and the second
  request waited on the first one's session lock. On a slow page such as
  Admin > Integrations > Shadow SaaS the first click seemed dead. A click now
  loads the page once.

- **Run review now could look like it did nothing.** The button was disabled
  without explanation until Use AI was saved, and a lock file left by the
  scheduled run could make manual runs skip silently while the page reported
  success. The button now always works and says exactly why a review cannot
  run, the page confirms the background job really started, a running
  review shows live progress, and lock files no longer block across users.
- **AI model shown for FAIR analysis on the Custom platform was wrong.** The
  Custom platform always sends its own request template, but the test
  messages named a leftover OpenWebUI model. They now show the gateway's model
  label (set under AI Integration > rates). Queued AI jobs are logged under
  their own feature name (fair_analysis) in AI usage.
- **A vendor page link to the SaaS Data tab opened the Vendor tab instead.**
- **A revoke request from one waiver page could revoke another kind of
  waiver.** Revoke now matches the waiver kind before changing anything.
- **Vendor privacy "due" counts and 30-day spend used the database clock**,
  which can differ from the application time zone; they now use application
  time.

- **AI token usage from Anthropic web searches was under-counted.** Only the
  last round of a multi-round search reported usage; rounds are now summed.

- **Sorting Shadow SaaS by SSC did nothing.** The SSC (SecurityScorecard)
  header linked to a sort the query did not recognize, so the list stayed in
  date order. It now sorts A to F, unrated apps last.
- **Tolerating rows that were already dismissed now updates Grip.** Dismiss
  and Tolerate Selected only wrote to Grip when a row's status changed, so
  apps dismissed before the Grip sync existed (shown under Tolerated) stayed
  New or Sanctioned in Grip even when re-tolerated. They are now written as
  Tolerated whenever Grip shows anything else. Bulk Dismiss and Restore write
  the first 25 to Grip in the request and queue the rest, which the Grip
  New-App Discovery job (every 15 minutes) or the next full sync writes,
  retrying failures.

- **Grip sync was quietly pulling only part of the tenant's SaaS apps, so many
  vendors never got Grip telemetry.** Grip's list endpoints sometimes return one
  row fewer than the requested page size (199 of 200) in the middle of a list.
  The client took any short page as the last one, so a sync could stop after
  599 of roughly 2,700 apps and still be logged as successful. Which apps made
  the cut changed with Grip's ordering, so vendors gained and lost their SaaS
  Data tab, SecurityScorecard grade and breach user counts from one night to
  the next. App, user and per-app roster pagination now advance by the page size
  requested and stop only on an empty page, with a repeated-page check and a
  page cap as backstops. The live roster view on `grip-saas-users.php` also no
  longer drifts past the requested window after a short page. The next sync
  after upgrading fills in the missing vendors.
- **Creating or editing a user on Admin -> Users silently did nothing.** The
  Create User and Edit User modals are the only two forms carrying
  `data-disable-on-submit`, the double-post guard added with the CSP hardening.
  Its handler disabled the submit button from inside the form's own `submit`
  event, but the browser builds the submission's entry list only after those
  handlers return, and disabled controls are left out of it - so the button's
  own `name="create_user"` / `name="update_user"` pair never reached the server.
  The POST arrived carrying the username, full name, email and password but no
  action key, matched none of the handler's branches, and fell through to a
  plain re-render: HTTP 200, no new user, no audit entry, no error message and
  nothing in the PHP log. The guard now disables the button one task later, so
  the action key survives in the request while double-posting is still blocked.
  Affects 2.6.2 only; `data-disable-on-submit` does not exist in 2.6.1.

- **Confirmation prompts across Admin, Vendors and Breach Alerts never appeared,
  and the SRS "Un-score" button did nothing.** A sweep of the rest of the app
  found 24 more inline event handlers that the Content-Security-Policy discards,
  in the same way as the GRC ones below. The most serious were confirmations
  that were written but never asked: on Admin -> Lockdown, "Disable WAF
  enforcement", "Clear all learned patterns", "Clear all block log entries",
  remove-whitelist-IP, remove-rule and add-exception all executed on a single
  unguarded click; likewise delete-status-note and delete-scheduled-action on
  Vendor Onboarding, deactivate/activate user, and purge-pending-rescores on
  Admin -> SRS. On Vendor SRS Details, the "Un-score" button was supposed to
  clear the field before submitting and did not, so it silently re-saved the
  existing custom score instead of removing it. Also restored: the double-submit
  guard on the user create and edit forms, the broken-image fallback on the SRS
  vendor list, and the click guards that stop ticking a row checkbox from also
  expanding the row (Breach Alerts, Cyber To-Do, Vendor SRS list, Fourth-Party
  Risk, Vendor Onboarding). Handlers now use the app's existing
  data-attribute delegation in `app/js/event-handlers.js`; three hooks it does
  not implement live in a new `includes/csp_behaviors.php`. The lockdown
  confirmations that interpolate an IP address or request URI are now plain HTML
  attributes rather than PHP echoed into a JavaScript string inside an HTML
  attribute, removing a nested-context escaping hazard.
- **GRC filter dropdowns, confirmation prompts, and the Add Evidence form did
  nothing.** Seventeen inline `onchange` / `onclick` attributes across the GRC
  pages were dead code: the Content-Security-Policy is `script-src 'self'` plus
  a nonce, with no `unsafe-inline`, so the browser never ran an inline event
  handler. Three consequences, in increasing order of seriousness. Filter
  dropdowns on Policies, Controls, Tasks and the Gaps assessment picker did not
  apply on selection, so a filter only took effect if you also clicked the
  Filter button. The "Remove mapping" and "Unlink evidence" buttons on Controls
  and the "Delete" button on Tasks were meant to ask for confirmation first and
  never did, so those destructive actions went through on a single click with no
  prompt. And the "+ New Evidence" button on Controls, which toggles the create
  form open, did nothing at all, leaving that form unreachable. All seventeen
  are now wired from the pages' nonced script blocks, including the print button
  in the audit HTML export, which is a standalone document but is served from
  the same response and so is covered by the same policy. The two confirmation
  prompts that were hardcoded English strings are now translated.
- **Automatic SRS rescoring starved lower tiers on large tenants.** The hourly
  rescore cron asks `SRSService::getVendorsNeedingRescore()` for a batch, but
  that method applied its `LIMIT` (batch size) to a strictly tier-ordered
  candidate list *before* deciding which rows were actually due, then filtered
  due-ness in PHP. On a large fleet the batch could fill entirely with
  not-yet-due Tier 1 vendors (they sort ahead of every lower tier regardless of
  due date); all of them were then discarded as "not due," the job logged
  "No vendors require rescoring," and genuinely-overdue Tier 2 / Tier 3 vendors -
  which sort after every Tier 1 row - were never fetched. The effect was a small
  set of correctly-tiered, active vendors stuck on "Needs Rescore" indefinitely
  while the rest of the fleet stayed current. Due-ness is now computed in SQL
  (per-tier `DATE_SUB` interval) before the `LIMIT`, so the batch can only ever
  contain vendors that are genuinely due and lower tiers can no longer be crowded
  out. The PHP `needsRescore()` pass is retained as defense in depth.
- **Shadow SaaS "Risk Type" filter matched only single-value rows.** `risk_type`
  is stored as a `"; "`-separated list (Grip writes `implode('; ', ...)`, as does
  the CSV import), but the filter built its `LIKE` patterns with a bare `;` and no
  space (`%;Type;%`, `%;Type`). Those never matched the stored `"; Type"` text, so
  selecting a risk type only returned rows where that type was the first or only
  element - to the user the filter looked like it did nothing. The query now
  normalizes the separators (`REPLACE`) and wraps the value in `;` before a single
  delimited `LIKE`, so any element in a multi-value list matches regardless of
  spacing. The CSV export honors the same corrected filter.
- **Recreating the database volume alone left the install broken.** `.passwords`
  lives in the `mariadb_data` volume, while `config.php` and `.sensitive` live in
  `persistent_data`. Destroying the database volume without also destroying the
  persistent one regenerated every credential, so `config.php` pointed at a
  database user the new database had never created (`Access denied for user
  'tprm_user'`) and `.sensitive` advertised an admin password the new database
  had never hashed. The entrypoint now detects a fresh database beside a
  surviving `/persistent` and seeds the new database with the credentials
  `config.php` already carries - keeping the existing encryption key, so data
  already encrypted under it stays readable - rather than rewriting a config it
  did not author. The admin password cannot be recovered from `config.php`, so it
  is regenerated and `.sensitive` is rewritten to match, with the previous file
  preserved as `.sensitive.previous`. Seeding and the credential rewrite now key
  off one `DB_EXISTS` check, so they cannot disagree. A plain restart still
  rewrites nothing.
- **Login was impossible over plain HTTP from another machine.** The session
  cookie was always sent as `__Host-tprm_session` with the `Secure` attribute,
  regardless of the request's scheme. Browsers refuse to store a `Secure` cookie
  (and reject a `__Host-` prefixed one outright) unless it arrives over an HTTPS
  origin, so a browser pointed at `http://<server-ip>:8080` kept no session at
  all; the CSRF check on the login form then failed and the correct admin
  password was rejected with "Invalid request. Please try again." It worked from
  the server itself only because browsers treat `localhost` as a trustworthy
  origin. `Session` now detects whether the request actually is HTTPS - directly,
  by port, or via `X-Forwarded-Proto` from a TLS-terminating reverse proxy - and
  only then asks for `Secure` and the `__Host-` prefix, dropping the prefix
  otherwise. Cookies remain hardened (`Secure`, `__Host-`, `HttpOnly`,
  `SameSite=Strict`) on every HTTPS deployment, including behind the nginx
  configuration the installer writes. No configuration change is required.
- **`setup_docker_install.sh` could never complete on a fresh machine.** The
  installer ran `docker compose build`, but the `Dockerfile` overlays files from
  `persistent/php_patches` and `persistent/icons`, neither of which is published
  in this repository. Every fresh install died with
  `"/persistent/icons": not found`. The installer now pulls the published
  application image (`docker pull` + `docker compose up -d --no-build`) instead
  of building it, which is also considerably faster. Nothing is compiled locally.
- **`setup_docker_install.sh` installed the wrong branch.** The copy of the
  script on the `2.6.2` branch cloned `master` and deployed the stable v2.6.1
  image, so the documented `-/raw/2.6.2/...` bootstrap URL never produced a
  v2.6.2 install. The branch is now a variable (`BRANCH`, default `2.6.2`,
  overridable with `FAIRTPRM_BRANCH`), and repository updates reset to that
  branch rather than to `origin/master`.
- **`setup_docker_install.sh` refused to run on Ubuntu 26.04.** The release gate
  hard-failed on anything other than 22.04/24.04, so the current Ubuntu LTS was
  rejected outright. 26.04 is now a supported release, unrecognized releases warn
  and continue instead of aborting, and the Docker apt repository falls back to
  the newest published codename when Docker has not built packages for the
  running release yet.
- **`setup_docker_install.sh` reported build failures as success.** A failing
  build was piped into `tee`, so `set -e` aborted the script before the
  `PIPESTATUS` check could run: the `[FAIL]` diagnostic was unreachable and the
  installer exited with no explanation. Failures of the image fetch and the
  container start are now reported explicitly.
- **`setup_docker_install.sh` printed an unreachable URL.** The finish banner
  advertised `http://<server-ip>:8080`, but Compose publishes port 8080 on
  `127.0.0.1` only. It now prints the correct URL together with the SSH-tunnel
  command needed to reach it from another machine. The credentials box borders
  also line up again. Port 8080 can now also be published to the network
  outright (`--expose network`), which is what most people wanted.
- **`setup_docker_install.sh` could print an admin password that did not work.**
  The password is written to `.sensitive` in the `persistent_data` volume, while
  the bcrypt hash it must match lives in the `users` table in `mariadb_data`.
  Changing the password in the UI, or recreating one volume without the other,
  leaves the file stale, and the installer reprinted it as though it were
  current. The installer now verifies the password against the database and, on
  a mismatch, prints a reset recipe rather than a stale credential.
- **README: the "forgot the admin password" recipe could not run.** It called
  `php -r` on the host, which has no PHP under a Docker install. The recipe now
  runs inside the container.
- **Subprocessors: editing name/domain/country/linked-vendor/service/data failed.**
  Saving an edit on the vendor onboarding **Subprocessors** tab returned "Failed to
  update subprocessor" and persisted nothing. The edit API passed a positional (`?`)
  WHERE clause to `Database::update()`, which builds its SET clause with named
  (`:col`) placeholders; PDO rejects the mix with "Invalid parameter number: mixed
  named and positional parameters." The WHERE now uses a named placeholder, matching
  the convention used by every other `update()` caller.
- **Fresh-install schema: onboarding role-gating columns were missing.** On a brand-new
  database, `assessment_questions.visible_roles` / `editable_roles` were added "AFTER
  include_in_minimal" before that column was created, so the ALTERs failed and the
  columns were absent on fresh v2.6.2 installs. `include_in_minimal` is now added before
  the columns that reference it. (Idempotent; existing installs are unaffected.)
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
- **Assessment PDF: a conditional question no longer shows when its controlling
  question is unanswered.** A question gated on a multi-select answer (e.g. "Other
  AI Providers", shown only when the AI-providers question includes "Others") was
  always visible, because multi-select parents were never registered as the
  controller that drives dependent visibility. They now gate the dependent's
  show/hide the same way single-choice parents do.
- **Assessment Excel: section-header bars now match the admin "Header Color".** The
  workbook read an unpopulated `$theme` global and always fell back to the default
  teal; it now reads the application-wide `header_color` setting directly, so the
  section bars use the configured brand color (the fillable PDF already honored it).
- **FAIR analysis: "Failed to save analysis" once an encrypted field grew large.**
  The analysis save encrypts each free-text field before storing it, and base64 +
  IV/HMAC inflate the value ~1.35x. A large field (e.g. a ~48 KB+ `configuration_data`
  paste) then exceeded its `TEXT` column's 64 KB limit and, under
  `STRICT_TRANS_TABLES`, aborted the entire save. The 30 encrypted content columns
  on `tprm_results` are widened from `TEXT` (64 KB) to `MEDIUMTEXT` (16 MB). The
  migration is idempotent and non-lossy -- widening preserves every existing value
  (verified by a byte-for-byte content hash across a down/up cycle).

### Security

- **dompdf updated from 3.1.5 to 3.1.6, closing six advisories.**
  `GHSA-8hg6-c449-896m`, `GHSA-f5gf-2cj8-52g2`, `GHSA-7x2p-4jvh-6384` and
  `GHSA-wvh6-f5jh-8gw4` (CVSS 7.5) plus `GHSA-cx96-42px-69fm` and
  `GHSA-j8qw-6jw8-r297` (CVSS 6.3) all had fixes in 3.1.6. This was worth
  treating as reachable rather than theoretical: the PDF export paths
  (`download-pdf.php`, `api/grc-assessment-report.php`) render app-generated
  HTML that embeds vendor- and assessment-supplied text, so partially
  attacker-influenced content does reach the renderer. Same major version, no
  API change. `vendor/` comes from the base image rather than being built here,
  so the upgrade runs as a `composer update dompdf/dompdf` inside the existing
  Dockerfile layer; no new layer is added. Verified in a built image: composer
  reports "No security vulnerability advisories found", and dompdf still renders
  a valid PDF.
- **Vendor favicons are never served as SVG.** `api/vendor-favicon.php` returns a
  BLOB fetched from the vendor's own domain by `FaviconService::fetchFavicon()`,
  and its MIME allowlist included `image/svg+xml`. SVG is an active content
  type, so a malicious or compromised vendor site could have supplied an SVG
  carrying `<script>` that was then replayed from this application's origin as
  stored XSS. The app CSP (`script-src 'self' 'nonce-...'`) did block execution,
  but that made CSP the single point of failure for the whole path. SVG and the
  other markup types are now refused outright and degrade to the existing 1x1
  transparent PNG placeholder, and every favicon response carries
  `X-Content-Type-Options: nosniff` so a raster response cannot be sniffed back
  into markup. PNG, ICO, GIF, JPEG and WebP are unaffected.
- **Removed a well-known example password hash from the reference schema files.**
  `includes/schema/master_schema.sql` and `includes/schema/schema_sqlite.sql`
  seeded the admin row with a bcrypt hash that is published verbatim in the PHP
  `password_hash()` documentation and appears in default-credential wordlists,
  under a comment claiming the password was `Admin@123` (it was not; the hash
  verifies against `password`). No deployment was ever exposed by this: both
  files are unreferenced reference copies, they are not web reachable, and the
  live seeding path is `init-db/00-master_schema.sql`, where `entrypoint.sh`
  substitutes `CHANGEME_ADMIN_HASH` with a bcrypt of a randomly generated
  per-install password written to `/persistent/config/.sensitive`. The stale
  hash and its misleading comment are nonetheless a trap for anyone who later
  mistakes these files for the live schema, and they made every credential scan
  report a critical finding. Both now carry the same `CHANGEME_ADMIN_HASH`
  placeholder as the real schema, with a comment stating plainly where seeding
  actually happens. No functional change; the generated-password install path is
  untouched.
- **Deleted the stock Debian Apache default page from the repository.**
  `web/index.html` was the 368-line "Apache2 Debian Default Page" that ships in
  the base OS package. Nothing referenced it, and it was already excluded from
  the built image, so it existed only as a permanent gap in the staging drift
  check and as a recurring pair of `plaintext-http-link` findings in every
  security scan (its `http://` links point at httpd.apache.org and
  bugs.debian.org documentation). Removing it clears both findings at the source
  and closes the drift gap. No runtime effect: the image never contained it, and
  `/` continues to resolve through `index.php` to the login redirect. Note the
  unrelated zero-byte `sql_updates/index.html`, which exists to suppress
  directory listing, is deliberately kept.
- **Admin section handlers** refuse direct web access (must be dispatched via
  `admin.php`).
- **BOLA/IDOR remediation** across object-access paths, plus OS package updates.
- **TOTP verification is now rate limited.** The second-factor step previously
  had no throttle of any kind: an attacker who already held a valid password
  could guess the six-digit code without limit. `Auth::verifyTOTP()` now honors
  `account_locked_until` before checking a code, counts each failure against the
  same budget as a bad password (`auth.max_login_attempts` /
  `auth.lockout_duration`, 5 attempts / 30 minutes by default), and clears the
  counter on success. No schema change - it reuses the existing
  `failed_login_attempts` and `account_locked_until` columns.
- **Content-Security-Policy `script-src` no longer allowlists bare Google
  origins.** Allowlisting `https://www.google.com` and `https://www.gstatic.com`
  wholesale is a known CSP bypass, since those hosts serve JSONP callback
  endpoints and legacy AngularJS builds that turn any HTML injection into script
  execution. The policy now scopes them to the paths actually loaded -
  `https://www.google.com/recaptcha/`, `https://www.gstatic.com/recaptcha/` and
  `https://maps.google.com/maps/api/` - so reCAPTCHA and Maps keep working
  without granting the bypass. `frame-src` is likewise narrowed to
  `https://www.google.com/recaptcha/`.
- **`.sql` files are no longer fetchable over HTTP.** `/init-db/` and
  `/migrations/` shipped inside the docroot without a deny rule, so the full
  database schema could be retrieved anonymously. A new
  `zz-fairtprm-sql-deny.conf` denies both directories and adds a blanket
  `<FilesMatch "\.sql$">` deny across the docroot. Schema and migration files
  still reach the database from inside the container (entrypoint seeding) and
  through the admin backup/update pages, which read them server-side.
- **Removed a stale, unauthenticated copy of the SQL-update page from the
  docroot.** `web/updates.php` was a fork of `includes/admin/updates.php`
  carrying the same `.sql` upload / apply / delete handlers but none of the
  `ADMIN_DISPATCH` guard, leaving an unauthenticated SQL handler in the webroot.
  Nothing referenced it - `admin.php` dispatches the guarded copy from
  `includes/admin/` - so it is deleted from the image as well as the tree.

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
