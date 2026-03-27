#!/usr/bin/env bash
# =============================================================================
# Fair TPRM & GRC Platform - Automated Docker Setup for Ubuntu 22.04 / 24.04
# =============================================================================
#
# This script:
#   1. Detects your Ubuntu version (22.04 or 24.04)
#   2. Installs Git if not already installed
#   3. Installs Docker Engine and Docker Compose v2
#   4. Clones the repository into /data/docker/fairtprm
#   5. Builds and starts the application container
#
# Usage (pick one):
#   curl -fsSL https://git.hackrange.com/trice/open-fairtprm/-/raw/master/setup_docker_install.sh -o /tmp/setup.sh && sudo bash /tmp/setup.sh
#   -- or --
#   chmod +x setup_docker_install.sh && sudo ./setup_docker_install.sh
#
# =============================================================================
# The { } block forces bash to read the ENTIRE script into memory before
# executing anything. Without this, "curl | bash" reads line-by-line and
# subcommands (apt-get, docker, etc.) can swallow parts of the script.
# =============================================================================
{

set -euo pipefail

# ---- Constants ----
INSTALL_DIR="/data/docker/fairtprm"
REPO_URL="https://git.hackrange.com/trice/open-fairtprm.git"
LOG_FILE="/tmp/fairtprm-setup-$(date +%Y%m%d-%H%M%S).log"
COMPOSE_CMD=""

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
        printf "  ${GREEN}|${NC}   Username:  ${YELLOW}%-43s${NC}${GREEN}|${NC}\n" "${ADMIN_USER:-admin}"
        printf "  ${GREEN}|${NC}   Password:  ${YELLOW}%-43s${NC}${GREEN}|${NC}\n" "${ADMIN_PASS:-<not yet generated>}"
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
            local SERVER_IP
            SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}') || SERVER_IP="localhost"

            echo ""
            echo -e "${GREEN}============================================================${NC}"
            echo -e "${GREEN}  Fair TPRM & GRC Platform - Already Installed & Running!${NC}"
            echo -e "${GREEN}============================================================${NC}"
            echo ""
            echo -e "  The application is ${GREEN}already set up${NC} and ${GREEN}running${NC}."
            echo ""
            echo -e "  ${BLUE}Access the application at:${NC}"
            echo ""
            echo -e "    ${YELLOW}http://${SERVER_IP}:8080${NC}"
            echo -e "    ${YELLOW}http://localhost:8080${NC}"

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

    # Installed but not running - stop any dead containers and continue with rebuild
    info "Previous installation found but not running. Rebuilding..."
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
        22.04|24.04)
            log "Detected Ubuntu $VERSION_ID ($VERSION_CODENAME)"
            ;;
        *)
            fail "Unsupported Ubuntu version: $VERSION_ID. This script supports 22.04 and 24.04."
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

    if command -v git >/dev/null 2>&1; then
        log "Git is already installed: $(git --version)"
        return 0
    fi

    info "Installing Git..."
    apt-get update -qq </dev/null
    apt-get install -y -qq git ca-certificates curl gnupg lsb-release </dev/null >/dev/null 2>&1
    log "Git installed: $(git --version)"
}

# ---- Step 2: Install Docker ----
install_docker() {
    header "Step 2/5 - Installing Docker Engine + Compose v2"

    local NEED_DOCKER=false
    local NEED_COMPOSE=false
    local NEED_BUILDX=false

    # Check what we already have
    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
        log "Docker is already installed: $(docker --version)"
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
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $VERSION_CODENAME stable" \
            > /etc/apt/sources.list.d/docker.list

        apt-get update -qq </dev/null
    fi

    # Install what we need
    if [ "$NEED_DOCKER" = true ]; then
        info "Removing old Docker packages..."
        apt-get remove -y docker docker-engine docker.io containerd runc </dev/null 2>/dev/null || true

        info "Installing Docker Engine..."
        apt-get install -y -qq docker-ce docker-ce-cli containerd.io </dev/null >/dev/null 2>&1
        systemctl enable --now docker
        log "Docker installed: $(docker --version)"
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
    header "Step 3/5 - Cloning Repository"

    mkdir -p "$(dirname "$INSTALL_DIR")"

    if [ -d "$INSTALL_DIR/.git" ]; then
        info "Repository already cloned. Pulling latest changes..."
        cd "$INSTALL_DIR"
        git fetch --all </dev/null
        git reset --hard origin/master </dev/null
        log "Repository updated to latest"
    else
        if [ -d "$INSTALL_DIR" ]; then
            rm -rf "$INSTALL_DIR"
        fi

        info "Cloning into $INSTALL_DIR..."
        git clone "$REPO_URL" "$INSTALL_DIR" </dev/null
        log "Repository cloned to $INSTALL_DIR"
    fi

    cd "$INSTALL_DIR"

    # Verify critical files
    local MISSING=0
    for f in Dockerfile docker-compose.yml docker/entrypoint.sh web/login.php; do
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

# ---- Step 4: Build and start ----
build_and_start() {
    header "Step 4/5 - Building and Starting the Application"

    cd "$INSTALL_DIR"

    export DOCKER_BUILDKIT=1
    export COMPOSE_DOCKER_CLI_BUILD=1

    info "Building the Docker image (this may take 5-10 minutes on first run)..."
    $COMPOSE_CMD build --progress=plain </dev/null 2>&1 | tee -a "$LOG_FILE"

    if [ "${PIPESTATUS[0]}" -ne 0 ]; then
        fail "Docker build failed. Check the log: $LOG_FILE"
    fi
    log "Docker image built successfully"

    info "Starting the container..."
    $COMPOSE_CMD up -d </dev/null

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
    local MAX_WAIT=180
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

    local SERVER_IP
    SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}') || SERVER_IP="localhost"

    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN}  Fair TPRM & GRC Platform - Setup Complete!${NC}"
    echo -e "${GREEN}============================================================${NC}"
    echo ""
    echo -e "  ${BLUE}Access the application at:${NC}"
    echo ""
    echo -e "    ${YELLOW}http://${SERVER_IP}:8080${NC}"
    echo -e "    ${YELLOW}http://localhost:8080${NC}"
    echo ""
    echo -e "  ${BLUE}Install directory:${NC} $INSTALL_DIR"
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
    build_and_start
    wait_and_show
}

main "$@"
exit 0
}
