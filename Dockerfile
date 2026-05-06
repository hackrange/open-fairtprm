# =============================================================================
# Fair TPRM & GRC Platform
# Debian Bookworm + Apache 2.4 + PHP 8.5 + MariaDB + ClamAV + ModSecurity
#
# Two-stage build: the `builder` stage performs all install/config steps,
# then the final stage copies the resulting filesystem into a single layer
# via FROM scratch. Keeps the image at 1 filesystem layer instead of ~30+.
# =============================================================================
FROM debian:bookworm-slim AS builder

ENV DEBIAN_FRONTEND=noninteractive

# ---- System packages: PHP 8.5 from Sury repo, MariaDB, ClamAV, ModSecurity ----
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        lsb-release \
    && curl -fsSL https://packages.sury.org/php/apt.gpg \
       | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ bookworm main" \
       > /etc/apt/sources.list.d/sury-php.list \
    && apt-get update

RUN apt-get install -y --no-install-recommends \
        apache2 \
        libapache2-mod-php8.5 \
        mariadb-server \
        mariadb-client \
        php8.5 \
        php8.5-cli \
        php8.5-common \
        php8.5-curl \
        php8.5-gd \
        php8.5-mbstring \
        php8.5-mysql \
        php8.5-xml \
        php8.5-xsl \
        php8.5-zip \
        php8.5-sqlite3 \
        php8.5-readline \
        php8.5-intl \
        openssl \
        tar \
        cron \
        coreutils \
        sudo \
        poppler-utils \
        clamav \
        clamav-daemon \
        clamav-freshclam \
        libapache2-mod-security2 \
    && apt-get upgrade -y \
    && rm -rf /var/lib/apt/lists/*

# ---- Apache configuration ----
RUN a2enmod rewrite headers deflate remoteip security2 \
    && a2dismod -f status

# ModSecurity configuration
COPY docker/modsecurity/modsecurity.conf /etc/modsecurity/modsecurity.conf
COPY docker/modsecurity/lockdown-whitelist.conf /etc/modsecurity/lockdown-whitelist.conf
COPY docker/modsecurity/scanner-detection.conf /etc/modsecurity/scanner-detection.conf
COPY docker/modsecurity/grc-assessment-rules.conf /etc/modsecurity/grc-assessment-rules.conf

# ModSecurity persistent dirs and sudoers for www-data WAF management
RUN mkdir -p /persistent/modsecurity/audit_log /persistent/modsecurity/rules \
    && chown -R www-data:www-data /persistent/modsecurity \
    && echo 'www-data ALL=(root) NOPASSWD: /usr/bin/tee /etc/modsecurity/modsecurity.conf' \
       > /etc/sudoers.d/modsecurity-writer \
    && echo 'www-data ALL=(root) NOPASSWD: /usr/bin/tee /etc/modsecurity/lockdown-whitelist.conf' \
       >> /etc/sudoers.d/modsecurity-writer \
    && echo 'www-data ALL=(root) NOPASSWD: /usr/bin/tee /persistent/modsecurity/modsecurity.conf' \
       >> /etc/sudoers.d/modsecurity-writer \
    && echo 'www-data ALL=(root) NOPASSWD: /usr/bin/tee /persistent/modsecurity/lockdown-whitelist.conf' \
       >> /etc/sudoers.d/modsecurity-writer \
    && echo 'www-data ALL=(root) NOPASSWD: /usr/bin/tee /etc/modsecurity/scanner-detection.conf' \
       >> /etc/sudoers.d/modsecurity-writer \
    && echo 'www-data ALL=(root) NOPASSWD: /usr/bin/tee /persistent/modsecurity/scanner-detection.conf' \
       >> /etc/sudoers.d/modsecurity-writer \
    && echo 'www-data ALL=(root) NOPASSWD: /usr/bin/tee /persistent/modsecurity/audit_log/modsec_audit.json' \
       >> /etc/sudoers.d/modsecurity-writer \
    && echo 'www-data ALL=(root) NOPASSWD: /usr/sbin/apachectl graceful' \
       >> /etc/sudoers.d/modsecurity-writer \
    && echo 'www-data ALL=(root) NOPASSWD: /usr/sbin/apachectl configtest' \
       >> /etc/sudoers.d/modsecurity-writer \
    && chmod 0440 /etc/sudoers.d/modsecurity-writer

# Listen on 8080, disable default-ssl
RUN echo "Listen 8080" > /etc/apache2/ports.conf
RUN a2dissite default-ssl 2>/dev/null || true
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
RUN sed -i 's/^KeepAlive On$/KeepAlive Off/' /etc/apache2/apache2.conf

# Server-level security directives (cannot be inside <VirtualHost>)
RUN printf '# Minimize server info disclosure (OWASP A05:2021)\nServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' \
    > /etc/apache2/conf-available/security-hardening.conf \
    && a2enconf security-hardening

# ---- PHP hardening ----
COPY docker/php-hardening.ini /etc/php/8.5/apache2/conf.d/99-hardening.ini

# ---- Cron jobs ----
COPY docker/crontab /etc/cron.d/open-tprm
RUN chmod 0644 /etc/cron.d/open-tprm \
    && echo 'www-data ALL=(root) NOPASSWD: /usr/bin/tee /etc/cron.d/open-tprm' \
       > /etc/sudoers.d/crontab-writer \
    && chmod 0440 /etc/sudoers.d/crontab-writer

# ---- ClamAV ----
COPY docker/clamd.conf /etc/clamav/clamd.conf
RUN mkdir -p /var/run/clamav /var/log/clamav \
    && chown clamav:clamav /var/run/clamav /var/log/clamav \
    && freshclam \
    && usermod -aG clamav www-data

# ---- Composer ----
RUN curl -sS https://getcomposer.org/installer \
      | php -- --install-dir=/usr/local/bin --filename=composer

# ---- Application code ----
COPY web/ /var/www/html/

# Remove build artifacts that should not ship in the image
RUN rm -rf /var/www/html/docker \
           /var/www/html/Dockerfile \
           /var/www/html/Dockerfile.mariadb \
           /var/www/html/docker-compose.yml \
           /var/www/html/.dockerignore \
           /var/www/html/db_backup \
           /var/www/html/persistent \
           /var/www/html/index.html

# Install PHP dependencies
RUN cd /var/www/html && COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-interaction --optimize-autoloader 2>/dev/null || true

# ---- Prepare runtime directories and permissions ----
RUN mkdir -p /var/log/php \
    && chown www-data:www-data /var/log/php \
    && mkdir -p /var/www/html/config/logs \
    && chown -R www-data:www-data /var/www/html \
    && mkdir -p /run/mysqld \
    && chown mysql:mysql /run/mysqld \
    && mkdir -p /persistent

# ---- Entrypoint ----
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# ---- Write version file ----
RUN echo 'v2.5.8' > /var/www/html/VERSION \
    && chown -R www-data:www-data /var/www/html/

# =============================================================================
# Final stage — squash builder filesystem into a single layer.
# FROM scratch produces a one-layer image; metadata directives below add
# manifest entries but not additional filesystem layers.
# =============================================================================
FROM scratch

COPY --from=builder / /

LABEL maintainer="Tim Rice - Hack Range"
LABEL version="2.5.8"

EXPOSE 8080/tcp

VOLUME ["/persistent", "/var/lib/mysql"]

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:8080/login.php || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
