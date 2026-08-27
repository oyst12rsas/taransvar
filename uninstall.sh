#!/bin/bash
set -euo pipefail

# TaraSec uninstall/reset helper.
#
# Default behaviour removes TaraSec-owned configuration, services, database,
# hotspot profiles, cron entries, deployed web files and runtime state while
# preserving generic OS packages and the source checkout.
#
# Optional destructive flags:
#   --purge-netbird   Also remove the NetBird client/package and its state.
#   --purge-packages  Purge packages installed by the current TaraSec installer.
#                     WARNING: some may have existed before TaraSec.
#   --remove-source   Remove this TaraSec source checkout after uninstall.
#   --yes             Skip the interactive confirmation.
#
# A truly byte-for-byte pre-TaraSec restoration is impossible for installations
# made before the installer started recording a pre-install manifest. This
# script therefore removes known TaraSec-owned state and is deliberately
# conservative with generic system software unless explicitly requested.

PURGE_NETBIRD=0
PURGE_PACKAGES=0
REMOVE_SOURCE=0
ASSUME_YES=0

for arg in "$@"; do
    case "$arg" in
        --purge-netbird)  PURGE_NETBIRD=1 ;;
        --purge-packages) PURGE_PACKAGES=1 ;;
        --remove-source)  REMOVE_SOURCE=1 ;;
        --yes)            ASSUME_YES=1 ;;
        -h|--help)
            sed -n '1,28p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            exit 2
            ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$SCRIPT_DIR"

cat <<EOF
=== TARASEC UNINSTALL ===

This will remove TaraSec-owned state from this machine, including:
  * TaraSec services and firewall helpers
  * TaraSec hotspot NetworkManager profiles
  * openNDS TaraSec captive-portal configuration/state
  * TaraSec cron jobs and runtime/log directories
  * TaraSec database and application DB account
  * TaraSec files deployed from this checkout into /var/www/html
  * /etc/tarasec device/configuration state

Generic OS packages are preserved by default.
NetBird package removal: $([ "$PURGE_NETBIRD" = 1 ] && echo YES || echo NO)
Package purge:          $([ "$PURGE_PACKAGES" = 1 ] && echo YES || echo NO)
Remove source checkout: $([ "$REMOVE_SOURCE" = 1 ] && echo YES || echo NO)
EOF

if [ "$ASSUME_YES" -ne 1 ]; then
    echo
    echo "Type REMOVE TARASEC to continue:"
    read -r answer
    if [ "$answer" != "REMOVE TARASEC" ]; then
        echo "Aborted."
        exit 0
    fi
fi

echo
echo "=== STOP TARASEC SERVICES ==="
known_services=(
    taransvar.service
    tarasec-management-firewall.service
    tarasec-hotspot.service
    tarasec-hostapd-disconnect.service
    opennds.service
)
for svc in "${known_services[@]}"; do
    if systemctl list-unit-files --no-legend "$svc" 2>/dev/null | grep -q .; then
        systemctl disable --now "$svc" 2>/dev/null || true
    fi
done

# Remove TaraSec-specific service units/helpers if present. Do not remove generic
# distro units such as apache2, mysql, NetworkManager or dnsmasq here.
rm -f \
    /etc/systemd/system/taransvar.service \
    /etc/systemd/system/tarasec-management-firewall.service \
    /etc/systemd/system/tarasec-hotspot.service \
    /etc/systemd/system/tarasec-hostapd-disconnect.service \
    /usr/local/sbin/tarasec-management-firewall \
    /usr/local/sbin/tarasec-mgmt-client \
    /usr/local/sbin/tarasec-hostapd-disconnect
systemctl daemon-reload || true


echo
echo "=== REMOVE TARASEC CRON ENTRIES ==="
if [ -f /var/spool/cron/crontabs/root ]; then
    tmp="$(mktemp)"
    grep -vE 'sleepingbeauty\.pl|/root/(wifi|setup)/|tarasec|taransvar' \
        /var/spool/cron/crontabs/root > "$tmp" || true
    cat "$tmp" > /var/spool/cron/crontabs/root
    chmod 600 /var/spool/cron/crontabs/root
    rm -f "$tmp"
fi
service cron reload 2>/dev/null || true


echo
echo "=== REMOVE HOTSPOT NETWORK PROFILES ==="
if command -v nmcli >/dev/null 2>&1; then
    while IFS= read -r profile; do
        [ -n "$profile" ] || continue
        echo "Deleting NetworkManager profile: $profile"
        nmcli connection delete "$profile" >/dev/null 2>&1 || true
    done < <(nmcli -t -f NAME connection show 2>/dev/null | grep '^tarasec-hotspot-' || true)
fi


echo
echo "=== REMOVE TARASEC FIREWALL CHAINS ==="
# iptables-nft chains created by TaraSec helpers. Delete jumps first, then chains.
for table in filter nat mangle; do
    command -v iptables >/dev/null 2>&1 || break
    while read -r chain; do
        [ -n "$chain" ] || continue
        # Remove references to the chain from all built-in/custom chains.
        while read -r rule; do
            [ -n "$rule" ] || continue
            # Convert -A to -D and execute exactly the rendered iptables rule.
            rule="${rule/-A /-D }"
            iptables -t "$table" $rule 2>/dev/null || true
        done < <(iptables -t "$table" -S 2>/dev/null | grep -- "-j $chain" || true)
        iptables -t "$table" -F "$chain" 2>/dev/null || true
        iptables -t "$table" -X "$chain" 2>/dev/null || true
    done < <(iptables -t "$table" -S 2>/dev/null | awk '/^-N TARASEC-/{print $2}')
done


echo
echo "=== REMOVE OPENNDS TARASEC STATE ==="
# openNDS itself is stopped above. Remove TaraSec-generated config/hooks but do
# not delete unrelated administrator-created openNDS files blindly.
rm -f \
    /etc/opennds/htdocs/tarasec* \
    /etc/opennds/tarasec* \
    /usr/lib/opennds/tarasec* \
    /usr/local/lib/opennds/tarasec* 2>/dev/null || true


echo
echo "=== REMOVE TARASEC RUNTIME / CONFIGURATION ==="
rm -rf \
    /etc/tarasec \
    /root/wifi \
    /root/setup \
    /var/lib/tarasec \
    /var/log/tarasec \
    /run/tarasec

rm -f \
    /etc/init/startup.conf \
    /usr/lib/cgi-bin/debugserver

# Remove only the Apache CGI block/handler added by the TaraSec installer.
if [ -f /etc/apache2/apache2.conf ]; then
    python3 - <<'PY' || true
from pathlib import Path
p=Path('/etc/apache2/apache2.conf')
s=p.read_text()
block='''<Directory /usr/lib/cgi-bin>\n  Options +ExecCGI\n</Directory>\n\nAddHandler cgi-script .cgi .pl\n'''
s=s.replace('\n'+block, '\n').replace(block, '')
p.write_text(s)
PY
fi


echo
echo "=== REMOVE DEPLOYED TARASEC WEB FILES ==="
# Delete files from /var/www/html only when there is a corresponding source
# file under this checkout's html/ directory. This avoids wiping unrelated web
# applications that may share Apache's DocumentRoot.
if [ -d "$REPO_ROOT/html" ] && [ -d /var/www/html ]; then
    while IFS= read -r -d '' src; do
        rel="${src#$REPO_ROOT/html/}"
        dst="/var/www/html/$rel"
        [ -e "$dst" ] || continue
        rm -f -- "$dst"
    done < <(find "$REPO_ROOT/html" -type f -print0)

    # Remove now-empty directories, deepest first, but never /var/www/html.
    find /var/www/html -depth -type d -empty -delete 2>/dev/null || true
fi


echo
echo "=== REMOVE TARASEC DATABASE ==="
if command -v mysql >/dev/null 2>&1; then
    mysql <<'SQL' || true
DROP DATABASE IF EXISTS taransvar;
DROP USER IF EXISTS 'scriptUsrAces3f3'@'localhost';
DROP USER IF EXISTS 'scriptUsrAces3f3'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
fi


echo
echo "=== NETBIRD ==="
if command -v netbird >/dev/null 2>&1; then
    # Disconnect this peer from the management network. This removes local
    # enrollment state but cannot delete the peer record from the remote
    # NetBird management server without server-side API credentials.
    netbird down 2>/dev/null || true
fi
rm -f /etc/tarasec/netbird.env 2>/dev/null || true

if [ "$PURGE_NETBIRD" -eq 1 ]; then
    systemctl disable --now netbird.service 2>/dev/null || true
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get purge -y netbird 2>/dev/null || true
    fi
    rm -rf /etc/netbird /var/lib/netbird /var/log/netbird
else
    echo "NetBird package preserved. Use --purge-netbird on a dedicated TaraSec machine."
fi


echo
echo "=== OPTIONAL PACKAGE PURGE ==="
if [ "$PURGE_PACKAGES" -eq 1 ]; then
    cat <<'EOF'
WARNING: purging packages installed by the TaraSec installer. The old installer
cannot tell whether these packages existed before TaraSec, so this mode is only
appropriate for a machine dedicated to TaraSec or a disposable test host.
EOF
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get purge -y \
            opennds freeradius freeradius-mysql ipfm \
            libdbi-perl libdbd-mysql-perl \
            php-mysql libapache2-mod-php php \
            apache2 mysql-server mysql-client 2>/dev/null || true
        DEBIAN_FRONTEND=noninteractive apt-get autoremove -y 2>/dev/null || true
    fi
else
    echo "Generic packages preserved."
fi

# Restart generic services only if they remain installed.
for svc in NetworkManager apache2 mysql cron; do
    if systemctl list-unit-files --no-legend "$svc.service" 2>/dev/null | grep -q .; then
        systemctl restart "$svc.service" 2>/dev/null || true
    fi
done


echo
echo "=== POST-UNINSTALL CHECK ==="
echo "Remaining TaraSec paths (if any):"
for p in /etc/tarasec /root/wifi /root/setup /var/lib/tarasec /var/log/tarasec; do
    [ -e "$p" ] && echo "  $p"
done

echo
if command -v nmcli >/dev/null 2>&1; then
    echo "Remaining TaraSec NetworkManager profiles:"
    nmcli -t -f NAME connection show 2>/dev/null | grep '^tarasec-' || echo "  none"
fi

echo
if command -v mysql >/dev/null 2>&1; then
    db_exists="$(mysql -N -s -e \"SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='taransvar';\" 2>/dev/null || echo '?')"
    echo "taransvar database present: $db_exists"
fi

if [ "$REMOVE_SOURCE" -eq 1 ]; then
    source_parent="$(dirname "$REPO_ROOT")"
    source_name="$(basename "$REPO_ROOT")"
    echo
    echo "Removing source checkout: $REPO_ROOT"
    cd "$source_parent"
    rm -rf -- "$source_name"
fi

echo
echo "=== TARASEC UNINSTALL COMPLETE ==="
echo "TaraSec-owned local state has been removed."
if [ "$PURGE_PACKAGES" -eq 0 ]; then
    echo "Generic packages were intentionally preserved."
fi
if [ "$PURGE_NETBIRD" -eq 0 ]; then
    echo "NetBird software was intentionally preserved, but this peer was disconnected."
fi
echo "A remote NetBird peer record may still need deletion from the NetBird dashboard/API."
