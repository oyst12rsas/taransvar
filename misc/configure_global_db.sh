#!/bin/bash
# Configure which central TaraSec DB server ("Global DB 1") this node reports to,
# and install reliable rsyslog telemetry forwarding to it (TCP/5514).
#
# Keeps the two existing settings in sync:
#   setup.globalDb1ip      (local DB; shown in Gatekeeper, used by activity discovery)
#   DBSERVER               (/etc/tarasecfw.conf; used by honeypot/firewall scripts)
#
# Usage: sudo bash misc/configure_global_db.sh [DB_SERVER_IPV4]
#   Without an argument: TARASEC_DBSERVER env, then an interactive prompt whose
#   default is the current DBSERVER or setup.globalDb1ip. Non-interactive with no
#   value keeps the current setting.
set -euo pipefail

[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo "Run as root." >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF=/etc/tarasecfw.conf

valid_ipv4() {
    local ip="$1" IFS=. part
    [[ $ip =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1
    for part in $ip; do (( part <= 255 )) || return 1; done
}

current_conf=""
if [[ -r $CONF ]]; then
    current_conf=$(sed -n 's/^DBSERVER="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$CONF" | tail -n 1)
fi
current_db=$(mariadb -NB taransvar -e 'SELECT INET_NTOA(globalDb1ip) FROM setup LIMIT 1' 2>/dev/null || true)
[[ $current_db == NULL ]] && current_db=""
is_central=$(mariadb -NB taransvar -e 'SELECT CAST(isGlobalDbServer AS UNSIGNED) FROM setup LIMIT 1' 2>/dev/null || true)

target="${1:-${TARASEC_DBSERVER:-}}"
default="${current_conf:-$current_db}"
if [[ -z $target ]]; then
    if [[ -t 0 ]]; then
        read -r -p "Central TaraSec DB server (Global DB 1) IPv4 [${default:-none}]: " target
    fi
    target="${target:-$default}"
fi

if [[ -z $target ]]; then
    echo "WARNING: no central DB server configured; security events stay in the local journal." >&2
    exit 0
fi
valid_ipv4 "$target" || { echo "Invalid IPv4 address: $target" >&2; exit 1; }

if [[ $is_central == 1 ]]; then
    echo "This node is the global DB server (setup.isGlobalDbServer = 1); not forwarding to itself."
    exit 0
fi

touch "$CONF"
if [[ $current_conf != "$target" ]]; then
    cp -a "$CONF" "$CONF.bak-$(date +%Y%m%d%H%M%S)"
    tmp=$(mktemp)
    grep -v '^DBSERVER=' "$CONF" > "$tmp" || true
    printf 'DBSERVER="%s"\n' "$target" >> "$tmp"
    install -m 0644 "$tmp" "$CONF"
    rm -f "$tmp"
fi

if [[ $current_db != "$target" ]]; then
    mariadb taransvar -e "UPDATE setup SET globalDb1ip = INET_ATON('$target')" ||
        echo "WARNING: could not update setup.globalDb1ip; set Global DB 1 in Gatekeeper." >&2
fi

bash "$SCRIPT_DIR/setup_backoffice_ai.sh" sensor "$target"

if timeout 5 bash -c "</dev/tcp/$target/5514" 2>/dev/null; then
    echo "Central DB server $target reachable on TCP/5514."
else
    echo "WARNING: $target:5514 is not reachable now; rsyslog queues events on disk and retries." >&2
fi
