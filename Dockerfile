# =============================================================================
# Fair TPRM - v2.6.1
#
# Self-contained build. The base is dockerregistry.fairtprm.com/fairtprm:v2.6.1
# itself — a single-layer flattened image that contains the full Fair TPRM
# filesystem (OS, Apache, PHP, app code, vendor libs). The v2.6.1 base was
# produced by flattening this overlay onto the prior v2.5.8 base (one-time
# version bump); builds are now self-referential on v2.6.1.
#
#     docker pull dockerregistry.fairtprm.com/fairtprm:v2.6.1
#     docker build -t dockerregistry.fairtprm.com/fairtprm:v2.6.1 .
#     docker-compose down && docker-compose up -d
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
# sql_updates is purged to a single authoritative schema file (v2.6.1.sql).
# =============================================================================
FROM dockerregistry.fairtprm.com/fairtprm:v2.5.8

# Layer 1: stage every patch input under /tmp/build. One COPY per source tree
# (php_patches and icons live under different paths in persistent/).
COPY persistent/php_patches /tmp/build/php_patches
COPY persistent/icons       /tmp/build/icons

# Layer 2: place each file at its destination, set ownership, write VERSION,
# verify the manifest, and remove the staging tree. set -e + cp's exit codes
# cause the build to fail fast on any missing source file.
RUN set -e; \
    pp=/tmp/build/php_patches; \
    \
    cp -rp $pp/api/.            /var/www/html/api/; \
    cp -rp $pp/cron/.           /var/www/html/cron/; \
    cp -rp $pp/includes/admin/. /var/www/html/includes/admin/; \
    mkdir -p /var/www/html/app/grip-reference; \
    cp -rp $pp/app/grip-reference/. /var/www/html/app/grip-reference/; \
    \
    cp -p  $pp/sidebar_nav.php $pp/init.php $pp/FairCalculator.php \
           /var/www/html/includes/; \
    \
    cp -p  $pp/email.php $pp/fair-defaults.php $pp/field-reference.php \
           $pp/assessment-templates.php $pp/api-integration.php \
           /var/www/html/includes/admin/; \
    \
    cp -p  $pp/EmailService.php $pp/LockdownService.php $pp/BreachAlertService.php \
           $pp/AIPlatformService.php $pp/GitHubOSINTScanner.php \
           $pp/ShodanClient.php $pp/ShodanService.php $pp/Security.php \
           $pp/VendorAssessmentService.php $pp/AssessmentTranslationService.php \
           $pp/GripService.php \
           $pp/ZscalerService.php $pp/SAMLHandler.php \
           /var/www/html/includes/classes/; \
    \
    cp -p  $pp/config.sample.php /var/www/html/config/; \
    \
    mkdir -p /var/www/html/includes/i18n; \
    cp -rp $pp/i18n/. /var/www/html/includes/i18n/; \
    \
    cp -p  $pp/search-vendors.php $pp/grc-unified-assessment.php \
           $pp/vendor-document-delete.php $pp/send-assessment-email.php \
           $pp/generate-fair-ai-analysis.php \
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
           $pp/lockdown.php $pp/procurement-contracts.php $pp/shadow-saas.php \
           $pp/updates.php $pp/vendor-assessment.php $pp/vendor-assessments.php \
           $pp/vendor-assessment-view.php $pp/vendor-detailed-summary.php \
           $pp/vendor-domains.php $pp/vendor-onboarding-list.php \
           $pp/vendor-onboarding.php \
           $pp/vendor-srs-list.php $pp/vendor-srs-compliance-report.php \
           $pp/view-result.php \
           $pp/admin-users-import.php $pp/api-rescore-status.php $pp/documentation.php \
           $pp/download-pdf.php $pp/error.php $pp/fair_dashboard.php \
           $pp/fair_import-analysis.php $pp/fair_results.php $pp/logout.php \
           $pp/reports.php $pp/vendor-annual-review-history.php $pp/vendor-annual-review.php \
           $pp/vendor-annual-reviews-list.php $pp/vendor-onboarding-export.php \
           $pp/vendor-onboarding-import.php $pp/vendor-srs-details.php \
           $pp/vendor-srs-export.php $pp/vendor-srs-import.php \
           /var/www/html/; \
    \
    cp -p  $pp/v2.6.1.sql /var/www/html/sql_updates/; \
    find /var/www/html/sql_updates -maxdepth 1 -type f \
         ! -name 'v2.6.1.sql' ! -name 'index.html' -delete; \
    rm -rf /var/www/html/sql_updates/.applied_hashes; \
    \
    cp -p  $pp/grc-assessment-rules.conf /etc/modsecurity/grc-assessment-rules.conf; \
    cp -p  $pp/open-tprm.cron /etc/cron.d/open-tprm; \
    chmod 644 /etc/cron.d/open-tprm; \
    \
    cp -p  $pp/entrypoint.sh /usr/local/bin/entrypoint.sh; \
    chmod 755 /usr/local/bin/entrypoint.sh; \
    sed -i 's/^expose_php = On/expose_php = Off/' /etc/php/*/cli/php.ini; \
    \
    composer require onelogin/php-saml:^4.3 --no-interaction --no-progress \
        --working-dir=/var/www/html; \
    \
    cp -p  /tmp/build/icons/announcement-03.svg /var/www/html/app/icons/announcement-03.svg; \
    \
    echo 'v2.6.1' > /var/www/html/VERSION; \
    chown -R www-data:www-data /var/www/html/; \
    rm -rf /tmp/build; \
    \
    for f in \
        /var/www/html/includes/sidebar_nav.php \
        /var/www/html/includes/init.php \
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
        /var/www/html/includes/classes/Security.php \
        /var/www/html/includes/classes/VendorAssessmentService.php \
        /var/www/html/includes/classes/GripService.php \
        /var/www/html/includes/classes/ZscalerService.php \
        /var/www/html/includes/classes/SAMLHandler.php \
        /var/www/html/vendor/onelogin/php-saml/src/Saml2/Response.php \
        /var/www/html/config/config.sample.php \
        /var/www/html/api/ai-job-status.php \
        /var/www/html/api/assessment-ai-apply.php \
        /var/www/html/api/assessment-download-pdf.php \
        /var/www/html/api/assessment-import-pdf.php \
        /var/www/html/api/vendor-document-upload.php \
        /var/www/html/api/search-vendors.php \
        /var/www/html/api/grc-unified-assessment.php \
        /var/www/html/api/vendor-document-delete.php \
        /var/www/html/api/send-assessment-email.php \
        /var/www/html/api/generate-fair-ai-analysis.php \
        /var/www/html/cron/ai-queue.php \
        /var/www/html/cron/breach-monitor.php \
        /var/www/html/cron/breach-scan-queue.php \
        /var/www/html/cron/grip-sync.php \
        /var/www/html/cron/osint-company.php \
        /var/www/html/cron/osint-vendors.php \
        /var/www/html/cron/sync-crontab.php \
        /var/www/html/cron/grc-task-digest.php \
        /var/www/html/app/grip-reference/grip-openapi.yaml \
        /var/www/html/app/grip-reference/grip-postman-collection.json \
        /var/www/html/app/icons/announcement-03.svg \
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
        /var/www/html/lockdown.php \
        /var/www/html/procurement-contracts.php \
        /var/www/html/shadow-saas.php \
        /var/www/html/updates.php \
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
        /var/www/html/sql_updates/v2.6.1.sql \
        /usr/local/bin/entrypoint.sh \
        /etc/modsecurity/grc-assessment-rules.conf \
        /etc/cron.d/open-tprm \
        /var/www/html/VERSION \
    ; do \
        if [ ! -f "$f" ]; then echo "MANIFEST FAIL: missing $f"; exit 1; fi; \
    done; \
    stray="$(ls /var/www/html/sql_updates/*.sql 2>/dev/null | grep -v '/v2.6.1.sql$' || true)"; \
    if [ -n "$stray" ]; then echo "CLEAN FAIL: stray schema files remain:"; echo "$stray"; exit 1; fi; \
    echo "Manifest verification passed; sql_updates contains only v2.6.1.sql"
