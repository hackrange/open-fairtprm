#!/bin/bash
set -e

PASSWORDS_FILE="/var/lib/mysql/.passwords"
MY_CNF="/root/.my.cnf"

# =========================================================================
# 1. Populate persistent directory with defaults (first time only)
# =========================================================================
if [ ! -f /persistent/.initialized ]; then
    echo "==> Initializing persistent directory with defaults..."

    mkdir -p /persistent/config/logs
    mkdir -p /persistent/sql_updates
    mkdir -p /persistent/branding
    mkdir -p /persistent/db_backup

    # Copy config sample as starting config.php
    if [ ! -f /persistent/config/config.php ]; then
        cp /var/www/html/config/config.sample.php /persistent/config/config.php
    fi

    # Copy branding images from template originals (app/images/ may be symlinks
    # if the image was built from a committed container)
    for src in /var/www/html/app/template/site/images/logo-default-418x78.png \
               /var/www/html/app/template/site/images/logo-inverse-416x78.png \
               /var/www/html/app/template/site/images/favicon.ico; do
        [ -f "$src" ] && cp "$src" /persistent/branding/
    done

    touch /persistent/.initialized
fi

# =========================================================================
# 2. Symlink persistent files into the application tree
# =========================================================================
# Config
ln -sf /persistent/config/config.php /var/www/html/config/config.php
rm -rf /var/www/html/config/logs
ln -sf /persistent/config/logs /var/www/html/config/logs

# SQL updates: kept in image (no symlink). Tracking state in /persistent/sql_updates/
mkdir -p /persistent/sql_updates/.applied_hashes

# Database backups (survive container rebuilds)
rm -rf /var/www/html/db_backup
mkdir -p /persistent/db_backup
ln -sf /persistent/db_backup /var/www/html/db_backup
chown -R www-data:www-data /persistent/db_backup

# Branding (logos & favicon)
if [ -f /persistent/branding/logo-default-418x78.png ]; then
    ln -sf /persistent/branding/logo-default-418x78.png /var/www/html/app/images/logo-default-418x78.png
fi
if [ -f /persistent/branding/logo-inverse-416x78.png ]; then
    ln -sf /persistent/branding/logo-inverse-416x78.png /var/www/html/app/images/logo-inverse-416x78.png
fi
if [ -f /persistent/branding/favicon.ico ]; then
    ln -sf /persistent/branding/favicon.ico /var/www/html/app/images/favicon.ico
fi

# Ensure www-data can write to logs and branding
chown -R www-data:www-data /persistent/config/logs
chown -R www-data:www-data /persistent/branding

# =========================================================================
# 2b. WAF (ModSecurity) persistent directory init
# =========================================================================
mkdir -p /persistent/modsecurity/audit_log /persistent/modsecurity/rules
chown -R www-data:www-data /persistent/modsecurity

# Restore WAF config from persistent storage (preserves mode across restarts)
if [ -f /persistent/modsecurity/modsecurity.conf ]; then
    cp /persistent/modsecurity/modsecurity.conf /etc/modsecurity/modsecurity.conf
fi
if [ -f /persistent/modsecurity/lockdown-whitelist.conf ]; then
    cp /persistent/modsecurity/lockdown-whitelist.conf /etc/modsecurity/lockdown-whitelist.conf
fi
if [ -f /persistent/modsecurity/scanner-detection.conf ]; then
    cp /persistent/modsecurity/scanner-detection.conf /etc/modsecurity/scanner-detection.conf
fi

# =========================================================================
# 3. Initialize MariaDB data directory if needed
# =========================================================================
if [ ! -d "/var/lib/mysql/mysql" ]; then
    echo "==> Initializing MariaDB data directory..."
    mysql_install_db --user=mysql --datadir=/var/lib/mysql > /dev/null 2>&1
fi

# Ensure runtime directory exists
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld

# =========================================================================
# 4. Start MariaDB
# =========================================================================
echo "==> Starting MariaDB..."
mysqld_safe --user=mysql &

# Wait for MariaDB to be ready
for i in $(seq 1 30); do
    if mysqladmin ping --silent 2>/dev/null; then
        break
    fi
    sleep 1
done

if ! mysqladmin ping --silent 2>/dev/null; then
    echo "ERROR: MariaDB failed to start within 30 seconds"
    exit 1
fi
echo "==> MariaDB is running."

# =========================================================================
# 5. MariaDB root password setup (only when no password file exists)
#    This happens once per data volume lifecycle.
# =========================================================================
if [ ! -f "$PASSWORDS_FILE" ]; then
    echo "==> First start: generating passwords..."
    MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32 | tr -d '/+=' | head -c 32)
    MYSQL_USER_PASSWORD=$(openssl rand -base64 32 | tr -d '/+=' | head -c 32)
    ENCRYPTION_KEY=$(openssl rand -base64 32)
    ADMIN_PASSWORD=$(openssl rand -base64 16 | tr -d '/+=' | head -c 16)

    # Set root password (fresh install has no password)
    mysql -u root <<EOSQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASSWORD}';
FLUSH PRIVILEGES;
EOSQL

    # Store passwords in the data volume
    cat > "$PASSWORDS_FILE" <<EOF
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
MYSQL_USER_PASSWORD=${MYSQL_USER_PASSWORD}
ENCRYPTION_KEY=${ENCRYPTION_KEY}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
    chmod 600 "$PASSWORDS_FILE"

    FIRST_START=true
else
    # Load existing passwords
    source "$PASSWORDS_FILE"
    FIRST_START=false

    # Older images didn't generate ADMIN_PASSWORD — backfill if missing
    if [ -z "$ADMIN_PASSWORD" ]; then
        echo "==> Generating missing admin password (upgrade from older image)..."
        ADMIN_PASSWORD=$(openssl rand -base64 16 | tr -d '/+=' | head -c 16)
        echo "ADMIN_PASSWORD=${ADMIN_PASSWORD}" >> "$PASSWORDS_FILE"
        UPDATE_ADMIN_PASSWORD=true
    fi
fi

# =========================================================================
# 6. Write /root/.my.cnf so mysql commands work without -p
# =========================================================================
cat > "$MY_CNF" <<EOF
[client]
user=root
password=${MYSQL_ROOT_PASSWORD}
EOF
chmod 600 "$MY_CNF"

# =========================================================================
# 6b. Does the application database already exist?
#     Everything below keys off this rather than off FIRST_START. The two can
#     disagree: $PASSWORDS_FILE lives in the MariaDB volume, so deleting it
#     without dropping the database would otherwise make us re-issue an admin
#     password the database has never seen.
# =========================================================================
if mysql -e "USE tprm" 2>/dev/null; then
    DB_EXISTS=true
else
    DB_EXISTS=false
fi

# =========================================================================
# 6c. Fresh database volume, but /persistent survived from an earlier install.
#
#     config.php and .sensitive live in the persistent volume; $PASSWORDS_FILE
#     lives in the MariaDB volume. Recreating one without the other used to
#     leave config.php pointing at a database user the new database never
#     created ("Access denied for user 'tprm_user'"), and .sensitive
#     advertising an admin password the new database never hashed.
#
#     Seed the new database with the credentials config.php already carries
#     rather than rewriting a config we did not author. The admin password is
#     the one exception: it is not recoverable from config.php, so it is
#     regenerated and .sensitive is rewritten to match (see section 7b).
# =========================================================================
if [ "$DB_EXISTS" = false ] && [ -f /persistent/config/config.php ] && \
   ! grep -q 'CHANGE_THIS_PASSWORD' /persistent/config/config.php 2>/dev/null; then
    echo "==> Existing /persistent with a fresh database volume - adopting its credentials..."

    adopted_db_pass=$(php -r '$c = @require "/persistent/config/config.php"; echo $c["database"]["password"] ?? "";' 2>/dev/null)
    adopted_key=$(php -r '$c = @require "/persistent/config/config.php"; echo $c["encryption"]["key"] ?? "";' 2>/dev/null)

    if [ -n "$adopted_db_pass" ]; then
        MYSQL_USER_PASSWORD="$adopted_db_pass"
        echo "    - Database password taken from config.php."
    fi
    # Keep the old key or every value already encrypted under it becomes garbage.
    if [ -n "$adopted_key" ]; then
        ENCRYPTION_KEY="$adopted_key"
        echo "    - Encryption key taken from config.php."
    fi

    # The passwords file must agree, or the next start adopts nothing and drifts again.
    sed -i -e "s|^MYSQL_USER_PASSWORD=.*|MYSQL_USER_PASSWORD=${MYSQL_USER_PASSWORD}|" \
           -e "s|^ENCRYPTION_KEY=.*|ENCRYPTION_KEY=${ENCRYPTION_KEY}|" "$PASSWORDS_FILE"
fi

# =========================================================================
# 7. Ensure config.php always has real credentials (not placeholders)
#    This runs every start so config.php is correct even if persistent/
#    was re-initialized while the DB volume was preserved.
# =========================================================================
if [ -f /persistent/config/config.php ]; then
    if grep -q 'CHANGE_THIS_PASSWORD' /persistent/config/config.php 2>/dev/null; then
        echo "==> Injecting credentials into config.php..."
        sed -i "s|'host' => 'localhost'|'host' => '127.0.0.1'|g" /persistent/config/config.php
        sed -i "s|CHANGE_THIS_PASSWORD|${MYSQL_USER_PASSWORD}|g" /persistent/config/config.php
        sed -i "s|CHANGE_THIS_TO_A_RANDOM_32_BYTE_BASE64_STRING|${ENCRYPTION_KEY}|g" /persistent/config/config.php
    fi

    # PHP 8.5: PDO::MYSQL_ATTR_INIT_COMMAND is deprecated — migrate existing configs
    if grep -q 'PDO::MYSQL_ATTR_INIT_COMMAND' /persistent/config/config.php 2>/dev/null && \
       ! grep -q 'Pdo.Mysql' /persistent/config/config.php 2>/dev/null; then
        echo "==> Updating config.php for PHP 8.5 PDO compatibility..."
        php <<'MIGRATE_PDO'
<?php
$f = '/persistent/config/config.php';
$c = file_get_contents($f);
$c = str_replace(
    'PDO::MYSQL_ATTR_INIT_COMMAND',
    "(class_exists('Pdo\\Mysql') ? Pdo\\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND)",
    $c
);
file_put_contents($f, $c);
MIGRATE_PDO
    fi
fi

# Harden config.php permissions: it holds the DB password and the encryption
# key. 640 (owner+group read, no "other") keeps the mysql/clamav service users
# from reading it; www-data (owner) still reads it for the app.
if [ -f /persistent/config/config.php ]; then
    chown www-data:www-data /persistent/config/config.php 2>/dev/null || true
    chmod 640 /persistent/config/config.php
fi

# 7b. Ensure .sensitive exists and tells the truth.
#
# Rewrite it whenever the database is about to be seeded: the admin password is
# regenerated in that case, so a surviving .sensitive from an earlier install
# would advertise a password the new database has never hashed, and the login
# would fail with "Invalid username or password". Keep a copy of the stale file
# rather than discarding it -- it may hold the only record of the old key.
if [ "$DB_EXISTS" = false ] && [ -f /persistent/config/.sensitive ]; then
    cp -p /persistent/config/.sensitive /persistent/config/.sensitive.previous
    echo "==> Database is new; previous credentials saved to .sensitive.previous"
fi

if [ "$DB_EXISTS" = false ] || [ ! -f /persistent/config/.sensitive ] || ! grep -q 'PORTAL_ADMIN_PASSWORD=.' /persistent/config/.sensitive 2>/dev/null; then
    cat > /persistent/config/.sensitive <<EOF
# Open TPRM & GRC - Sensitive Credentials
# Generated: $(date -u '+%Y-%m-%d %H:%M:%S UTC')
# WARNING: Keep this file secure. Do not share or commit to version control.

# Default Portal Login
PORTAL_ADMIN_USERNAME=admin
PORTAL_ADMIN_PASSWORD=${ADMIN_PASSWORD}

# MySQL Credentials
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
MYSQL_USER_PASSWORD=${MYSQL_USER_PASSWORD}
MYSQL_DATABASE=tprm
MYSQL_USER=tprm_user
MYSQL_HOST=127.0.0.1

# Encryption
ENCRYPTION_KEY=${ENCRYPTION_KEY}
EOF
    chmod 600 /persistent/config/.sensitive
    echo "==> Credentials written to persistent/config/.sensitive"
fi

# =========================================================================
# 8. Database provisioning: create tprm DB ONLY if it does not exist
#    This is the core safety check — we NEVER drop or overwrite an
#    existing tprm database.  docker compose up/down/restart is safe.
# =========================================================================
if [ "$DB_EXISTS" = false ]; then
    echo "==> Database 'tprm' not found — creating and seeding..."

    # Generate bcrypt hash for admin password
    ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASSWORD}', PASSWORD_BCRYPT, ['cost' => 12]);")

    # Create the database
    mysql <<EOSQL
CREATE DATABASE tprm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EOSQL

    # Load master schema (replace admin password placeholder)
    sed "s|CHANGEME_ADMIN_HASH|${ADMIN_HASH}|g" \
        /var/www/html/init-db/00-master_schema.sql \
        | mysql tprm
    echo "    - Master schema loaded."

    # Create app user, grant privileges, and seed default data
    sed -e "s/CHANGEME_USER_PASSWORD/${MYSQL_USER_PASSWORD}/g" \
        -e "s|CHANGEME_ADMIN_HASH|${ADMIN_HASH}|g" \
        /var/www/html/init-db/grant_remote_access.sql \
        | mysql tprm
    echo "    - User grants and seed data applied."

    # Apply all sql_updates/*.sql files in version-sorted order
    APPLIED_JSON="/persistent/sql_updates/applied_updates.json"
    HASH_DIR="/persistent/sql_updates/.applied_hashes"
    mkdir -p "$HASH_DIR"
    SQL_COUNT=0
    for sql_file in $(ls /var/www/html/sql_updates/*.sql 2>/dev/null | sort -V); do
        sql_name="$(basename "$sql_file")"
        echo "    - Applying SQL update: ${sql_name}"
        mysql --force tprm < "$sql_file" 2>&1 | while read -r line; do
            # Suppress expected idempotent errors (already exists / duplicate / can't drop)
            echo "$line" | grep -qE "^ERROR (1050|1060|1061|1068|1091|1553)" && continue
            [ -n "$line" ] && echo "      $line"
        done || true
        SQL_COUNT=$((SQL_COUNT + 1))
        # Store content hash for change detection on future upgrades
        md5sum "$sql_file" | awk '{print $1}' > "${HASH_DIR}/${sql_name}.md5"
        # Track applied file so the admin provisioner does not re-apply it
        NOW="$(date '+%Y-%m-%d %H:%M:%S')"
        if [ -s "$APPLIED_JSON" ]; then
            # Append key to existing JSON object (remove trailing } and add new entry)
            sed -i 's/}$//' "$APPLIED_JSON"
            printf ',"%s":{"applied_at":"%s","exit_code":0}}\n' "$sql_name" "$NOW" >> "$APPLIED_JSON"
        else
            printf '{"%s":{"applied_at":"%s","exit_code":0}}\n' "$sql_name" "$NOW" > "$APPLIED_JSON"
        fi
    done
    echo "    - Applied ${SQL_COUNT} SQL update file(s)."

    echo ""
    echo "============================================================"
    echo "  DATABASE CREATED - SAVE THESE CREDENTIALS"
    echo "============================================================"
    echo ""
    echo "  MySQL Root Password:          ${MYSQL_ROOT_PASSWORD}"
    echo "  MySQL App User (tprm_user):   ${MYSQL_USER_PASSWORD}"
    echo "  Encryption Key:               ${ENCRYPTION_KEY}"
    echo ""
    echo "  Database Host: 127.0.0.1"
    echo "  Database Name: tprm"
    echo "  Database User: tprm_user"
    echo ""
    echo "  Portal Admin User:            admin"
    echo "  Portal Admin Password:        ${ADMIN_PASSWORD}"
    echo ""
    echo "  Credentials saved to persistent/config/.sensitive"
    echo "  View again:  docker compose logs | grep 'Root Password'"
    echo "============================================================"
    echo ""
elif [ "$FIRST_START" = true ]; then
    # Edge case: password file was missing but database exists (manual restore)
    echo "==> Database 'tprm' already exists. Skipping schema/seed."
    echo ""
    echo "============================================================"
    echo "  NEW ROOT PASSWORD (existing database preserved)"
    echo "============================================================"
    echo ""
    echo "  MySQL Root Password:  ${MYSQL_ROOT_PASSWORD}"
    echo "  Database 'tprm' was NOT modified."
    echo "  Update config.php manually if needed."
    echo "============================================================"
    echo ""
else
    echo "==> Database 'tprm' exists. Nothing to do."
fi

# =========================================================================
# 8a. Auto-apply unapplied or changed SQL updates (upgrade-safe)
#     Runs on EVERY start when the database exists. Uses content-hash
#     tracking so updated migration files in a new Docker image are
#     automatically re-applied. SQL files must be idempotent (IF NOT
#     EXISTS, INSERT IGNORE, MODIFY COLUMN, etc.) so re-runs are safe.
# =========================================================================
if mysql -e "USE tprm" 2>/dev/null; then
    APPLIED_JSON="/persistent/sql_updates/applied_updates.json"
    HASH_DIR="/persistent/sql_updates/.applied_hashes"
    mkdir -p "$HASH_DIR"
    UPGRADE_COUNT=0
    for sql_file in $(ls /var/www/html/sql_updates/*.sql 2>/dev/null | sort -V); do
        sql_name="$(basename "$sql_file")"
        current_hash="$(md5sum "$sql_file" | awk '{print $1}')"
        hash_file="${HASH_DIR}/${sql_name}.md5"

        # Skip if content hash matches (file unchanged since last apply)
        if [ -f "$hash_file" ] && [ "$(cat "$hash_file")" = "$current_hash" ]; then
            continue
        fi

        if [ -f "$hash_file" ]; then
            echo "==> Re-applying updated SQL: ${sql_name}"
        else
            echo "==> Applying new SQL update: ${sql_name}"
        fi

        mysql --force tprm < "$sql_file" 2>&1 | while read -r line; do
            echo "$line" | grep -qE "^ERROR (1050|1060|1061|1068|1091|1553)" && continue
            [ -n "$line" ] && echo "      $line"
        done || true

        # Update content hash
        echo "$current_hash" > "$hash_file"
        UPGRADE_COUNT=$((UPGRADE_COUNT + 1))

        # Track in applied_updates.json for admin panel compatibility
        NOW="$(date '+%Y-%m-%d %H:%M:%S')"
        if [ -s "$APPLIED_JSON" ] && ! grep -q "\"${sql_name}\"" "$APPLIED_JSON" 2>/dev/null; then
            sed -i 's/}$//' "$APPLIED_JSON"
            printf ',"%s":{"applied_at":"%s","exit_code":0}}\n' "$sql_name" "$NOW" >> "$APPLIED_JSON"
        elif [ ! -s "$APPLIED_JSON" ]; then
            printf '{"%s":{"applied_at":"%s","exit_code":0}}\n' "$sql_name" "$NOW" > "$APPLIED_JSON"
        fi
    done
    if [ "$UPGRADE_COUNT" -gt 0 ]; then
        echo "==> Applied ${UPGRADE_COUNT} SQL update(s) during startup."
    fi
fi

# =========================================================================
# 8a2. Auto-seed GRC catalog frameworks (idempotent — skips existing)
# =========================================================================
if mysql -e "USE tprm" 2>/dev/null && mysql tprm -e "SELECT 1 FROM grc_frameworks LIMIT 1" 2>/dev/null; then
    echo "==> Seeding GRC catalog frameworks..."
    php /var/www/html/includes/seed_catalog_frameworks.php 2>&1 || true

    # Seed unified assessment question bank and framework mappings
    echo "==> Seeding unified question bank..."
    php /var/www/html/includes/seed_unified_questions.php 2>&1 || true
fi

# =========================================================================
# 8b. Update admin password in DB if it was backfilled from an older image
# =========================================================================
if [ "${UPDATE_ADMIN_PASSWORD}" = true ] && mysql -e "USE tprm" 2>/dev/null; then
    echo "==> Updating admin password in database..."
    ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASSWORD}', PASSWORD_BCRYPT, ['cost' => 12]);")
    mysql tprm -e "UPDATE users SET password_hash='${ADMIN_HASH}' WHERE username='admin';"
    echo "==> Admin password updated. New password is in persistent/config/.sensitive"
fi

# =========================================================================
# 8c. Auto-enable WAF security on first deployment
#     Generates whitelist + scanner detection configs and sets WAF to
#     enforcing mode. Writes config files directly (no Apache reload)
#     because Apache hasn't started yet. Only runs once per persistent
#     volume (flag file prevents re-runs on container restart).
# =========================================================================
WAF_INIT_FLAG="/persistent/modsecurity/.waf_initialized"
if [ ! -f "$WAF_INIT_FLAG" ] && mysql -e "USE tprm" 2>/dev/null; then
    echo "==> Initializing WAF security configuration..."
    php -r "
        require '/var/www/html/includes/init.php';
        \$db = Database::getInstance();
        \$lockdown = new LockdownService(\$db);

        // Generate whitelist rules (writes conf files, no Apache reload)
        \$count = \$lockdown->generateWhitelistRules();
        echo \"    - Generated \$count whitelist rules\n\";
    " 2>/dev/null

    # Build scanner detection + modsecurity configs via PHP and write
    # directly. We can't call setScannerBlocking()/setMode() because
    # they reload Apache, which hasn't started yet.
    php -r "
        require '/var/www/html/includes/init.php';
        \$db = Database::getInstance();
        \$lockdown = new LockdownService(\$db);

        // Use reflection to call private buildScannerDetectionConf()
        \$method = new ReflectionMethod(\$lockdown, 'buildScannerDetectionConf');
        \$method->setAccessible(true);
        echo \$method->invoke(\$lockdown);
    " 2>/dev/null | sudo tee /etc/modsecurity/scanner-detection.conf > /dev/null
    cp /etc/modsecurity/scanner-detection.conf /persistent/modsecurity/scanner-detection.conf
    echo "    - Scanner blocking: enabled"

    # Build enforcing modsecurity.conf
    php -r "
        require '/var/www/html/includes/init.php';
        \$db = Database::getInstance();
        \$lockdown = new LockdownService(\$db);

        \$method = new ReflectionMethod(\$lockdown, 'buildModSecConf');
        \$method->setAccessible(true);
        echo \$method->invoke(\$lockdown, 'On');
    " 2>/dev/null | sudo tee /etc/modsecurity/modsecurity.conf > /dev/null
    cp /etc/modsecurity/modsecurity.conf /persistent/modsecurity/modsecurity.conf
    echo "    - WAF enforcing mode: enabled"

    # Set DB config values
    mysql tprm -e "
        INSERT INTO app_config (config_key, config_value, description)
        VALUES ('waf_mode', 'enforcing', 'WAF lockdown mode: disabled, learning, enforcing')
        ON DUPLICATE KEY UPDATE config_value = 'enforcing';
        INSERT INTO app_config (config_key, config_value, description)
        VALUES ('waf_scanner_blocking', '1', 'Enable/disable automated scanner/attack detection rules')
        ON DUPLICATE KEY UPDATE config_value = '1';
    "

    touch "$WAF_INIT_FLAG"
    echo "==> WAF security initialized."
fi

# =========================================================================
# 8c2. Always regenerate scanner-detection.conf from current code + DB.
#      This is what ships new ModSecurity rules (e.g. login-endpoint rate
#      limiting) to existing customer deployments without requiring an admin
#      to click anything in the WAF UI. The conf is deterministic from the
#      DB config + LockdownService source, so regenerating every start is
#      safe and idempotent.
# =========================================================================
if mysql -e "USE tprm" 2>/dev/null; then
    # `-d display_errors=0` keeps any PHP notice/deprecation off STDOUT so a
    # warning can't ever get prepended to the conf file (which makes Apache
    # refuse to start). setAccessible(true) is a no-op in PHP 8.1+ and
    # deprecated in 8.5, so we don't call it — private-method reflection
    # works without it on this stack.
    REGENERATED_CONF=$(php -d display_errors=0 -r "
        require '/var/www/html/includes/init.php';
        \$db = Database::getInstance();
        \$lockdown = new LockdownService(\$db);

        \$method = new ReflectionMethod(\$lockdown, 'buildScannerDetectionConf');
        echo \$method->invoke(\$lockdown);
    " 2>/dev/null)
    # Only overwrite if PHP produced a non-trivial conf (guards against DB
    # outage, autoloader failure, etc., which would otherwise blank the file).
    if [ "${#REGENERATED_CONF}" -gt 200 ]; then
        echo "$REGENERATED_CONF" | sudo tee /etc/modsecurity/scanner-detection.conf > /dev/null
        cp /etc/modsecurity/scanner-detection.conf /persistent/modsecurity/scanner-detection.conf
        echo "==> Regenerated scanner-detection.conf from current LockdownService code."
    fi
fi

# =========================================================================
# 8c3. Ensure Apache server-info hardening is applied at runtime.
#      ServerTokens/ServerSignature/TraceEnable are baked into the image,
#      but customers running an older image who only restart the container
#      (rather than rebuild) wouldn't have them. Writing the conf here makes
#      the next restart suppress the version banner on default error pages
#      regardless of when the image was built. Idempotent — overwrites with
#      identical content on every boot.
# =========================================================================
HARDENING_CONF=/etc/apache2/conf-available/security-hardening.conf
cat > "$HARDENING_CONF" <<'APACHE_HARDENING'
# Minimize server info disclosure (OWASP A05:2021).
# ServerTokens Prod  -> "Server: Apache" only, no version or OS.
# ServerSignature Off -> no version/host footer on default error pages.
# TraceEnable Off    -> reject TRACE so XST attacks have nothing to land on.
ServerTokens Prod
ServerSignature Off
TraceEnable Off
APACHE_HARDENING
a2enconf security-hardening >/dev/null 2>&1 || true
echo "==> Apache server-info hardening conf ensured (ServerTokens Prod, ServerSignature Off)."

# Fix ownership on all persistent files created by root above
chown -R www-data:www-data /persistent/modsecurity
chown -R www-data:www-data /persistent/sql_updates
chown -R www-data:www-data /persistent/config

# =========================================================================
# 9. Remove setup.php (Docker entrypoint handles all setup)
# =========================================================================
if [ -f /var/www/html/setup.php ]; then
    rm -f /var/www/html/setup.php
    echo "==> Removed setup.php (not needed in Docker deployment)."
fi

# =========================================================================
# 9b. Sync crontab from database scheduler settings
#     Regenerates /etc/cron.d/open-tprm so timezone, schedules, and
#     enabled/disabled state match what the admin configured in the
#     Scheduler UI. Runs on every start so container rebuilds don't
#     revert admin changes back to image defaults.
# =========================================================================
if mysql -e "USE tprm" 2>/dev/null; then
    echo "==> Syncing crontab with database scheduler settings..."
    php /var/www/html/cron/sync-crontab.php 2>&1 || echo "    - Warning: crontab sync failed, using image defaults"
fi

# =========================================================================
# 10. Start cron daemon (background)
# =========================================================================
echo "==> Starting cron daemon..."
cron

# =========================================================================
# 11. Start ClamAV antivirus daemon
# =========================================================================
echo "==> Starting ClamAV antivirus..."
mkdir -p /var/run/clamav /var/log/clamav
chown clamav:clamav /var/run/clamav /var/log/clamav

# Start freshclam in daemon mode (auto-updates virus definitions)
freshclam -d --quiet 2>/dev/null &

# Start clamd
clamd 2>/dev/null &

# Wait for the clamd socket to become available (up to 30s)
CLAMD_READY=false
for i in $(seq 1 30); do
    if [ -S /var/run/clamav/clamd.sock ]; then
        CLAMD_READY=true
        break
    fi
    sleep 1
done

if [ "$CLAMD_READY" = true ]; then
    echo "==> ClamAV is running (socket ready)."
    # Nudge clamd to load the freshest signatures shortly after startup. freshclam's
    # initial update can finish before clamd's socket exists, so freshclam's "notify
    # clamd" ping fails; clamd would otherwise only pick the new DB up on its ~10min
    # SelfCheck. This backgrounded one-shot RELOAD makes signatures prompt after a recreate.
    (
        sleep 120
        if [ -S /var/run/clamav/clamd.sock ]; then
            printf 'nRELOAD\n' | socat - UNIX-CONNECT:/var/run/clamav/clamd.sock >/dev/null 2>&1 || true
        fi
    ) &
else
    echo "WARNING: ClamAV socket not ready after 30s — virus scanning fails CLOSED, so uploads will be blocked until clamd is available."
fi

# =========================================================================
# 12. Graceful shutdown handler
# =========================================================================
APACHE_PID=""
shutdown_handler() {
    echo "==> Shutting down ..."
    if [ -n "$APACHE_PID" ]; then
        kill -TERM "$APACHE_PID" 2>/dev/null || true
        wait "$APACHE_PID" 2>/dev/null || true
    fi
    # Stop ClamAV daemons
    pkill clamd 2>/dev/null || true
    pkill freshclam 2>/dev/null || true
    mysqladmin shutdown 2>/dev/null || true
    exit 0
}
trap shutdown_handler SIGTERM SIGINT

# =========================================================================
# 13. Start Apache in the foreground
# =========================================================================
echo "==> Starting Apache on port 8080..."
# Pre-create audit log files so ModSecurity doesn't create them as root
touch /persistent/modsecurity/audit_log/modsec_audit.json /persistent/modsecurity/audit_log/modsec_debug.log
chown www-data:www-data /persistent/modsecurity/audit_log/modsec_audit.json /persistent/modsecurity/audit_log/modsec_debug.log
chmod 640 /persistent/modsecurity/audit_log/modsec_audit.json /persistent/modsecurity/audit_log/modsec_debug.log

# Grant www-data read access to Apache error log for WAF intercept counting.
# Only this specific directory — NOT the adm group (which exposes syslog, auth.log, etc.)
# The directory needs o+x so www-data can traverse it to reach the error log.
chmod o+x /var/log/apache2
touch /var/log/apache2/error.log
chown root:www-data /var/log/apache2/error.log
chmod 640 /var/log/apache2/error.log

export APACHE_CONFDIR="${APACHE_CONFDIR:-/etc/apache2}"
source /etc/apache2/envvars
apache2 -D FOREGROUND &
APACHE_PID=$!
wait "$APACHE_PID"
