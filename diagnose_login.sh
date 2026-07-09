#!/usr/bin/env bash
# =============================================================================
# Fair TPRM - login diagnostics and admin password reset
#
#   sudo bash diagnose_login.sh                  # diagnose only, changes nothing
#   sudo bash diagnose_login.sh --reset          # reset to a fresh random password
#   sudo bash diagnose_login.sh --reset 'MyPw!'  # reset to a password you choose
#   sudo bash diagnose_login.sh --url http://192.0.2.10:8080
#                                                # also log in over the URL a browser
#                                                # uses, to separate an app problem
#                                                # from a browser/network one
#
# "Invalid username or password" has three distinct causes that the login page
# deliberately does not distinguish (so nobody can enumerate usernames). This
# tells them apart:
#   - no admin row at all         -> the database was never seeded
#   - is_active = 0               -> the account is disabled
#   - password_verify() mismatch  -> .sensitive is stale vs the database
#
# The last one is the common trap: the printed password lives in .sensitive in
# the persistent_data volume, while the bcrypt hash lives in the users table in
# mariadb_data. Recreating one volume without the other leaves the file showing
# a password the database has never had.
# =============================================================================
set -uo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'
ok()   { echo "${GREEN}[OK]${NC}    $*"; }
bad()  { echo "${RED}[FAIL]${NC}  $*"; }
warn() { echo "${YELLOW}[WARN]${NC}  $*"; }
info() { echo "${BLUE}[INFO]${NC}  $*"; }

DO_RESET=false
NEW_PW=""
EXT_URL=""
while [ $# -gt 0 ]; do
    case "$1" in
        --reset)   DO_RESET=true
                   case "${2:-}" in -*|"") ;; *) NEW_PW="$2"; shift ;; esac ;;
        --url)     [ -n "${2:-}" ] || { echo "--url needs a value, e.g. --url http://192.0.2.10:8080"; exit 2; }
                   EXT_URL="${2%/}"; shift ;;
        -h|--help) sed -n '2,21p' "$0"; exit 0 ;;
        *)         echo "Unknown argument: $1 (try --help)"; exit 2 ;;
    esac
    shift
done

CID=$(docker ps -q --filter name=fairtprm | head -1)
if [ -z "$CID" ]; then
    bad "No running container named 'fairtprm'."
    echo "        Start it with:  cd /data/docker/fairtprm && docker compose up -d"
    exit 1
fi
info "Container: $(docker inspect -f '{{.Name}}' "$CID" | sed 's|^/||')  image: $(docker inspect -f '{{.Config.Image}}' "$CID")"

# --- 1. Is the Secure/__Host- cookie fix present, and how does it behave? ----
echo
echo "${BLUE}=== Session cookie ===${NC}"
if docker exec "$CID" grep -q requestIsHttps /var/www/html/includes/classes/Session.php 2>/dev/null; then
    ok "Image contains the plain-HTTP session cookie fix."
else
    warn "This image predates the session cookie fix. Over plain HTTP by IP a"
    warn "browser drops the session cookie and login fails with"
    warn "\"Invalid request. Please try again.\" -- pull a newer image."
fi
COOKIE=$(docker exec "$CID" curl -sS -o /dev/null -D - http://127.0.0.1:8080/login.php 2>/dev/null | grep -i '^set-cookie' | tr -d '\r')
echo "        ${COOKIE:-<no Set-Cookie header>}"
case "$COOKIE" in
    *__Host-*|*secure*) warn "Cookie is Secure/__Host- -- a browser keeps it only over HTTPS." ;;
    *)                  ok "Cookie is usable over plain HTTP." ;;
esac

# --- 2. Which of the three "invalid" causes is it? --------------------------
echo
echo "${BLUE}=== Admin account ===${NC}"
docker exec "$CID" mysql -t tprm -e "SELECT id,username,is_active,LENGTH(password_hash) hash_len,LEFT(password_hash,7) algo,failed_login_attempts fails,IFNULL(account_locked_until,'-') locked_until FROM users;" 2>/dev/null \
    || bad "Could not query the users table."

VERDICT=$(docker exec "$CID" bash -lc '
PW=$(grep "^PORTAL_ADMIN_PASSWORD=" /persistent/config/.sensitive 2>/dev/null | cut -d= -f2-)
[ -n "$PW" ] || { echo "NOPASS"; exit 0; }
echo "LEN:${#PW}"
PW="$PW" php -r '"'"'
$h = trim(shell_exec("mysql -N -B tprm -e \"SELECT password_hash FROM users WHERE username=\x27admin\x27 AND is_active=1\""));
if ($h === "") { echo "NOROW\n"; exit; }
echo password_verify(getenv("PW"), $h) ? "MATCH\n" : "MISMATCH\n";
'"'"'
')
echo
case "$VERDICT" in
    *NOPASS*)   bad "No PORTAL_ADMIN_PASSWORD in /persistent/config/.sensitive." ;;
    *NOROW*)    bad "No row matches (username='admin' AND is_active=1)."
                echo "        Either the seed never ran, or the account is disabled." ;;
    *MISMATCH*) bad "The password in .sensitive does NOT match the database hash."
                echo "        .sensitive lives in the persistent_data volume; the hash lives in"
                echo "        mariadb_data. They came from different installs. Re-run with --reset." ;;
    *MATCH*)    ok "The password in .sensitive matches the admin hash ($(echo "$VERDICT" | sed -n 's/^LEN:\([0-9]*\).*/\1/p') chars)." ;;
    *)          warn "Inconclusive: $VERDICT" ;;
esac

# --- 3. Ground truth: a real login through the app --------------------------
echo
echo "${BLUE}=== Real login through the app ===${NC}"
docker exec "$CID" bash -lc '
PW=$(grep "^PORTAL_ADMIN_PASSWORD=" /persistent/config/.sensitive 2>/dev/null | cut -d= -f2-)
[ -n "$PW" ] || exit 0
CK=$(mktemp)
T=$(curl -s -c "$CK" http://127.0.0.1:8080/login.php | grep -oE "name=\"csrf_token\" value=\"[^\"]+\"" | head -1 | sed -E "s/.*value=\"([^\"]+)\".*/\1/")
code=$(curl -s -o /tmp/_r.html -w "%{http_code}" -b "$CK" -c "$CK" -X POST -d username=admin --data-urlencode "password=$PW" -d "csrf_token=$T" http://127.0.0.1:8080/login.php)
echo "        POST /login.php -> HTTP $code"
if [ "$code" = "302" ]; then
    echo "        SUCCESS: these credentials work."
else
    grep -aoE "Invalid[^<]{0,40}|Account is locked[^<]{0,30}" /tmp/_r.html | head -2 | sed "s/^/        /"
fi
rm -f "$CK" /tmp/_r.html
'

# --- 3b. Same login, but over the URL a browser actually uses ---------------
# Isolates "the app rejects these credentials" from "something between the
# browser and the app is at fault" (autofill, proxy, WAF, wrong host/port).
if [ -n "$EXT_URL" ]; then
    echo
    echo "${BLUE}=== Real login over ${EXT_URL} ===${NC}"
    PW=$(docker exec "$CID" grep '^PORTAL_ADMIN_PASSWORD=' /persistent/config/.sensitive 2>/dev/null | cut -d= -f2-)
    if [ -z "$PW" ]; then
        bad "No password in .sensitive to test with."
    elif ! curl -sS -m 10 -o /dev/null "${EXT_URL}/login.php" 2>/dev/null; then
        bad "Cannot even fetch ${EXT_URL}/login.php from this host."
        echo "        The port is not published on that interface, or a firewall is in the way."
    else
        CK=$(mktemp)
        UA='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126 Safari/537.36'
        T=$(curl -s -A "$UA" -c "$CK" "${EXT_URL}/login.php" | grep -oE 'name="csrf_token" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')
        R=$(mktemp)
        CODE=$(curl -s -A "$UA" -o "$R" -w '%{http_code}' -b "$CK" -c "$CK" -X POST \
               -d username=admin --data-urlencode "password=${PW}" -d "csrf_token=${T}" "${EXT_URL}/login.php")
        echo "        POST ${EXT_URL}/login.php -> HTTP $CODE"
        if [ "$CODE" = "302" ]; then
            ok "The app accepts these credentials over this exact URL."
            echo "        So the app, the cookie and the password are all fine. If your browser"
            echo "        still fails here, the browser is sending a different password --"
            echo "        almost always a saved/autofilled one. Try a private window."
        else
            grep -aoE 'Invalid[^<]{0,40}|Account is locked[^<]{0,30}' "$R" | head -2 | sed 's/^/        /'
            warn "The app rejects this login over ${EXT_URL} but accepts it on 127.0.0.1."
            warn "Something on the network path is altering the request."
        fi
        rm -f "$CK" "$R"
    fi
    echo
    echo "        Password bytes (spot stray whitespace or smart quotes):"
    printf '        '; printf '%s' "$PW" | od -c | head -2 | sed 's/^/        /'
fi

# --- 4. Optional reset -----------------------------------------------------
if [ "$DO_RESET" = true ]; then
    echo
    echo "${BLUE}=== Resetting the admin password ===${NC}"
    if [ -z "$NEW_PW" ]; then
        NEW_PW=$(docker exec "$CID" bash -lc "openssl rand -base64 16 | tr -d '/+=' | head -c 16")
        info "Generated a new password."
    fi

    # Hash and UPDATE through PDO: a bcrypt hash contains '$' and '/', which a
    # shell-interpolated SQL string would mangle.
    docker exec -e NEWPW="$NEW_PW" "$CID" php -r '
        $cfg = require "/persistent/config/config.php";
        $d = $cfg["database"] ?? $cfg["db"];
        $pdo = new PDO("mysql:host=" . ($d["host"] ?? "127.0.0.1") . ";dbname=" . ($d["database"] ?? "tprm"),
                       $d["username"] ?? $d["user"], $d["password"]);
        $h = password_hash(getenv("NEWPW"), PASSWORD_BCRYPT, ["cost" => 12]);
        $s = $pdo->prepare("UPDATE users SET password_hash=:h, is_active=1, failed_login_attempts=0, account_locked_until=NULL WHERE username=:u");
        $s->execute([":h" => $h, ":u" => "admin"]);
        echo "        rows updated: " . $s->rowCount() . "\n";
    ' || { bad "Reset failed."; exit 1; }

    # Keep .sensitive honest so it stops advertising a password that no longer works.
    docker exec -e NEWPW="$NEW_PW" "$CID" bash -lc '
        f=/persistent/config/.sensitive
        [ -f "$f" ] || exit 0
        if grep -q "^PORTAL_ADMIN_PASSWORD=" "$f"; then
            sed -i "s|^PORTAL_ADMIN_PASSWORD=.*|PORTAL_ADMIN_PASSWORD=${NEWPW}|" "$f"
        else
            echo "PORTAL_ADMIN_PASSWORD=${NEWPW}" >> "$f"
        fi
    ' && ok "Updated /persistent/config/.sensitive to match."

    echo
    echo "  +------------------------------------------------------------+"
    printf "  |   Username:  %-45s |\n" "admin"
    printf "  |   Password:  %-45s |\n" "$NEW_PW"
    echo "  +------------------------------------------------------------+"
    echo
    ok "Log in, then change this password in the web UI."
else
    echo
    info "Nothing was changed. To reset the admin password, re-run with:"
    echo "        sudo bash $0 --reset"
fi
