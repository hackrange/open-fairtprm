# =============================================================================
# Fair TPRM - v2.6.2 (bleeding-edge / pre-release)
#
# Self-contained overlay build for the v2.6.2 line. The base is
# dockerregistry.fairtprm.com/fairtprm:v2.6.1 — the published stable release, a
# single-layer flattened image that contains the full Fair TPRM filesystem (OS,
# Apache, PHP, app code, vendor libs). This Dockerfile overlays the v2.6.2
# changes onto that v2.6.1 base and tags the result v2.6.2.
#
#     docker pull dockerregistry.fairtprm.com/fairtprm:v2.6.1
#     docker buildx build --provenance=false --sbom=false \
#         -t dockerregistry.fairtprm.com/fairtprm:v2.6.2 --load .
#     docker push dockerregistry.fairtprm.com/fairtprm:v2.6.2
#     docker-compose down && docker-compose up -d
#
# Manifest format: ALWAYS build with --provenance=false --sbom=false and push
# from the local image store (--load then docker push). This publishes a single
# Docker schema-2 manifest (application/vnd.docker.distribution.manifest.v2+json).
# Do NOT let buildx publish an OCI image index (the default when attestations are
# on): the in-app upgrader (admin.php?section=version) on deployed v2.5.x/v2.6.x
# containers requests the manifest with a narrow Accept header that does NOT list
# OCI index types, so an index makes the registry return 404 MANIFEST_UNKNOWN and
# the "Upgrade" button fails with "Could not fetch manifest". A single schema-2
# manifest is accepted by every deployed version. We are single-arch (linux/amd64),
# so the index buys nothing here.
#
# Layer hygiene: this Dockerfile adds exactly TWO layers on top of the base
# (one COPY per source tree, then one RUN that places every file). The
# previous per-file COPY pattern produced ~65 layers per build and tripped
# Docker's storage-driver depth limit; consolidating keeps the image under
# the 10-layer hygiene cap regardless of how many files are patched.
#
# The RUN block is one logical shell line (Docker collapses \-continuations),
# so it cannot contain `#` comments inside; the file groupings below are
# organised by destination path, blank-line-separated for readability.
# sql_updates is purged to a single authoritative schema file (v2.6.2.sql).
#
# Two docroot files inherited from the base image are deleted rather than
# overwritten: lockdown.php, and updates.php -- a stale fork of the admin
# SQL-update page that carries the .sql upload/apply/delete handlers but none
# of the ADMIN_DISPATCH guard, i.e. an unauthenticated SQL handler in the
# webroot. admin.php dispatches the guarded copy from includes/admin/, so
# nothing references the docroot one.
# =============================================================================
FROM dockerregistry.fairtprm.com/fairtprm:v2.6.1

# Layer 1: stage every patch input under /tmp/build. One COPY per source tree
# (php_patches and icons live under different paths in persistent/).
COPY persistent/php_patches /tmp/build/php_patches
COPY persistent/icons       /tmp/build/icons

# Layer 2: place each file at its destination, set ownership, write VERSION,
# verify the manifest, and remove the staging tree. set -e + cp's exit codes
# cause the build to fail fast on any missing source file.
RUN set -e; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get update; \
    apt-get -y -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef dist-upgrade; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*; \
    pp=/tmp/build/php_patches; \
    \
    cp -rp $pp/api/.            /var/www/html/api/; \
    cp -rp $pp/cron/.           /var/www/html/cron/; \
    cp -rp $pp/includes/admin/. /var/www/html/includes/admin/; \
    mkdir -p /var/www/html/app/grip-reference; \
    cp -rp $pp/app/grip-reference/. /var/www/html/app/grip-reference/; \
    mkdir -p /var/www/html/app/hero-reference; \
    cp -rp $pp/app/hero-reference/. /var/www/html/app/hero-reference/; \
    mkdir -p /var/www/html/app/docs; \
    cp -rp $pp/app/docs/. /var/www/html/app/docs/; \
    mkdir -p /var/www/html/app/js; \
    cp -p  $pp/app/js/phone-input.js $pp/app/js/vat-input.js $pp/app/js/template-builder.js /var/www/html/app/js/; \
    mkdir -p /var/www/html/app/flags; \
    cp -rp $pp/app/flags/. /var/www/html/app/flags/; \
    \
    cp -p  $pp/sidebar_nav.php $pp/init.php $pp/FairCalculator.php $pp/contact-fields.php \
           $pp/cron-jobs.php \
           /var/www/html/includes/; \
    \
    cp -p  $pp/email.php $pp/fair-defaults.php $pp/field-reference.php \
           $pp/assessment-templates.php $pp/api-integration.php \
           /var/www/html/includes/admin/; \
    \
    cp -p  $pp/ACL.php $pp/Auth.php \
           $pp/EmailService.php $pp/LockdownService.php $pp/BreachAlertService.php \
           $pp/AIPlatformService.php $pp/GitHubOSINTScanner.php \
           $pp/ShodanClient.php $pp/ShodanService.php $pp/SRSService.php $pp/Security.php $pp/Session.php \
           $pp/VendorAssessmentService.php $pp/AssessmentTranslationService.php \
           $pp/CyberTodoService.php \
           $pp/GripService.php $pp/HeroService.php \
           $pp/ZscalerService.php $pp/SAMLHandler.php \
           $pp/FaviconService.php $pp/ContinuousMonitor.php $pp/VirusScanService.php \
           $pp/BackupService.php $pp/APIService.php \
           /var/www/html/includes/classes/; \
    \
    mkdir -p /var/www/html/includes/partials; \
    cp -rp $pp/includes/partials/. /var/www/html/includes/partials/; \
    \
    cp -p  $pp/config.sample.php /var/www/html/config/; \
    \
    mkdir -p /var/www/html/includes/i18n; \
    cp -rp $pp/i18n/. /var/www/html/includes/i18n/; \
    \
    mkdir -p /var/www/html/lang; \
    cp -rp $pp/lang/. /var/www/html/lang/; \
    \
    cp -p  $pp/search-vendors.php $pp/grc-unified-assessment.php \
           $pp/vendor-document-delete.php $pp/send-assessment-email.php \
           $pp/generate-fair-ai-analysis.php $pp/ai-models.php \
           /var/www/html/api/; \
    \
    cp -p  $pp/grc-task-digest.php /var/www/html/cron/; \
    \
    cp -p  $pp/fair-analysis.php $pp/breach-alerts.php $pp/api-breach-scan-status.php \
           $pp/cyber-todo.php $pp/fourth-party-risk.php \
           $pp/grc-assessment.php $pp/grc-assessment-report.php $pp/grc-audit-report.php \
           $pp/grc-audits.php $pp/grc-controls.php $pp/grc-crosswalk.php \
           $pp/grc-dashboard.php $pp/grc-evidence.php $pp/grc-fairscore.php \
           $pp/grc-framework-report.php $pp/grc-frameworks.php $pp/grc-gaps.php \
           $pp/grc-monitors.php $pp/grc-policies.php $pp/grc-risks.php \
           $pp/grc-tasks.php $pp/admin.php $pp/index.php $pp/login.php $pp/profile.php \
           $pp/procurement-contracts.php $pp/procurement-cyber-status.php $pp/shadow-saas.php $pp/grip-saas-users.php \
           $pp/vendor-assessment.php $pp/vendor-assessments.php \
           $pp/vendor-assessment-view.php $pp/vendor-detailed-summary.php \
           $pp/vendor-domains.php $pp/vendor-onboarding-list.php \
           $pp/vendor-onboarding.php \
           $pp/vendor-srs-list.php $pp/vendor-srs-compliance-report.php \
           $pp/view-result.php \
           $pp/admin-users-import.php $pp/api-rescore-status.php $pp/documentation.php $pp/reference-doc.php \
           $pp/download-pdf.php $pp/error.php $pp/fair_dashboard.php \
           $pp/fair_import-analysis.php $pp/fair_results.php $pp/logout.php \
           $pp/reports.php $pp/vendor-annual-review-history.php $pp/vendor-annual-review.php \
           $pp/vendor-annual-reviews-list.php $pp/vendor-onboarding-export.php \
           $pp/vendor-onboarding-import.php $pp/vendor-srs-details.php \
           $pp/vendor-srs-export.php $pp/vendor-srs-import.php \
           /var/www/html/; \
    \
    cp -p  $pp/v2.6.2.sql /var/www/html/sql_updates/; \
    find /var/www/html/sql_updates -maxdepth 1 -type f \
         ! -name 'v2.6.2.sql' ! -name 'index.html' -delete; \
    rm -rf /var/www/html/sql_updates/.applied_hashes; \
    \
    cp -p  $pp/grc-assessment-rules.conf /etc/modsecurity/grc-assessment-rules.conf; \
    cp -p  $pp/open-tprm.cron /etc/cron.d/open-tprm; \
    chmod 644 /etc/cron.d/open-tprm; \
    \
    cp -p  $pp/entrypoint.sh /usr/local/bin/entrypoint.sh; \
    chmod 755 /usr/local/bin/entrypoint.sh; \
    sed -i 's/^expose_php = On/expose_php = Off/' /etc/php/*/cli/php.ini; \
    sed -i 's/^Checks[[:space:]].*/Checks 1/' /etc/clamav/freshclam.conf; \
    \
    cp -p  $pp/fairtprm-tuning.cnf /etc/mysql/mariadb.conf.d/99-fairtprm-tuning.cnf; \
    chmod 644 /etc/mysql/mariadb.conf.d/99-fairtprm-tuning.cnf; \
    for ini in /etc/php/*/apache2/conf.d/99-hardening.ini /etc/php/*/fpm/conf.d/99-hardening.ini /etc/php/*/cli/conf.d/99-hardening.ini; do \
        [ -f "$ini" ] && printf '\n; Large-backup restore/upload headroom (appended; last value wins)\nupload_max_filesize = 8G\npost_max_size = 8G\nmax_input_time = 1800\n' >> "$ini"; \
    done; \
    printf 'Timeout 1800\n<IfModule mod_proxy.c>\nProxyTimeout 1800\n</IfModule>\n' > /etc/apache2/conf-enabled/zz-fairtprm-timeout.conf; \
    printf '# API reference docs (OpenAPI/Swagger/Postman) must not be world-readable\n# static files. Deny direct access; they are served by reference-doc.php behind\n# the admin session.\n<LocationMatch "^/app/(grip|hero)-reference/">\n    Require all denied\n</LocationMatch>\n' > /etc/apache2/conf-enabled/zz-fairtprm-reference-deny.conf; \
    printf '# No .sql file is ever served directly over HTTP. Schema and migration files\n# reach the database from inside the container (entrypoint seeding) or through\n# the admin backup/update pages, which read them server-side. Fetching one off\n# the docroot is never legitimate.\n<LocationMatch "^/(init-db|migrations)/">\n    Require all denied\n</LocationMatch>\n<FilesMatch "\\.sql$">\n    Require all denied\n</FilesMatch>\n' > /etc/apache2/conf-enabled/zz-fairtprm-sql-deny.conf; \
    \
    composer require onelogin/php-saml:^4.3 --no-interaction --no-progress \
        --working-dir=/var/www/html; \
    \
    cp -p  /tmp/build/icons/announcement-03.svg /var/www/html/app/icons/announcement-03.svg; \
    \
    rm -f /var/www/html/lockdown.php; \
    rm -f /var/www/html/updates.php; \
    \
    cp -p  $pp/LICENSE.md /var/www/html/LICENSE.md; \
    \
    echo 'v2.6.2' > /var/www/html/VERSION; \
    chown -R www-data:www-data /var/www/html/; \
    rm -rf /tmp/build; \
    \
    for f in \
        /var/www/html/includes/sidebar_nav.php \
        /var/www/html/includes/init.php \
        /var/www/html/includes/cron-jobs.php \
        /var/www/html/includes/FairCalculator.php \
        /var/www/html/includes/admin/email.php \
        /var/www/html/includes/admin/fair-defaults.php \
        /var/www/html/includes/admin/field-reference.php \
        /var/www/html/includes/admin/assessment-templates.php \
        /var/www/html/includes/admin/api-integration.php \
        /var/www/html/includes/admin/shadow-saas-grip.php \
        /var/www/html/includes/admin/version.php \
        /var/www/html/includes/admin/general.php \
        /var/www/html/includes/admin/ai-platform.php \
        /var/www/html/includes/admin/api-tokens.php \
        /var/www/html/includes/admin/backup.php \
        /var/www/html/includes/admin/lockdown.php \
        /var/www/html/includes/admin/saml.php \
        /var/www/html/includes/admin/scheduler.php \
        /var/www/html/includes/admin/shodan-srs.php \
        /var/www/html/includes/admin/srs.php \
        /var/www/html/includes/admin/updates.php \
        /var/www/html/includes/admin/users.php \
        /var/www/html/includes/admin/workflows.php \
        /var/www/html/profile.php \
        /var/www/html/includes/classes/AssessmentTranslationService.php \
        /var/www/html/includes/classes/CyberTodoService.php \
        /var/www/html/includes/i18n/en.php \
        /var/www/html/includes/i18n/es.php \
        /var/www/html/includes/i18n/it.php \
        /var/www/html/includes/i18n/uk.php \
        /var/www/html/includes/i18n/zh-Hans.php \
        /var/www/html/includes/i18n/hi.php \
        /var/www/html/includes/i18n/fr.php \
        /var/www/html/includes/i18n/pt.php \
        /var/www/html/api/assessment-submit.php \
        /var/www/html/api/assessment-certificate-upload.php \
        /var/www/html/includes/classes/EmailService.php \
        /var/www/html/includes/classes/LockdownService.php \
        /var/www/html/includes/classes/BreachAlertService.php \
        /var/www/html/includes/classes/AIPlatformService.php \
        /var/www/html/includes/classes/GitHubOSINTScanner.php \
        /var/www/html/includes/classes/ShodanClient.php \
        /var/www/html/includes/classes/ShodanService.php \
        /var/www/html/includes/classes/Auth.php \
        /var/www/html/includes/classes/Security.php \
        /var/www/html/includes/classes/Session.php \
        /var/www/html/includes/classes/VendorAssessmentService.php \
        /var/www/html/includes/classes/GripService.php \
        /var/www/html/includes/classes/HeroService.php \
        /var/www/html/includes/classes/ZscalerService.php \
        /var/www/html/includes/classes/SAMLHandler.php \
        /var/www/html/includes/classes/FaviconService.php \
        /var/www/html/includes/classes/ContinuousMonitor.php \
        /var/www/html/includes/classes/VirusScanService.php \
        /var/www/html/includes/classes/APIService.php \
        /var/www/html/includes/partials/vendor-tasks-data.php \
        /var/www/html/vendor/onelogin/php-saml/src/Saml2/Response.php \
        /var/www/html/config/config.sample.php \
        /var/www/html/api/ai-job-status.php \
        /var/www/html/api/assessment-ai-apply.php \
        /var/www/html/api/assessment-download-pdf.php \
        /var/www/html/api/assessment-import-pdf.php \
        /var/www/html/api/assessment-download-xlsx.php \
        /var/www/html/api/assessment-import.php \
        /var/www/html/api/vendor-document-upload.php \
        /var/www/html/api/search-vendors.php \
        /var/www/html/api/grc-unified-assessment.php \
        /var/www/html/api/vendor-document-delete.php \
        /var/www/html/api/send-assessment-email.php \
        /var/www/html/api/generate-fair-ai-analysis.php \
        /var/www/html/api/ai-models.php \
        /var/www/html/api/procurement-update-save.php \
        /var/www/html/api/procurement-notes-list.php \
        /var/www/html/api/procurement-note-manage.php \
        /var/www/html/cron/ai-queue.php \
        /var/www/html/cron/breach-monitor.php \
        /var/www/html/cron/breach-scan-queue.php \
        /var/www/html/cron/grip-sync.php \
        /var/www/html/cron/osint-company.php \
        /var/www/html/cron/osint-vendors.php \
        /var/www/html/cron/sync-crontab.php \
        /var/www/html/cron/shadow-saas-sync.php \
        /var/www/html/cron/grc-task-digest.php \
        /var/www/html/cron/procurement-digest.php \
        /var/www/html/cron/onboarding-scheduled-actions.php \
        /var/www/html/app/grip-reference/grip-openapi.yaml \
        /var/www/html/app/grip-reference/grip-postman-collection.json \
        /var/www/html/app/hero-reference/hero-openapi.yaml \
        /var/www/html/app/docs/dashboard.png \
        /var/www/html/app/docs/profile-language-card.png \
        /var/www/html/app/docs/procurement-cyber-status.png \
        /var/www/html/app/docs/grip-card.png \
        /var/www/html/app/docs/zscaler-card.png \
        /var/www/html/app/docs/vendor-info-card.png \
        /var/www/html/app/docs/procurement-cyber-status-updates.png \
        /var/www/html/app/docs/grc-fairscore.png \
        /var/www/html/app/docs/grc-gaps.png \
        /var/www/html/app/docs/grc-frameworks.png \
        /var/www/html/app/docs/grc-assessment.png \
        /var/www/html/app/docs/grc-assessment-questions.png \
        /var/www/html/app/icons/announcement-03.svg \
        /var/www/html/app/flags/us.svg \
        /var/www/html/fair-analysis.php \
        /var/www/html/breach-alerts.php \
        /var/www/html/api-breach-scan-status.php \
        /var/www/html/cyber-todo.php \
        /var/www/html/fourth-party-risk.php \
        /var/www/html/grc-assessment.php \
        /var/www/html/grc-assessment-report.php \
        /var/www/html/grc-audit-report.php \
        /var/www/html/grc-audits.php \
        /var/www/html/grc-controls.php \
        /var/www/html/grc-crosswalk.php \
        /var/www/html/grc-dashboard.php \
        /var/www/html/grc-evidence.php \
        /var/www/html/grc-fairscore.php \
        /var/www/html/grc-framework-report.php \
        /var/www/html/grc-frameworks.php \
        /var/www/html/grc-gaps.php \
        /var/www/html/grc-monitors.php \
        /var/www/html/grc-policies.php \
        /var/www/html/grc-risks.php \
        /var/www/html/grc-tasks.php \
        /var/www/html/admin.php \
        /var/www/html/index.php \
        /var/www/html/login.php \
        /var/www/html/procurement-contracts.php \
        /var/www/html/procurement-cyber-status.php \
        /var/www/html/shadow-saas.php \
        /var/www/html/grip-saas-users.php \
        /var/www/html/vendor-assessment.php \
        /var/www/html/vendor-assessments.php \
        /var/www/html/vendor-assessment-view.php \
        /var/www/html/vendor-detailed-summary.php \
        /var/www/html/vendor-domains.php \
        /var/www/html/vendor-onboarding-list.php \
        /var/www/html/vendor-onboarding.php \
        /var/www/html/vendor-srs-list.php \
        /var/www/html/vendor-srs-compliance-report.php \
        /var/www/html/view-result.php \
        /var/www/html/admin-users-import.php \
        /var/www/html/api-rescore-status.php \
        /var/www/html/documentation.php \
        /var/www/html/lang/en/documentation.php \
        /var/www/html/lang/es/documentation.php \
        /var/www/html/lang/fr/documentation.php \
        /var/www/html/lang/hi/documentation.php \
        /var/www/html/lang/it/documentation.php \
        /var/www/html/lang/pt/documentation.php \
        /var/www/html/lang/uk/documentation.php \
        /var/www/html/lang/zh-Hans/documentation.php \
        /var/www/html/reference-doc.php \
        /var/www/html/download-pdf.php \
        /var/www/html/error.php \
        /var/www/html/fair_dashboard.php \
        /var/www/html/fair_import-analysis.php \
        /var/www/html/fair_results.php \
        /var/www/html/logout.php \
        /var/www/html/reports.php \
        /var/www/html/vendor-annual-review-history.php \
        /var/www/html/vendor-annual-review.php \
        /var/www/html/vendor-annual-reviews-list.php \
        /var/www/html/vendor-onboarding-export.php \
        /var/www/html/vendor-onboarding-import.php \
        /var/www/html/vendor-srs-details.php \
        /var/www/html/vendor-srs-export.php \
        /var/www/html/vendor-srs-import.php \
        /var/www/html/includes/i18n/en.php \
        /var/www/html/includes/contact-fields.php \
        /var/www/html/app/js/phone-input.js \
        /var/www/html/app/js/vat-input.js \
        /var/www/html/api/vat-validate.php \
        /var/www/html/api/template-question-save.php \
        /var/www/html/sql_updates/v2.6.2.sql \
        /usr/local/bin/entrypoint.sh \
        /etc/mysql/mariadb.conf.d/99-fairtprm-tuning.cnf \
        /etc/apache2/conf-enabled/zz-fairtprm-timeout.conf \
        /etc/apache2/conf-enabled/zz-fairtprm-reference-deny.conf \
        /etc/modsecurity/grc-assessment-rules.conf \
        /etc/cron.d/open-tprm \
        /var/www/html/VERSION \
        /var/www/html/LICENSE.md \
    ; do \
        if [ ! -f "$f" ]; then echo "MANIFEST FAIL: missing $f"; exit 1; fi; \
    done; \
    stray="$(ls /var/www/html/sql_updates/*.sql 2>/dev/null | grep -v '/v2.6.2.sql$' || true)"; \
    if [ -n "$stray" ]; then echo "CLEAN FAIL: stray schema files remain:"; echo "$stray"; exit 1; fi; \
    echo "Manifest verification passed; sql_updates contains only v2.6.2.sql"
