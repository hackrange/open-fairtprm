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

    # Check if the install directory already exists with content
    if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/docker-compose.yml" ]; then
        warn "Directory $INSTALL_DIR already exists with a docker-compose.yml."
        echo ""
        read -r -p "  Overwrite and reinstall? (y/N): " CONFIRM
        if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
            info "Aborted by user."
            exit 0
        fi
        # Stop any running containers before overwriting
        if command -v docker &>/dev/null; then
            info "Stopping any existing containers in $INSTALL_DIR..."
            (cd "$INSTALL_DIR" && docker compose down 2>/dev/null) || true
            (cd "$INSTALL_DIR" && docker-compose down 2>/dev/null) || true
        fi
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

    # Retrieve credentials
    echo ""
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN}  Fair TPRM & GRC Platform — Setup Complete!${NC}"
    echo -e "${GREEN}============================================================${NC}"
    echo ""
    echo -e "  ${BLUE}Application URL:${NC}  http://$(hostname -I | awk '{print $1}'):8080"
    echo -e "  ${BLUE}                   http://localhost:8080${NC}"
    echo ""
    echo -e "  ${BLUE}Install directory:${NC} $INSTALL_DIR"
    echo -e "  ${BLUE}Setup log:${NC}         $LOG_FILE"
    echo ""

    # Try to show credentials
    local CREDS
    CREDS=$($COMPOSE_CMD exec -T open-tprm cat /persistent/config/.sensitive 2>/dev/null) || true

    if [ -n "$CREDS" ]; then
        echo -e "  ${YELLOW}Login Credentials:${NC}"
        echo "$CREDS" | grep -E "^(PORTAL_|MYSQL_|ENCRYPTION)" | while IFS='=' read -r KEY VAL; do
            printf "    %-28s %s\n" "$KEY:" "$VAL"
        done
        echo ""
        echo -e "  ${YELLOW}To view credentials later:${NC}"
        echo "    cd $INSTALL_DIR && $COMPOSE_CMD exec open-tprm cat /persistent/config/.sensitive"
    else
        echo -e "  ${YELLOW}Credentials will appear in the container logs once initialization completes:${NC}"
        echo "    cd $INSTALL_DIR && $COMPOSE_CMD logs | grep -A 20 'DATABASE CREATED'"
    fi

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
