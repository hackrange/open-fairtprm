#!/usr/bin/env bash
# =============================================================================
# Fair TPRM & GRC Platform - Automated Docker Setup for Ubuntu 22.04 / 24.04 / 26.04
# =============================================================================
#
# This script:
#   1. Detects your Ubuntu version
#   2. Installs Git if not already installed
#   3. Installs Docker Engine and Docker Compose v2
#   4. Clones the repository into /data/docker/fairtprm
#   5. Pulls the published application image and starts the container
#
# Usage (pick one):
#   curl -fsSL https://git.hackrange.com/trice/open-fairtprm/-/raw/2.6.2/setup_docker_install.sh -o /tmp/setup.sh && sudo bash /tmp/setup.sh
#   -- or --
#   chmod +x setup_docker_install.sh && sudo ./setup_docker_install.sh
#
# Environment overrides:
#   FAIRTPRM_BRANCH   Git branch to deploy       (default: 2.6.2)
#   FAIRTPRM_DIR      Install directory          (default: /data/docker/fairtprm)
#
# =============================================================================
# The { } block forces bash to read the ENTIRE script into memory before
# executing anything. Without this, "curl | bash" reads line-by-line and
# subcommands (apt-get, docker, etc.) can swallow parts of the script.
# =============================================================================
{

set -euo pipefail

# ---- Constants ----
INSTALL_DIR="${FAIRTPRM_DIR:-/data/docker/fairtprm}"
REPO_URL="https://git.hackrange.com/trice/open-fairtprm.git"
BRANCH="${FAIRTPRM_BRANCH:-2.6.2}"
LOG_FILE="/tmp/fairtprm-setup-$(date +%Y%m%d-%H%M%S).log"
COMPOSE_CMD=""
APP_IMAGE=""

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

# ---- Detect which compose command to use ----
detect_compose() {
    if docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD="docker compose"
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD="docker-compose"
    fi
}

# ---- Resolve the image name declared in docker-compose.yml ----
resolve_image() {
    APP_IMAGE=$(cd "$INSTALL_DIR" && $COMPOSE_CMD config --images 2>/dev/null | head -1) || true

    if [ -z "$APP_IMAGE" ]; then
        # Fallback: read the image: line straight out of the compose file.
        APP_IMAGE=$(awk '/^[[:space:]]*image:[[:space:]]*/ {print $2; exit}' "$INSTALL_DIR/docker-compose.yml") || true
    fi

    if [ -z "$APP_IMAGE" ]; then
        fail "Could not determine the application image from $INSTALL_DIR/docker-compose.yml"
    fi
}

# ---- Print how to reach the app ----
show_access_urls() {
    local SERVER_IP
    SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}') || SERVER_IP=""

    echo -e "  ${BLUE}Access the application at:${NC}"
    echo ""
    echo -e "    ${YELLOW}http://localhost:8080${NC}"
    echo ""
    echo -e "  ${BLUE}Note:${NC} port 8080 is published on ${YELLOW}127.0.0.1 only${NC}, so the app is not"
    echo "  reachable from other machines. To reach it from your laptop, tunnel over SSH:"
    echo ""
    if [ -n "$SERVER_IP" ]; then
        echo "    ssh -L 8080:localhost:8080 ${SUDO_USER:-$(whoami)}@${SERVER_IP}"
    else
        echo "    ssh -L 8080:localhost:8080 user@this-server"
    fi
    echo ""
    echo "  then browse to http://localhost:8080 on your laptop."
    echo ""
    echo "  To expose it on the network instead, edit the ports: line in"
    echo "  $INSTALL_DIR/docker-compose.yml and restart. Put it behind"
    echo "  TLS (a reverse proxy) before doing so -- the app speaks plain HTTP."
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

# ---- Check if already installed and running ----
check_existing() {
    # Not installed at all
    if [ ! -d "$INSTALL_DIR" ] || [ ! -f "$INSTALL_DIR/docker-compose.yml" ]; then
        return 0
    fi

    # Installed but Docker is not available yet
    if ! command -v docker >/dev/null 2>&1; then
        return 0
    fi

    detect_compose
    if [ -z "$COMPOSE_CMD" ]; then
        return 0
    fi

    # Check if the container is running
    local CONTAINER_STATE=""
    CONTAINER_STATE=$(cd "$INSTALL_DIR" && $COMPOSE_CMD ps -q 2>/dev/null | head -1) || true

    if [ -n "$CONTAINER_STATE" ]; then
        local IS_RUNNING=""
        IS_RUNNING=$(docker inspect --format='{{.State.Running}}' "$CONTAINER_STATE" 2>/dev/null) || true

        if [ "$IS_RUNNING" = "true" ]; then
            echo ""
            echo -e "${GREEN}============================================================${NC}"
            echo -e "${GREEN}  Fair TPRM & GRC Platform - Already Installed & Running!${NC}"
            echo -e "${GREEN}============================================================${NC}"
            echo ""
            echo -e "  The application is ${GREEN}already set up${NC} and ${GREEN}running${NC}."
            echo ""

            show_access_urls
            show_credentials

            echo ""
            echo -e "  ${BLUE}Useful commands:${NC}"
            echo "    cd $INSTALL_DIR"
            echo "    $COMPOSE_CMD logs -f          # Watch live logs"
            echo "    $COMPOSE_CMD restart          # Restart the container"
            echo "    $COMPOSE_CMD down             # Stop the container"
            echo "    $COMPOSE_CMD up -d            # Start the container"
            echo ""
            echo -e "  ${BLUE}To reinstall from scratch:${NC}"
            echo "    cd $INSTALL_DIR && $COMPOSE_CMD down"
            echo "    sudo bash $INSTALL_DIR/setup_docker_install.sh"
            echo ""
            echo -e "${GREEN}============================================================${NC}"
            echo ""
            exit 0
        fi
    fi

    # Installed but not running - stop any dead containers and continue with reinstall
    info "Previous installation found but not running. Reinstalling..."
    (cd "$INSTALL_DIR" && $COMPOSE_CMD down 2>/dev/null) || true
    return 0
}

# ---- Pre-flight checks ----
preflight() {
    header "Pre-flight Checks"

    # Must be root
    if [ "$(id -u)" -ne 0 ]; then
        fail "This script must be run as root. Use: sudo $0"
    fi
    log "Running as root"

    # Must be Ubuntu
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

    # Check disk space
    AVAIL_KB=$(df --output=avail / | tail -1 | tr -d ' ')
    AVAIL_GB=$((AVAIL_KB / 1024 / 1024))
    if [ "$AVAIL_GB" -lt 5 ]; then
        fail "Insufficient disk space. Need at least 5 GB, have ${AVAIL_GB} GB on /"
    fi
    log "Disk space: ${AVAIL_GB} GB available"

    # Check RAM
    TOTAL_RAM_KB=$(grep MemTotal /proc/meminfo | awk '{print $2}')
    TOTAL_RAM_GB=$((TOTAL_RAM_KB / 1024 / 1024))
    if [ "$TOTAL_RAM_KB" -lt 1800000 ]; then
        warn "Low memory detected (${TOTAL_RAM_GB} GB). Recommended: 2 GB minimum."
    else
        log "RAM: ${TOTAL_RAM_GB} GB available"
    fi

    # Check for existing installation
    check_existing
}

# ---- Step 1: Install Git ----
install_git() {
    header "Step 1/5 - Installing Git"

    # curl, gnupg and ca-certificates are needed later to add Docker's apt
    # repository, so make sure they are present even when Git already is.
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

    # Check what we already have
    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
        # Check if this is docker.io (Ubuntu's package) instead of docker-ce.
        # docker.io ships an old runc that fails on newer kernels with:
        #   "open sysctl net.ipv4.ip_unprivileged_port_start: permission denied"
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

    # If everything is installed, we're done
    if [ "$NEED_DOCKER" = false ] && [ "$NEED_COMPOSE" = false ] && [ "$NEED_BUILDX" = false ]; then
        COMPOSE_CMD="docker compose"
        return 0
    fi

    # Add Docker's official apt repository (needed for docker-ce, compose plugin, buildx)
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

    # Install what we need
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
        # Even if Docker is already installed, ensure containerd.io (which
        # includes runc) is up to date from Docker's official repo.
        # Old runc versions crash with "open sysctl net.ipv4.ip_unprivileged_port_start:
        # permission denied" on newer kernels and LXC/VPS hosts.
        local RUNC_VER=""
        RUNC_VER=$(runc --version 2>/dev/null | head -1 | grep -oP '[\d.]+' | head -1) || true
        if [ -n "$RUNC_VER" ]; then
            local RUNC_MAJOR RUNC_MINOR RUNC_PATCH
            RUNC_MAJOR=$(echo "$RUNC_VER" | cut -d. -f1)
            RUNC_MINOR=$(echo "$RUNC_VER" | cut -d. -f2)
            RUNC_PATCH=$(echo "$RUNC_VER" | cut -d. -f3)
            # Need runc >= 1.1.5 for the sysctl fix
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
            # Direct download fallback
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
        if docker buildx version >/dev/null 2>&1; then
            log "Docker Buildx installed"
        else
            warn "Buildx not available - builds may be slower"
        fi
    fi

    # Verify Docker is working
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
        info "Repository already cloned. Pulling latest changes..."
        cd "$INSTALL_DIR"
        git fetch --depth 1 origin "$BRANCH" </dev/null
        git reset --hard FETCH_HEAD </dev/null
        log "Repository updated to latest $BRANCH"
    else
        if [ -d "$INSTALL_DIR" ]; then
            rm -rf "$INSTALL_DIR"
        fi

        info "Cloning $BRANCH into $INSTALL_DIR..."
        git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR" </dev/null
        log "Repository cloned to $INSTALL_DIR"
    fi

    cd "$INSTALL_DIR"

    # Verify critical files
    local MISSING=0
    for f in docker-compose.yml docker/entrypoint.sh web/login.php; do
        if [ ! -f "$INSTALL_DIR/$f" ]; then
            warn "Missing expected file: $f"
            MISSING=1
        fi
    done

    if [ "$MISSING" -eq 1 ]; then
        fail "Repository is incomplete. Required files are missing."
    fi

    log "All required files verified"
}

# ---- Step 4: Pull the published image and start ----
pull_and_start() {
    header "Step 4/5 - Fetching and Starting the Application"

    cd "$INSTALL_DIR"
    resolve_image

    # The application image is published pre-built. It is NOT buildable from
    # this repository -- the Dockerfile overlays files from persistent/, which
    # is not published here -- so always pull and never let Compose fall back
    # to building (hence --no-build below).
    info "Pulling the application image: $APP_IMAGE"
    info "(about 400 MB; this takes a few minutes on a slow link)"

    if ! docker pull "$APP_IMAGE" </dev/null 2>&1 | tee -a "$LOG_FILE"; then
        fail "Could not pull $APP_IMAGE. Check network/DNS access to dockerregistry.fairtprm.com. Log: $LOG_FILE"
    fi
    log "Application image pulled"

    info "Starting the container..."
    if ! $COMPOSE_CMD up -d --no-build </dev/null 2>&1 | tee -a "$LOG_FILE"; then
        fail "Could not start the container. Check the log: $LOG_FILE"
    fi

    log "Container started"
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

        # Check if the container died
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
    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN}  Fair TPRM & GRC Platform - Docker Setup Script${NC}"
    echo -e "${GREEN}============================================================${NC}"
    echo ""
    info "Log file: $LOG_FILE"

    preflight
    install_git
    install_docker
    clone_repo
    pull_and_start
    wait_and_show
}

main "$@"
exit 0
}
