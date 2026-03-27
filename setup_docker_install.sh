#!/usr/bin/env bash
# =============================================================================
# Fair TPRM & GRC Platform — Automated Docker Setup for Ubuntu 22.04 / 24.04
# =============================================================================
#
# This script:
#   1. Detects your Ubuntu version (22.04 or 24.04)
#   2. Installs Git if not already installed
#   3. Installs Docker Engine and Docker Compose v2 (falls back to v1 if needed)
#   4. Clones the repository into /data/docker/fairtprm
#   5. Builds and starts the application container
#
# Usage:
#   chmod +x setup_docker_install.sh
#   sudo ./setup_docker_install.sh
#
# =============================================================================

set -euo pipefail

# ---- Constants ----
INSTALL_DIR="/data/docker/fairtprm"
REPO_URL="https://git.hackrange.com/trice/open-fairtprm.git"
LOG_FILE="/tmp/fairtprm-setup-$(date +%Y%m%d-%H%M%S).log"

# ---- Colors for output ----
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ---- Helper functions ----
log()   { echo -e "${GREEN}[OK]${NC}    $1" | tee -a "$LOG_FILE"; }
info()  { echo -e "${BLUE}[INFO]${NC}  $1" | tee -a "$LOG_FILE"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $1" | tee -a "$LOG_FILE"; }
fail()  { echo -e "${RED}[FAIL]${NC}  $1" | tee -a "$LOG_FILE"; exit 1; }

header() {
    echo "" | tee -a "$LOG_FILE"
    echo -e "${BLUE}============================================================${NC}" | tee -a "$LOG_FILE"
    echo -e "${BLUE}  $1${NC}" | tee -a "$LOG_FILE"
    echo -e "${BLUE}============================================================${NC}" | tee -a "$LOG_FILE"
    echo "" | tee -a "$LOG_FILE"
}

# ---- Show credentials helper ----
# Reads credentials from the container and prints username/password prominently
show_credentials() {
    local COMP_CMD="$1"

    cd "$INSTALL_DIR"

    local CREDS
    CREDS=$($COMP_CMD exec -T open-tprm cat /persistent/config/.sensitive 2>/dev/null) || true

    if [ -n "$CREDS" ]; then
        local ADMIN_USER ADMIN_PASS DB_ROOT_PASS DB_USER_PASS ENC_KEY
        ADMIN_USER=$(echo "$CREDS" | grep "^PORTAL_ADMIN_USERNAME=" | cut -d'=' -f2-)
        ADMIN_PASS=$(echo "$CREDS" | grep "^PORTAL_ADMIN_PASSWORD=" | cut -d'=' -f2-)
        DB_ROOT_PASS=$(echo "$CREDS" | grep "^MYSQL_ROOT_PASSWORD=" | cut -d'=' -f2-)
        DB_USER_PASS=$(echo "$CREDS" | grep "^MYSQL_USER_PASSWORD=" | cut -d'=' -f2-)
        ENC_KEY=$(echo "$CREDS" | grep "^ENCRYPTION_KEY=" | cut -d'=' -f2-)

        echo ""
        echo -e "  ${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
        echo -e "  ${GREEN}║                  ADMIN LOGIN CREDENTIALS                  ║${NC}"
        echo -e "  ${GREEN}╠════════════════════════════════════════════════════════════╣${NC}"
        echo -e "  ${GREEN}║${NC}                                                            ${GREEN}║${NC}"
        printf "  ${GREEN}║${NC}   Username:  ${YELLOW}%-43s${NC}${GREEN}║${NC}\n" "${ADMIN_USER:-admin}"
        printf "  ${GREEN}║${NC}   Password:  ${YELLOW}%-43s${NC}${GREEN}║${NC}\n" "${ADMIN_PASS:-<not yet generated>}"
        echo -e "  ${GREEN}║${NC}                                                            ${GREEN}║${NC}"
        echo -e "  ${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
        echo ""
        echo -e "  ${BLUE}Database Credentials (for advanced users):${NC}"
        echo -e "    MySQL Root Password:     ${DB_ROOT_PASS:-<not available>}"
        echo -e "    MySQL App User:          tprm_user"
        echo -e "    MySQL App Password:      ${DB_USER_PASS:-<not available>}"
        echo -e "    Encryption Key:          ${ENC_KEY:-<not available>}"
        echo ""
        echo -e "  ${BLUE}To view these credentials later, run:${NC}"
        echo "    cd $INSTALL_DIR && $COMP_CMD exec open-tprm cat /persistent/config/.sensitive"
    else
        echo ""
        echo -e "  ${YELLOW}Credentials are not available yet. The container may still be initializing.${NC}"
        echo -e "  ${YELLOW}Check the logs for your credentials:${NC}"
        echo "    cd $INSTALL_DIR && $COMP_CMD logs | grep -A 20 'DATABASE CREATED'"
        echo ""
        echo -e "  ${YELLOW}Or wait a moment and run:${NC}"
        echo "    cd $INSTALL_DIR && $COMP_CMD exec open-tprm cat /persistent/config/.sensitive"
    fi
}

# ---- Show info for an existing running installation ----
show_existing_install() {
    local COMP_CMD="$1"
    local SERVER_IP
    SERVER_IP=$(hostname -I | awk '{print $1}')

    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN}  Fair TPRM & GRC Platform — Already Installed & Running!${NC}"
    echo -e "${GREEN}============================================================${NC}"
    echo ""
    echo -e "  The application is ${GREEN}already set up${NC} and ${GREEN}running${NC}."
    echo ""
    echo -e "  ${BLUE}Access the application at:${NC}"
    echo ""
    echo -e "    ${YELLOW}http://${SERVER_IP}:8080${NC}"
    echo -e "    ${YELLOW}http://localhost:8080${NC}"
    echo ""

    show_credentials "$COMP_CMD"

    echo ""
    echo -e "  ${BLUE}Useful commands:${NC}"
    echo "    cd $INSTALL_DIR"
    echo "    $COMP_CMD logs -f          # Watch live logs"
    echo "    $COMP_CMD restart          # Restart the container"
    echo "    $COMP_CMD down             # Stop the container"
    echo "    $COMP_CMD up -d            # Start the container"
    echo ""
    echo -e "  ${BLUE}To reinstall from scratch, stop first then re-run:${NC}"
    echo "    cd $INSTALL_DIR && $COMP_CMD down"
    echo "    sudo $0"
    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo ""
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

    # Must be 22.04 or 24.04
    case "$VERSION_ID" in
        22.04|24.04)
            log "Detected Ubuntu $VERSION_ID ($VERSION_CODENAME)"
            ;;
        *)
            fail "Unsupported Ubuntu version: $VERSION_ID. This script supports 22.04 and 24.04."
            ;;
    esac

    # Check available disk space (need at least 5 GB on the partition where /data will live)
    AVAIL_KB=$(df --output=avail / | tail -1 | tr -d ' ')
    AVAIL_GB=$((AVAIL_KB / 1024 / 1024))
    if [ "$AVAIL_GB" -lt 5 ]; then
        fail "Insufficient disk space. Need at least 5 GB, have ${AVAIL_GB} GB on /"
    fi
    log "Disk space: ${AVAIL_GB} GB available"

    # Check available RAM (need at least 2 GB)
    TOTAL_RAM_KB=$(grep MemTotal /proc/meminfo | awk '{print $2}')
    TOTAL_RAM_GB=$((TOTAL_RAM_KB / 1024 / 1024))
    if [ "$TOTAL_RAM_KB" -lt 1800000 ]; then
        warn "Low memory detected (${TOTAL_RAM_GB} GB). Recommended: 2 GB minimum. ClamAV may not start."
    else
        log "RAM: ${TOTAL_RAM_GB} GB available"
    fi

    # Check if already installed and running
    if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/docker-compose.yml" ] && command -v docker &>/dev/null; then
        # Determine which compose command works
        local EXISTING_COMPOSE=""
        if docker compose version &>/dev/null; then
            EXISTING_COMPOSE="docker compose"
        elif command -v docker-compose &>/dev/null; then
            EXISTING_COMPOSE="docker-compose"
        fi

        if [ -n "$EXISTING_COMPOSE" ]; then
            # Check if the container is running and healthy
            local EXISTING_STATE
            EXISTING_STATE=$(cd "$INSTALL_DIR" && $EXISTING_COMPOSE ps --format '{{.State}}' 2>/dev/null | head -1) || true

            if [ "$EXISTING_STATE" = "running" ]; then
                show_existing_install "$EXISTING_COMPOSE"
                exit 0
            fi
        fi

        # Installed but not running — offer to reinstall or start
        warn "Directory $INSTALL_DIR exists but the container is not running."
        echo ""
        echo "  What would you like to do?"
        echo ""
        echo "    1) Start the existing container"
        echo "    2) Reinstall from scratch"
        echo "    3) Exit"
        echo ""
        read -r -p "  Choose [1/2/3]: " CHOICE
        case "$CHOICE" in
            1)
                info "Starting existing container..."
                cd "$INSTALL_DIR"
                COMPOSE_CMD="$EXISTING_COMPOSE"
                if [ -z "$COMPOSE_CMD" ]; then
                    COMPOSE_CMD="docker compose"
                fi
                $COMPOSE_CMD up -d
                wait_and_show
                exit 0
                ;;
            2)
                info "Stopping any existing containers..."
                (cd "$INSTALL_DIR" && docker compose down 2>/dev/null) || true
                (cd "$INSTALL_DIR" && docker-compose down 2>/dev/null) || true
                ;;
            *)
                info "Exiting."
                exit 0
                ;;
        esac
    fi
}

# ---- Step 1: Install Git ----
install_git() {
    header "Step 1/5 — Installing Git"

    if command -v git &>/dev/null; then
        log "Git is already installed: $(git --version)"
        return
    fi

    info "Installing Git..."
    apt-get update -qq
    apt-get install -y -qq git ca-certificates curl gnupg lsb-release > /dev/null 2>&1
    log "Git installed: $(git --version)"
}

# ---- Step 2: Install Docker ----
install_docker() {
    header "Step 2/5 — Installing Docker Engine"

    # Check if Docker is already installed and working
    if command -v docker &>/dev/null && docker info &>/dev/null; then
        DOCKER_VERSION=$(docker --version)
        log "Docker is already installed: $DOCKER_VERSION"
        install_compose
        return
    fi

    info "Removing any old Docker packages..."
    apt-get remove -y docker docker-engine docker.io containerd runc 2>/dev/null || true

    info "Installing Docker prerequisites..."
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg > /dev/null 2>&1

    info "Adding Docker GPG key and repository..."
    install -m 0755 -d /etc/apt/keyrings
    if [ -f /etc/apt/keyrings/docker.gpg ]; then
        rm -f /etc/apt/keyrings/docker.gpg
    fi
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg

    . /etc/os-release
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $VERSION_CODENAME stable" \
        | tee /etc/apt/sources.list.d/docker.list > /dev/null

    info "Installing Docker Engine..."
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin > /dev/null 2>&1

    # Start and enable Docker
    systemctl enable --now docker

    # Verify Docker works
    if ! docker info &>/dev/null; then
        fail "Docker installed but not responding. Check: systemctl status docker"
    fi

    log "Docker installed: $(docker --version)"
    install_compose
}

# ---- Install/verify Docker Compose ----
install_compose() {
    info "Checking Docker Compose..."

    # Prefer Compose v2 (docker compose — the plugin)
    if docker compose version &>/dev/null; then
        COMPOSE_CMD="docker compose"
        log "Docker Compose v2 available: $(docker compose version)"
        return
    fi

    # Check for standalone docker-compose v1
    if command -v docker-compose &>/dev/null; then
        COMPOSE_CMD="docker-compose"
        log "Docker Compose v1 available: $(docker-compose --version)"
        return
    fi

    # Try to install the compose plugin
    info "Installing Docker Compose plugin..."
    apt-get install -y -qq docker-compose-plugin > /dev/null 2>&1 || true

    if docker compose version &>/dev/null; then
        COMPOSE_CMD="docker compose"
        log "Docker Compose v2 installed: $(docker compose version)"
        return
    fi

    # Last resort: install standalone v1
    warn "Compose v2 plugin not available, installing standalone docker-compose..."
    curl -fsSL "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" \
        -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose

    if command -v docker-compose &>/dev/null; then
        COMPOSE_CMD="docker-compose"
        log "Docker Compose v1 installed: $(docker-compose --version)"
        return
    fi

    fail "Could not install Docker Compose. Please install it manually."
}

# ---- Step 3: Clone repository ----
clone_repo() {
    header "Step 3/5 — Cloning Repository"

    # Create parent directories
    mkdir -p "$(dirname "$INSTALL_DIR")"

    if [ -d "$INSTALL_DIR/.git" ]; then
        info "Repository already cloned. Pulling latest changes..."
        cd "$INSTALL_DIR"
        git fetch --all
        git reset --hard origin/master
        log "Repository updated to latest"
    else
        # Remove directory if it exists but isn't a git repo
        if [ -d "$INSTALL_DIR" ]; then
            rm -rf "$INSTALL_DIR"
        fi

        info "Cloning into $INSTALL_DIR..."
        git clone "$REPO_URL" "$INSTALL_DIR"
        log "Repository cloned to $INSTALL_DIR"
    fi

    cd "$INSTALL_DIR"

    # Verify critical files exist
    local MISSING=0
    for f in Dockerfile docker-compose.yml docker/entrypoint.sh web/login.php; do
        if [ ! -f "$INSTALL_DIR/$f" ]; then
            warn "Missing expected file: $f"
            MISSING=1
        fi
    done

    if [ "$MISSING" -eq 1 ]; then
        fail "Repository is incomplete. Some required files are missing."
    fi

    log "All required files verified"
}

# ---- Step 4: Build and start ----
build_and_start() {
    header "Step 4/5 — Building and Starting the Application"

    cd "$INSTALL_DIR"

    info "Building the Docker image (this may take 5-10 minutes on first run)..."
    $COMPOSE_CMD build 2>&1 | tee -a "$LOG_FILE"

    if [ "${PIPESTATUS[0]}" -ne 0 ]; then
        fail "Docker build failed. Check the log: $LOG_FILE"
    fi
    log "Docker image built successfully"

    info "Starting the container..."
    $COMPOSE_CMD up -d

    log "Container started"
}

# ---- Step 5: Wait for healthy and show credentials ----
wait_and_show() {
    header "Step 5/5 — Waiting for Application to Start"

    cd "$INSTALL_DIR"

    info "Waiting for the application to become healthy (this takes ~60 seconds)..."
    info "ClamAV virus definitions loading + database initialization..."
    echo ""

    # Wait up to 180 seconds for the health check to pass
    local SECONDS_WAITED=0
    local MAX_WAIT=180
    local HEALTHY=0

    while [ $SECONDS_WAITED -lt $MAX_WAIT ]; do
        # Check container health status
        local STATUS
        STATUS=$($COMPOSE_CMD ps --format '{{.Health}}' 2>/dev/null | head -1) || true

        if [ "$STATUS" = "healthy" ]; then
            HEALTHY=1
            break
        fi

        # Also check if the container exited
        local STATE
        STATE=$($COMPOSE_CMD ps --format '{{.State}}' 2>/dev/null | head -1) || true
        if [ "$STATE" = "exited" ] || [ "$STATE" = "dead" ]; then
            echo ""
            fail "Container exited unexpectedly. Check logs with: cd $INSTALL_DIR && $COMPOSE_CMD logs"
        fi

        # Show a progress indicator
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
        warn "Check status with: cd $INSTALL_DIR && $COMPOSE_CMD logs -f"
    fi

    # Show completion banner with credentials
    local SERVER_IP
    SERVER_IP=$(hostname -I | awk '{print $1}')

    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN}  Fair TPRM & GRC Platform — Setup Complete!${NC}"
    echo -e "${GREEN}============================================================${NC}"
    echo ""
    echo -e "  ${BLUE}Access the application at:${NC}"
    echo ""
    echo -e "    ${YELLOW}http://${SERVER_IP}:8080${NC}"
    echo -e "    ${YELLOW}http://localhost:8080${NC}"
    echo ""
    echo -e "  ${BLUE}Install directory:${NC} $INSTALL_DIR"
    echo -e "  ${BLUE}Setup log:${NC}         $LOG_FILE"

    show_credentials "$COMPOSE_CMD"

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
    echo -e "${GREEN}  Fair TPRM & GRC Platform — Docker Setup Script${NC}"
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
