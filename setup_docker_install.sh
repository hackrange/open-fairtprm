#!/usr/bin/env bash
# =============================================================================
# Fair TPRM & GRC Platform - Automated Docker Setup for Ubuntu 22.04 / 24.04 / 26.04
# =============================================================================
#
# This script:
#   1. Detects your Ubuntu version and installs Git + Docker Engine + Compose v2
#   2. Asks which version to install, whether to expose port 8080 to the network,
#      and whether to put an nginx reverse proxy in front of it
#   3. Clones the repository into /data/docker/fairtprm
#   4. Pulls the published application image and starts the container
#
# Usage (pick one):
#   curl -fsSL https://git.hackrange.com/trice/open-fairtprm/-/raw/2.6.2/setup_docker_install.sh -o /tmp/setup.sh && sudo bash /tmp/setup.sh
#   -- or --
#   chmod +x setup_docker_install.sh && sudo ./setup_docker_install.sh
#
# Run `setup_docker_install.sh --help` for the full list of options.
#
# With no options the script prompts for its choices when it has a terminal, and
# falls back to the safe defaults below when it does not (e.g. `curl | bash`):
#
#   version v2.6.2,  port 8080 published on 127.0.0.1 only,  no reverse proxy
#
# =============================================================================
# The { } block forces bash to read the ENTIRE script into memory before
# executing anything. Without this, "curl | bash" reads line-by-line and
# subcommands (apt-get, docker, etc.) can swallow parts of the script.
# =============================================================================
{

set -euo pipefail

# ---- Defaults (also the answers used when running non-interactively) ----
DEFAULT_VERSION="v2.6.2"
DEFAULT_PORT="8080"

INSTALL_DIR="${FAIRTPRM_DIR:-/data/docker/fairtprm}"
REPO_URL="https://git.hackrange.com/trice/open-fairtprm.git"
REGISTRY_HOST="dockerregistry.fairtprm.com"
REGISTRY_REPO="fairtprm"
LOG_FILE="/tmp/fairtprm-setup-$(date +%Y%m%d-%H%M%S).log"

# Named IMAGE_TAG, not VERSION: `. /etc/os-release` (used below to detect the
# Ubuntu release) exports VERSION itself and would silently overwrite it.
IMAGE_TAG=""          # normalized image tag, e.g. v2.6.2
BRANCH=""             # git branch matching IMAGE_TAG, e.g. 2.6.2
EXPOSE=""             # host | network
HOST_PORT=""
USE_NGINX=""          # true | false
DOMAIN=""
USE_TLS=""            # true | false
TLS_EMAIL=""
ASSUME_YES=false
OPTS_GIVEN=false      # did the user pass any configuration flag?

COMPOSE_CMD=""
APP_IMAGE=""
PUBLISH_IP="127.0.0.1"

# Newest release codename Docker publishes packages for. Used as a fallback when
# running on an Ubuntu release that Docker has not built packages for yet.
FALLBACK_CODENAME="noble"

# ---- Colors for output ----
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ---- Helper functions ----
log()    { echo -e "${GREEN}[OK]${NC}    $1" | tee -a "$LOG_FILE"; }
info()   { echo -e "${BLUE}[INFO]${NC}  $1" | tee -a "$LOG_FILE"; }
warn()   { echo -e "${YELLOW}[WARN]${NC}  $1" | tee -a "$LOG_FILE"; }
fail()   { echo -e "${RED}[FAIL]${NC}  $1" | tee -a "$LOG_FILE"; exit 1; }

header() {
    echo "" | tee -a "$LOG_FILE"
    echo -e "${BLUE}============================================================${NC}" | tee -a "$LOG_FILE"
    echo -e "${BLUE}  $1${NC}" | tee -a "$LOG_FILE"
    echo -e "${BLUE}============================================================${NC}" | tee -a "$LOG_FILE"
    echo "" | tee -a "$LOG_FILE"
}

usage() {
    cat <<'EOF'
Fair TPRM & GRC Platform - Docker setup

Usage: sudo ./setup_docker_install.sh [OPTIONS]

Options:
  -v, --version TAG    Version to install. "2.6.2" and "v2.6.2" both mean v2.6.2.
                       Default: v2.6.2
  -e, --expose MODE    Who can reach the app:
                         host     publish port on 127.0.0.1 only  (default)
                         network  publish on 0.0.0.0, reachable from the LAN
  -p, --port PORT      Host port to publish. Default: 8080
      --nginx          Install an nginx reverse proxy on port 80 in front of the app
      --no-nginx       Do not install a reverse proxy (default)
  -d, --domain FQDN    server_name for the nginx site (default: the server's IP)
      --tls            Request a Let's Encrypt certificate (needs --domain + --email)
      --email ADDRESS  Contact address for Let's Encrypt
  -y, --yes            Never prompt; use defaults for anything not given
  -h, --help           Show this help and exit

Examples:
  sudo ./setup_docker_install.sh
      Prompt for version, exposure and reverse proxy (defaults in brackets).

  sudo ./setup_docker_install.sh --yes
      No prompts: v2.6.2, port 8080 on 127.0.0.1, no reverse proxy.

  sudo ./setup_docker_install.sh --version 2.6.1 --expose network
      Install v2.6.1 and publish port 8080 to the whole network.

  sudo ./setup_docker_install.sh --nginx --domain tprm.example.com --tls --email me@example.com
      Keep the app on 127.0.0.1 and serve it over HTTPS via nginx.

Environment:
  FAIRTPRM_DIR   Install directory (default: /data/docker/fairtprm)
EOF
}

# ---- Argument parsing ----
need_arg() {  # need_arg <option> <value>
    [ -n "${2:-}" ] || { echo "Option $1 requires a value." >&2; echo "" >&2; usage >&2; exit 2; }
}

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
            -v|--version) need_arg "$1" "${2:-}"; IMAGE_TAG="$2";   OPTS_GIVEN=true; shift 2 ;;
            -e|--expose)  need_arg "$1" "${2:-}"; EXPOSE="$2";    OPTS_GIVEN=true; shift 2 ;;
            -p|--port)    need_arg "$1" "${2:-}"; HOST_PORT="$2"; OPTS_GIVEN=true; shift 2 ;;
            -d|--domain)  need_arg "$1" "${2:-}"; DOMAIN="$2";    OPTS_GIVEN=true; shift 2 ;;
            --email)      need_arg "$1" "${2:-}"; TLS_EMAIL="$2"; OPTS_GIVEN=true; shift 2 ;;
            --nginx)      USE_NGINX=true;     OPTS_GIVEN=true; shift ;;
            --no-nginx)   USE_NGINX=false;    OPTS_GIVEN=true; shift ;;
            --tls)        USE_TLS=true;       OPTS_GIVEN=true; shift ;;
            -y|--yes)     ASSUME_YES=true;    shift ;;
            -h|--help)    usage; exit 0 ;;
            *)            echo "Unknown option: $1" >&2; echo "" >&2; usage >&2; exit 2 ;;
        esac
    done
}

# ---- Version helpers ----

# "2.6.2" and "v2.6.2" both normalize to "v2.6.2"; "latest" is left alone.
normalize_version() {
    local v="$1"
    v="${v#"${v%%[![:space:]]*}"}"   # trim leading space
    v="${v%"${v##*[![:space:]]}"}"   # trim trailing space
    [ -z "$v" ] && { echo ""; return; }
    if [ "$v" = "latest" ]; then echo "latest"; return; fi
    echo "v${v#v}"
}

# Tags published in the registry, newest first. Empty if the registry is unreachable.
registry_tags() {
    curl -fsSL --max-time 15 "https://${REGISTRY_HOST}/v2/${REGISTRY_REPO}/tags/list" 2>/dev/null \
        | tr ',' '\n' | grep -oE '"v?[0-9][^"]*"|"latest"' | tr -d '"' | sort -Vr
}

# The git branch that matches an image tag: v2.6.2 -> 2.6.2, falling back to master.
branch_for_version() {
    local candidate="${1#v}"
    if git ls-remote --exit-code --heads "$REPO_URL" "$candidate" >/dev/null 2>&1; then
        echo "$candidate"
    else
        echo "master"
    fi
}

# ---- Interactive configuration ----

# Prompts read from /dev/tty, never stdin: `curl ... | bash` feeds the script
# itself on stdin, and a `read` there would eat the rest of the script.
have_tty() { [ "$ASSUME_YES" = false ] && [ -r /dev/tty ]; }

ask() {  # ask <prompt> <default> -> answer on stdout
    local prompt="$1" default="$2" reply=""
    read -r -p "$prompt [$default]: " reply < /dev/tty || reply=""
    echo "${reply:-$default}"
}

ask_yes_no() {  # ask_yes_no <prompt> <default:y|n> -> true/false
    local prompt="$1" default="$2" reply=""
    local hint="y/N"; [ "$default" = "y" ] && hint="Y/n"
    read -r -p "$prompt [$hint]: " reply < /dev/tty || reply=""
    reply="${reply:-$default}"
    case "${reply,,}" in y|yes) echo true ;; *) echo false ;; esac
}

choose_version() {
    local tags default_tag="$DEFAULT_VERSION"
    tags=$(registry_tags) || true

    if [ -z "$tags" ]; then
        warn "Could not list versions from ${REGISTRY_HOST}; offering the default only."
        IMAGE_TAG=$(ask "  Version to install" "$default_tag")
        return
    fi

    echo "  Versions available in the registry:"
    echo ""
    local i=1
    while read -r t; do
        [ -z "$t" ] && continue
        if [ "$t" = "$default_tag" ]; then
            printf "    %2d) %-12s (default)\n" "$i" "$t"
        else
            printf "    %2d) %s\n" "$i" "$t"
        fi
        i=$((i + 1))
    done <<< "$tags"
    echo ""
    echo "  Enter a number, or a version such as 2.6.2 or v2.6.2."
    echo ""

    local reply
    reply=$(ask "  Version to install" "$default_tag")

    if [[ "$reply" =~ ^[0-9]+$ ]] && [ "$reply" -ge 1 ] && [ "$reply" -lt "$i" ]; then
        IMAGE_TAG=$(echo "$tags" | sed -n "${reply}p")
    else
        IMAGE_TAG="$reply"
    fi
}

configure() {
    header "Configuration"

    if ! have_tty; then
        [ "$ASSUME_YES" = true ] || [ "$OPTS_GIVEN" = true ] \
            || info "No terminal available; using defaults (pass --help to see the options)."
    elif [ "$OPTS_GIVEN" = false ]; then
        choose_version

        echo ""
        echo "  Who should be able to reach the application?"
        echo "    1) This host only  - publish on 127.0.0.1 (default, most secure)"
        echo "    2) The network     - publish on 0.0.0.0, any host on the LAN can connect"
        echo ""
        local pick
        pick=$(ask "  Choice" "1")
        case "$pick" in
            2|network) EXPOSE="network" ;;
            *)         EXPOSE="host" ;;
        esac

        echo ""
        USE_NGINX=$(ask_yes_no "  Install an nginx reverse proxy on port 80?" "n")
        if [ "$USE_NGINX" = true ]; then
            local ip
            ip=$(primary_ip)
            DOMAIN=$(ask "  Domain name for the site" "${ip:-_}")
            if [[ "$DOMAIN" =~ ^[A-Za-z] ]]; then
                USE_TLS=$(ask_yes_no "  Request a Let's Encrypt TLS certificate for $DOMAIN?" "n")
                if [ "$USE_TLS" = true ]; then
                    TLS_EMAIL=$(ask "  Contact email for Let's Encrypt" "")
                fi
            fi
        fi
        echo ""
    fi

    # ---- Fill in anything still unset, then validate ----
    IMAGE_TAG=$(normalize_version "${IMAGE_TAG:-$DEFAULT_VERSION}")
    HOST_PORT="${HOST_PORT:-$DEFAULT_PORT}"
    EXPOSE="${EXPOSE:-host}"
    USE_NGINX="${USE_NGINX:-false}"
    USE_TLS="${USE_TLS:-false}"

    case "$EXPOSE" in
        host)    PUBLISH_IP="127.0.0.1" ;;
        network) PUBLISH_IP="0.0.0.0" ;;
        *)       fail "--expose must be 'host' or 'network' (got: $EXPOSE)" ;;
    esac

    if ! [[ "$HOST_PORT" =~ ^[0-9]+$ ]] || [ "$HOST_PORT" -lt 1 ] || [ "$HOST_PORT" -gt 65535 ]; then
        fail "--port must be a number between 1 and 65535 (got: $HOST_PORT)"
    fi

    # A reverse proxy only makes sense in front of a host-only app port.
    if [ "$USE_NGINX" = true ] && [ "$EXPOSE" = "network" ]; then
        warn "--nginx implies the app port stays on 127.0.0.1; nginx is what listens on the network."
        PUBLISH_IP="127.0.0.1"
        EXPOSE="host"
    fi

    if [ "$USE_TLS" = true ]; then
        [ -n "$DOMAIN" ] && [[ "$DOMAIN" =~ ^[A-Za-z] ]] || fail "--tls needs --domain with a real hostname"
        [ -n "$TLS_EMAIL" ] || fail "--tls needs --email"
        USE_NGINX=true
    fi

    # Validate the version against the registry when we can reach it.
    local tags
    tags=$(registry_tags) || true
    if [ -n "$tags" ] && ! echo "$tags" | grep -qx "$IMAGE_TAG"; then
        echo "" >&2
        warn "Version '$IMAGE_TAG' is not published in ${REGISTRY_HOST}."
        echo "  Available: $(echo "$tags" | tr '\n' ' ')" | tee -a "$LOG_FILE"
        fail "Pick one of the versions listed above."
    fi

    BRANCH=$(branch_for_version "$IMAGE_TAG")
    APP_IMAGE="${REGISTRY_HOST}/${REGISTRY_REPO}:${IMAGE_TAG}"

    log "Version:       $IMAGE_TAG  (git branch: $BRANCH)"
    log "Published on:  ${PUBLISH_IP}:${HOST_PORT}"
    if [ "$USE_NGINX" = true ]; then
        log "Reverse proxy: nginx on port 80${DOMAIN:+ for $DOMAIN}$([ "$USE_TLS" = true ] && echo " (+ TLS)")"
    else
        log "Reverse proxy: none"
    fi
}

primary_ip() { hostname -I 2>/dev/null | awk '{print $1}'; }

# ---- Detect which compose command to use ----
detect_compose() {
    if docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD="docker compose"
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD="docker-compose"
    fi
}

# ---- Print how to reach the app ----
show_access_urls() {
    local ip
    ip=$(primary_ip)

    echo -e "  ${BLUE}Access the application at:${NC}"
    echo ""

    if [ "$USE_NGINX" = true ]; then
        local scheme="http" host="${DOMAIN:-${ip:-localhost}}"
        [ "$USE_TLS" = true ] && scheme="https"
        echo -e "    ${YELLOW}${scheme}://${host}${NC}"
        echo ""
        echo "  nginx listens on port 80$([ "$USE_TLS" = true ] && echo " and 443") and forwards to the"
        echo "  application on 127.0.0.1:${HOST_PORT}, which is not reachable directly."
    elif [ "$PUBLISH_IP" = "0.0.0.0" ]; then
        echo -e "    ${YELLOW}http://${ip:-<this-server>}:${HOST_PORT}${NC}"
        echo -e "    ${YELLOW}http://localhost:${HOST_PORT}${NC}"
        echo ""
        echo -e "  ${YELLOW}The app is reachable from every host on your network over plain HTTP.${NC}"
        echo "  Put it behind TLS (re-run with --nginx --domain ... --tls) before using it"
        echo "  for anything real. Note that a published Docker port bypasses UFW rules."
    else
        echo -e "    ${YELLOW}http://localhost:${HOST_PORT}${NC}"
        echo ""
        echo -e "  ${BLUE}Note:${NC} the port is published on ${YELLOW}127.0.0.1 only${NC}, so the app is not"
        echo "  reachable from other machines. To reach it from your laptop, tunnel over SSH:"
        echo ""
        echo "    ssh -L ${HOST_PORT}:localhost:${HOST_PORT} ${SUDO_USER:-$(whoami)}@${ip:-this-server}"
        echo ""
        echo "  then browse to http://localhost:${HOST_PORT} on your laptop. To publish it on"
        echo "  the network instead, re-run this script with --expose network."
    fi
}

# ---- Show credentials from running container ----
show_credentials() {
    local CREDS=""
    CREDS=$(cd "$INSTALL_DIR" && $COMPOSE_CMD exec -T open-tprm cat /persistent/config/.sensitive 2>/dev/null) || true

    if [ -n "$CREDS" ]; then
        local ADMIN_USER ADMIN_PASS DB_ROOT_PASS DB_USER_PASS ENC_KEY
        ADMIN_USER=$(echo "$CREDS" | grep "^PORTAL_ADMIN_USERNAME=" | cut -d'=' -f2-) || true
        ADMIN_PASS=$(echo "$CREDS" | grep "^PORTAL_ADMIN_PASSWORD=" | cut -d'=' -f2-) || true
        DB_ROOT_PASS=$(echo "$CREDS" | grep "^MYSQL_ROOT_PASSWORD=" | cut -d'=' -f2-) || true
        DB_USER_PASS=$(echo "$CREDS" | grep "^MYSQL_USER_PASSWORD=" | cut -d'=' -f2-) || true
        ENC_KEY=$(echo "$CREDS" | grep "^ENCRYPTION_KEY=" | cut -d'=' -f2-) || true

        echo ""
        echo -e "  ${GREEN}+------------------------------------------------------------+${NC}"
        echo -e "  ${GREEN}|              ADMIN LOGIN CREDENTIALS                       |${NC}"
        echo -e "  ${GREEN}+------------------------------------------------------------+${NC}"
        echo -e "  ${GREEN}|${NC}                                                            ${GREEN}|${NC}"
        printf "  ${GREEN}|${NC}   Username:  ${YELLOW}%-46s${NC}${GREEN}|${NC}\n" "${ADMIN_USER:-admin}"
        printf "  ${GREEN}|${NC}   Password:  ${YELLOW}%-46s${NC}${GREEN}|${NC}\n" "${ADMIN_PASS:-<not yet generated>}"
        echo -e "  ${GREEN}|${NC}                                                            ${GREEN}|${NC}"
        echo -e "  ${GREEN}+------------------------------------------------------------+${NC}"
        echo ""
        # Same credentials without the box, so a copy/paste cannot pick up the
        # padding spaces inside the border.
        echo "  Copy/paste:  ${ADMIN_USER:-admin} / ${ADMIN_PASS:-<not yet generated>}"
        echo ""
        echo -e "  ${BLUE}This is the password generated on first start.${NC} If someone has since"
        echo "  changed it in the web UI, this file still shows the original one."
        echo ""
        echo -e "  ${BLUE}Database Credentials (for advanced users):${NC}"
        echo "    MySQL Root Password:     ${DB_ROOT_PASS:-<not available>}"
        echo "    MySQL App User:          tprm_user"
        echo "    MySQL App Password:      ${DB_USER_PASS:-<not available>}"
        echo "    Encryption Key:          ${ENC_KEY:-<not available>}"
        echo ""
        echo -e "  ${BLUE}To view these credentials later, run:${NC}"
        echo "    cd $INSTALL_DIR && $COMPOSE_CMD exec open-tprm cat /persistent/config/.sensitive"
    else
        echo ""
        echo -e "  ${YELLOW}Credentials are not available yet. The container may still be initializing.${NC}"
        echo -e "  ${YELLOW}Wait a moment, then run:${NC}"
        echo "    cd $INSTALL_DIR && $COMPOSE_CMD exec open-tprm cat /persistent/config/.sensitive"
    fi
}

# ---- Confirm the credentials we are about to print actually work ----
#
# .sensitive lives in the persistent_data volume; the bcrypt hash it corresponds
# to lives in the users table in the mariadb_data volume. The two drift apart if
# the admin password is changed in the web UI, or if one volume is recreated
# without the other. Printing a password that no longer authenticates is worse
# than printing nothing, so check it rather than assume.
verify_credentials() {
    local creds pass user hash
    creds=$(cd "$INSTALL_DIR" && $COMPOSE_CMD exec -T open-tprm cat /persistent/config/.sensitive 2>/dev/null) || return 0
    pass=$(echo "$creds" | grep '^PORTAL_ADMIN_PASSWORD=' | cut -d'=' -f2-) || true
    user=$(echo "$creds" | grep '^PORTAL_ADMIN_USERNAME=' | cut -d'=' -f2-) || true
    user="${user:-admin}"
    [ -n "$pass" ] || return 0

    hash=$(cd "$INSTALL_DIR" && $COMPOSE_CMD exec -T open-tprm \
        mysql tprm -N -B -e "SELECT password_hash FROM users WHERE username = '${user}' LIMIT 1" 2>/dev/null | head -1) || true
    [ -n "$hash" ] || return 0

    if (cd "$INSTALL_DIR" && $COMPOSE_CMD exec -T -e PW="$pass" -e HS="$hash" open-tprm \
            php -r 'exit(password_verify(getenv("PW"), getenv("HS")) ? 0 : 1);' >/dev/null 2>&1); then
        log "Admin password verified against the database"
        return 0
    fi

    echo ""
    warn "The password stored in .sensitive does NOT match the '${user}' account in the database."
    warn "This happens when the password was changed in the web UI, or when the"
    warn "persistent volume and the database volume came from different installs."
    echo ""
    echo -e "  ${BLUE}To set a known password, run:${NC}"
    echo ""
    print_reset_recipe "$user"
    echo ""
}

# The reset command, printed verbatim. A quoted heredoc keeps the nested PHP and
# SQL quoting readable instead of drowning it in backslashes.
print_reset_recipe() {
    local user="$1"
    cat <<EOF
    cd $INSTALL_DIR
    $COMPOSE_CMD exec -T -e NEWPW='YourNewPassword' open-tprm sh -s <<'RESET'
      H=\$(php -r 'echo password_hash(getenv("NEWPW"), PASSWORD_BCRYPT, ["cost" => 12]);')
      mysql tprm -e "UPDATE users SET password_hash = '\$H' WHERE username = '$user';"
RESET
EOF
}

# ---- Check if the requested setup is already running ----
check_existing() {
    if [ ! -d "$INSTALL_DIR" ] || [ ! -f "$INSTALL_DIR/docker-compose.yml" ]; then
        return 0
    fi
    if ! command -v docker >/dev/null 2>&1; then
        return 0
    fi

    detect_compose
    [ -z "$COMPOSE_CMD" ] && return 0

    local CONTAINER_STATE=""
    CONTAINER_STATE=$(cd "$INSTALL_DIR" && $COMPOSE_CMD ps -q 2>/dev/null | head -1) || true
    [ -z "$CONTAINER_STATE" ] && return 0

    local IS_RUNNING="" RUNNING_IMAGE=""
    IS_RUNNING=$(docker inspect --format='{{.State.Running}}' "$CONTAINER_STATE" 2>/dev/null) || true
    RUNNING_IMAGE=$(docker inspect --format='{{.Config.Image}}' "$CONTAINER_STATE" 2>/dev/null) || true

    if [ "$IS_RUNNING" != "true" ]; then
        info "Previous installation found but not running. Reinstalling..."
        (cd "$INSTALL_DIR" && $COMPOSE_CMD down 2>/dev/null) || true
        return 0
    fi

    # Running, but the user asked for something different -> reconfigure.
    if [ "$RUNNING_IMAGE" != "$APP_IMAGE" ] || [ "$OPTS_GIVEN" = true ] || have_tty; then
        info "Reconfiguring the existing installation (running: ${RUNNING_IMAGE:-unknown})..."
        (cd "$INSTALL_DIR" && $COMPOSE_CMD down 2>/dev/null) || true
        return 0
    fi

    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN}  Fair TPRM & GRC Platform - Already Installed & Running!${NC}"
    echo -e "${GREEN}============================================================${NC}"
    echo ""
    show_access_urls
    show_credentials
    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo ""
    exit 0
}

# ---- Pre-flight checks ----
preflight() {
    header "Pre-flight Checks"

    if [ "$(id -u)" -ne 0 ]; then
        fail "This script must be run as root. Use: sudo $0"
    fi
    log "Running as root"

    if [ ! -f /etc/os-release ]; then
        fail "Cannot detect OS. /etc/os-release not found."
    fi
    . /etc/os-release

    if [ "$ID" != "ubuntu" ]; then
        fail "This script only supports Ubuntu. Detected: $ID"
    fi

    case "$VERSION_ID" in
        22.04|24.04|26.04)
            log "Detected Ubuntu $VERSION_ID ($VERSION_CODENAME)"
            ;;
        *)
            # Newer or non-LTS releases usually work fine. Warn instead of
            # refusing to run -- a hard failure here blocks every future
            # Ubuntu release until someone edits this script.
            warn "Ubuntu $VERSION_ID is not a tested release (tested: 22.04, 24.04, 26.04). Continuing anyway."
            ;;
    esac

    AVAIL_KB=$(df --output=avail / | tail -1 | tr -d ' ')
    AVAIL_GB=$((AVAIL_KB / 1024 / 1024))
    if [ "$AVAIL_GB" -lt 5 ]; then
        fail "Insufficient disk space. Need at least 5 GB, have ${AVAIL_GB} GB on /"
    fi
    log "Disk space: ${AVAIL_GB} GB available"

    TOTAL_RAM_KB=$(grep MemTotal /proc/meminfo | awk '{print $2}')
    TOTAL_RAM_GB=$((TOTAL_RAM_KB / 1024 / 1024))
    if [ "$TOTAL_RAM_KB" -lt 1800000 ]; then
        warn "Low memory detected (${TOTAL_RAM_GB} GB). Recommended: 2 GB minimum."
    else
        log "RAM: ${TOTAL_RAM_GB} GB available"
    fi
}

# ---- Step 1: Install Git (and the tools the rest of the script needs) ----
install_git() {
    header "Step 1/5 - Installing Git"

    # curl, gnupg and ca-certificates are needed to query the registry and to add
    # Docker's apt repository, so make sure they are present even when Git is.
    local NEED=()
    command -v git  >/dev/null 2>&1 || NEED+=(git)
    command -v curl >/dev/null 2>&1 || NEED+=(curl)
    command -v gpg  >/dev/null 2>&1 || NEED+=(gnupg)

    if [ "${#NEED[@]}" -eq 0 ]; then
        log "Git is already installed: $(git --version)"
        return 0
    fi

    info "Installing: ${NEED[*]}"
    apt-get update -qq </dev/null
    apt-get install -y -qq ca-certificates "${NEED[@]}" </dev/null >/dev/null 2>&1
    log "Git installed: $(git --version)"
}

# ---- Pick the Docker apt repo codename for this Ubuntu release ----
docker_repo_codename() {
    local CODENAME="$1"

    if curl -fsSL -o /dev/null "https://download.docker.com/linux/ubuntu/dists/${CODENAME}/Release" 2>/dev/null; then
        echo "$CODENAME"
    else
        warn "Docker has no packages for '${CODENAME}' yet; using '${FALLBACK_CODENAME}' packages instead." >&2
        echo "$FALLBACK_CODENAME"
    fi
}

# ---- Step 2: Install Docker ----
install_docker() {
    header "Step 2/5 - Installing Docker Engine + Compose v2"

    local NEED_DOCKER=false
    local NEED_COMPOSE=false
    local NEED_BUILDX=false

    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
        # docker.io (Ubuntu's package) ships an old runc that fails on newer
        # kernels with "open sysctl net.ipv4.ip_unprivileged_port_start:
        # permission denied", so replace it with docker-ce.
        if dpkg -l docker.io 2>/dev/null | grep -q "^ii"; then
            warn "Found docker.io (Ubuntu package) which has a known runc bug."
            info "Will replace with docker-ce (Docker official package)..."
            NEED_DOCKER=true
        else
            log "Docker is already installed: $(docker --version)"
        fi
    else
        NEED_DOCKER=true
    fi

    if docker compose version >/dev/null 2>&1; then
        log "Docker Compose v2 is already installed: $(docker compose version 2>&1)"
    else
        NEED_COMPOSE=true
        if command -v docker-compose >/dev/null 2>&1; then
            warn "Docker Compose v1 found but v1 uses a legacy builder that fails on newer kernels."
            info "Will install Compose v2 alongside it."
        fi
    fi

    if docker buildx version >/dev/null 2>&1; then
        log "Docker Buildx is already installed"
    else
        NEED_BUILDX=true
    fi

    if [ "$NEED_DOCKER" = false ] && [ "$NEED_COMPOSE" = false ] && [ "$NEED_BUILDX" = false ]; then
        COMPOSE_CMD="docker compose"
        return 0
    fi

    if [ ! -f /etc/apt/sources.list.d/docker.list ] || [ "$NEED_DOCKER" = true ]; then
        info "Adding Docker official repository..."
        apt-get update -qq </dev/null
        apt-get install -y -qq ca-certificates curl gnupg </dev/null >/dev/null 2>&1

        install -m 0755 -d /etc/apt/keyrings
        rm -f /etc/apt/keyrings/docker.gpg
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
        chmod a+r /etc/apt/keyrings/docker.gpg

        . /etc/os-release
        local REPO_CODENAME
        REPO_CODENAME=$(docker_repo_codename "$VERSION_CODENAME")
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $REPO_CODENAME stable" \
            > /etc/apt/sources.list.d/docker.list

        apt-get update -qq </dev/null
    fi

    if [ "$NEED_DOCKER" = true ]; then
        info "Removing old Docker packages (docker.io, docker-engine, etc.)..."
        systemctl stop docker 2>/dev/null || true
        apt-get remove -y docker docker-engine docker.io docker-doc docker-compose containerd runc </dev/null 2>/dev/null || true
        apt-get autoremove -y </dev/null 2>/dev/null || true

        info "Installing Docker CE (official)..."
        apt-get install -y -qq docker-ce docker-ce-cli containerd.io </dev/null >/dev/null 2>&1
        systemctl enable --now docker
        log "Docker CE installed: $(docker --version)"
    else
        # Old runc versions crash with "open sysctl
        # net.ipv4.ip_unprivileged_port_start: permission denied" on newer
        # kernels and LXC/VPS hosts; containerd.io carries the fixed runc.
        local RUNC_VER=""
        RUNC_VER=$(runc --version 2>/dev/null | head -1 | grep -oP '[\d.]+' | head -1) || true
        if [ -n "$RUNC_VER" ]; then
            local RUNC_MAJOR RUNC_MINOR RUNC_PATCH
            RUNC_MAJOR=$(echo "$RUNC_VER" | cut -d. -f1)
            RUNC_MINOR=$(echo "$RUNC_VER" | cut -d. -f2)
            RUNC_PATCH=$(echo "$RUNC_VER" | cut -d. -f3)
            if [ "$RUNC_MAJOR" -le 1 ] && [ "$RUNC_MINOR" -le 1 ] && [ "${RUNC_PATCH:-0}" -lt 5 ]; then
                warn "runc $RUNC_VER is outdated and has a known sysctl bug. Updating..."
                apt-get install -y -qq containerd.io </dev/null >/dev/null 2>&1 || true
                log "Updated runc: $(runc --version 2>/dev/null | head -1)"
            else
                log "runc version $RUNC_VER is OK"
            fi
        else
            info "Updating containerd.io (includes runc)..."
            apt-get install -y -qq containerd.io </dev/null >/dev/null 2>&1 || true
        fi
    fi

    if [ "$NEED_COMPOSE" = true ]; then
        info "Installing Docker Compose v2 plugin..."
        apt-get install -y -qq docker-compose-plugin </dev/null >/dev/null 2>&1 || true

        if ! docker compose version >/dev/null 2>&1; then
            info "Trying direct download of Compose v2..."
            local PLUGIN_DIR="/usr/local/lib/docker/cli-plugins"
            mkdir -p "$PLUGIN_DIR"
            curl -fsSL "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" \
                -o "$PLUGIN_DIR/docker-compose" 2>/dev/null || true
            chmod +x "$PLUGIN_DIR/docker-compose" 2>/dev/null || true
        fi

        if docker compose version >/dev/null 2>&1; then
            log "Docker Compose v2 installed: $(docker compose version 2>&1)"
        else
            fail "Could not install Docker Compose v2. Visit https://docs.docker.com/engine/install/ubuntu/"
        fi
    fi

    if [ "$NEED_BUILDX" = true ]; then
        info "Installing Docker Buildx..."
        apt-get install -y -qq docker-buildx-plugin </dev/null >/dev/null 2>&1 || true
        docker buildx version >/dev/null 2>&1 && log "Docker Buildx installed" || warn "Buildx not available"
    fi

    if ! docker info >/dev/null 2>&1; then
        fail "Docker is not responding. Run: systemctl status docker"
    fi

    COMPOSE_CMD="docker compose"
}

# ---- Step 3: Clone repository ----
clone_repo() {
    header "Step 3/5 - Cloning Repository (branch: $BRANCH)"

    mkdir -p "$(dirname "$INSTALL_DIR")"

    if [ -d "$INSTALL_DIR/.git" ]; then
        info "Repository already cloned. Fetching $BRANCH..."
        cd "$INSTALL_DIR"
        git fetch --depth 1 origin "$BRANCH" </dev/null
        # -f discards any local edits (an earlier run may have used the sed
        # fallback in write_override) so switching versions always succeeds.
        git checkout -q -f -B "$BRANCH" FETCH_HEAD </dev/null
        log "Repository updated to latest $BRANCH"
    else
        [ -d "$INSTALL_DIR" ] && rm -rf "$INSTALL_DIR"
        info "Cloning $BRANCH into $INSTALL_DIR..."
        git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR" </dev/null
        log "Repository cloned to $INSTALL_DIR"
    fi

    cd "$INSTALL_DIR"

    local MISSING=0
    for f in docker-compose.yml docker/entrypoint.sh web/login.php; do
        if [ ! -f "$INSTALL_DIR/$f" ]; then
            warn "Missing expected file: $f"
            MISSING=1
        fi
    done
    [ "$MISSING" -eq 1 ] && fail "Repository is incomplete. Required files are missing."

    log "All required files verified"
}

# ---- Write docker-compose.override.yml with the chosen image + port binding ----
write_override() {
    local override="$INSTALL_DIR/docker-compose.override.yml"

    # `!override` replaces the base ports list instead of appending to it; a
    # plain merge would leave both 127.0.0.1:8080 and 0.0.0.0:8080 published.
    cat > "$override" <<EOF
# Generated by setup_docker_install.sh -- do not edit by hand.
# Re-run the script (see --help) to change the version or the port binding.
services:
  open-tprm:
    image: ${APP_IMAGE}
    ports: !override
      - "${PUBLISH_IP}:${HOST_PORT}:8080"
EOF

    if ! (cd "$INSTALL_DIR" && $COMPOSE_CMD config -q >/dev/null 2>&1); then
        # Compose older than v2.24 does not understand `!override`. Fall back to
        # editing the tracked compose file; clone_repo resets it on every run.
        warn "This Compose version does not support '!override'; editing docker-compose.yml directly."
        rm -f "$override"
        sed -i -E "s#^(\s*image:\s*).*#\1${APP_IMAGE}#" "$INSTALL_DIR/docker-compose.yml"
        sed -i -E "s#^(\s*-\s*)\"[^\"]*:8080\"#\1\"${PUBLISH_IP}:${HOST_PORT}:8080\"#" "$INSTALL_DIR/docker-compose.yml"
        (cd "$INSTALL_DIR" && $COMPOSE_CMD config -q) || fail "Generated compose configuration is invalid."
    fi

    log "Compose configured: ${APP_IMAGE} on ${PUBLISH_IP}:${HOST_PORT}"
}

# ---- Step 4: Pull the published image and start ----
pull_and_start() {
    header "Step 4/5 - Fetching and Starting the Application"

    cd "$INSTALL_DIR"
    write_override

    # The application image is published pre-built. It is NOT buildable from this
    # repository -- the Dockerfile overlays files from persistent/, which is not
    # published here -- so always pull, and never let Compose fall back to a build.
    info "Pulling the application image: $APP_IMAGE"
    info "(about 400 MB; this takes a few minutes on a slow link)"

    if ! docker pull "$APP_IMAGE" </dev/null 2>&1 | tee -a "$LOG_FILE"; then
        fail "Could not pull $APP_IMAGE. Check network/DNS access to ${REGISTRY_HOST}. Log: $LOG_FILE"
    fi
    log "Application image pulled"

    info "Starting the container..."
    if ! $COMPOSE_CMD up -d --no-build </dev/null 2>&1 | tee -a "$LOG_FILE"; then
        fail "Could not start the container. Check the log: $LOG_FILE"
    fi

    log "Container started"
}

# ---- Optional: nginx reverse proxy ----
setup_nginx() {
    [ "$USE_NGINX" = true ] || return 0

    header "Installing the nginx Reverse Proxy"

    if ss -tlnH 2>/dev/null | awk '{print $4}' | grep -qE '(^|:)80$' && ! systemctl is-active --quiet nginx; then
        fail "Port 80 is already in use by another service. Free it, then re-run with --nginx."
    fi

    info "Installing nginx..."
    apt-get install -y -qq nginx </dev/null >/dev/null 2>&1 || fail "Could not install nginx."

    local server_name="${DOMAIN:-_}"
    # client_max_body_size matches the container's PHP upload_max_filesize (2G);
    # anything smaller silently truncates evidence and assessment uploads.
    cat > /etc/nginx/sites-available/fairtprm.conf <<EOF
# Generated by setup_docker_install.sh -- do not edit by hand.
server {
    listen 80;
    listen [::]:80;
    server_name ${server_name};

    client_max_body_size 2G;

    location / {
        proxy_pass http://127.0.0.1:${HOST_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host              \$host;
        proxy_set_header X-Real-IP         \$remote_addr;
        proxy_set_header X-Forwarded-For   \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;

        # FAIR Monte Carlo runs and vendor scans can take minutes to return.
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
}
EOF

    ln -sf /etc/nginx/sites-available/fairtprm.conf /etc/nginx/sites-enabled/fairtprm.conf
    rm -f /etc/nginx/sites-enabled/default

    nginx -t >/dev/null 2>&1 || fail "nginx configuration test failed. Run: nginx -t"
    systemctl enable --now nginx >/dev/null 2>&1
    systemctl reload nginx
    log "nginx is proxying port 80 -> 127.0.0.1:${HOST_PORT}"

    if [ "$USE_TLS" = true ]; then
        info "Requesting a Let's Encrypt certificate for $DOMAIN..."
        apt-get install -y -qq certbot python3-certbot-nginx </dev/null >/dev/null 2>&1 \
            || fail "Could not install certbot."

        if certbot --nginx -d "$DOMAIN" -m "$TLS_EMAIL" --agree-tos -n --redirect 2>&1 | tee -a "$LOG_FILE"; then
            log "TLS certificate installed; HTTP now redirects to HTTPS"
        else
            USE_TLS=false
            warn "certbot could not issue a certificate (is $DOMAIN pointed at this server, port 80 open?)."
            warn "nginx is still serving plain HTTP. Re-run: certbot --nginx -d $DOMAIN"
        fi
    fi
}

# ---- Step 5: Wait for healthy and show credentials ----
wait_and_show() {
    header "Step 5/5 - Waiting for Application to Start"

    cd "$INSTALL_DIR"

    info "Waiting for the application to become healthy (this takes ~60 seconds)..."
    info "ClamAV virus definitions loading + database initialization..."
    echo ""

    local SECONDS_WAITED=0
    local MAX_WAIT=300
    local HEALTHY=0

    while [ $SECONDS_WAITED -lt $MAX_WAIT ]; do
        local HEALTH_STATUS=""
        HEALTH_STATUS=$(docker inspect --format='{{.State.Health.Status}}' fairtprm 2>/dev/null) || true
        if [ "$HEALTH_STATUS" = "healthy" ]; then
            HEALTHY=1
            break
        fi

        local RUNNING=""
        RUNNING=$(docker inspect --format='{{.State.Running}}' fairtprm 2>/dev/null) || true
        if [ "$RUNNING" = "false" ]; then
            echo ""
            fail "Container exited unexpectedly. Check logs: cd $INSTALL_DIR && $COMPOSE_CMD logs"
        fi

        printf "\r  Waiting... %3ds / %ds" "$SECONDS_WAITED" "$MAX_WAIT"
        sleep 5
        SECONDS_WAITED=$((SECONDS_WAITED + 5))
    done

    echo ""
    echo ""

    if [ "$HEALTHY" -eq 1 ]; then
        log "Application is healthy and ready!"
    else
        warn "Health check hasn't passed after ${MAX_WAIT}s. The app may still be starting."
        warn "Check status: cd $INSTALL_DIR && $COMPOSE_CMD logs -f"
    fi

    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN}  Fair TPRM & GRC Platform - Setup Complete!${NC}"
    echo -e "${GREEN}============================================================${NC}"
    echo ""

    show_access_urls

    echo ""
    echo -e "  ${BLUE}Install directory:${NC} $INSTALL_DIR"
    echo -e "  ${BLUE}Version:${NC}           $APP_IMAGE"
    echo -e "  ${BLUE}Setup log:${NC}         $LOG_FILE"

    show_credentials
    echo ""
    verify_credentials

    echo ""
    echo -e "  ${BLUE}Useful commands:${NC}"
    echo "    cd $INSTALL_DIR"
    echo "    $COMPOSE_CMD logs -f          # Watch live logs"
    echo "    $COMPOSE_CMD restart          # Restart the container"
    echo "    $COMPOSE_CMD down             # Stop the container"
    echo "    $COMPOSE_CMD up -d            # Start the container"
    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo ""
}

# ---- Main ----
main() {
    parse_args "$@"

    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN}  Fair TPRM & GRC Platform - Docker Setup Script${NC}"
    echo -e "${GREEN}============================================================${NC}"
    echo ""
    info "Log file: $LOG_FILE"

    preflight
    install_git       # also gives us curl, needed to query the registry
    configure         # prompts (or defaults), then validates the choices
    check_existing
    install_docker
    clone_repo
    pull_and_start
    setup_nginx
    wait_and_show
}

main "$@"
exit 0
}
