#!/bin/bash
set -euo pipefail

# TaraSec uninstall/reset helper for Ubuntu/Debian/Raspberry Pi OS.
#
# Default behaviour removes TaraSec-owned configuration, services, database,
# captive-portal state, hotspot profiles and runtime files while preserving the
# source checkout, the machine's normal WAN/uplink and generic OS packages.
#
# Optional destructive flags:
#   --purge-netbird   Also remove the NetBird client/package and its state.
#   --purge-packages  Purge generic packages installed by TaraSec. Use only on
#                     a dedicated/disposable TaraSec test machine.
#   --remove-source   Remove this TaraSec source checkout after uninstall.
#   --yes             Skip the interactive confirmation.

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
            sed -n '1,24p' "$0"
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
=== TARASEC UNINSTALL / RESET ===

This removes TaraSec-owned state from this machine, including:
  * TaraSec services and firewall helpers
  * TaraSec NetworkManager hotspot profiles
  * TaraSec openNDS captive-portal configuration
  * TaraSec Wi-Fi disconnect/session watcher
  * TaraSec Apache captive-login listener on port 8080
  * TaraSec cron jobs, runtime/log directories and database
  * TaraSec hotspot web application under /var/www/html/hotspot
  * TaraSec files deployed from this checkout into /var/www/html
  * /etc/tarasec device/configuration state

The current non-TaraSec WAN/default-route connection is preserved.
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
echo "=== RECORD CURRENT WAN ==="
WAN_IF="$(ip -4 route show default 2>/dev/null | awk 'NR==1 {print $5}')"
WAN_GW="$(ip -4 route show default 2>/dev/null | awk 'NR==1 {print $3}')"
echo "WAN interface before reset: ${WAN_IF:-none}"
echo "WAN gateway before reset:   ${WAN_GW:-none}"

echo
echo "=== STOP TARASEC SERVICES ==="
known_services=(
    tarasec-wifi-session-watch.service
    taransvar.service
    tarasec-management-firewall.service
    tarasec-hotspot.service
    tarasec-hostapd-disconnect.service
    opennds.service
)
for svc in "${known_services[@]}"; do
    systemctl disable --now "$svc" 2>/dev/null || true
done

rm -f \
    /etc/systemd/system/tarasec-wifi-session-watch.service \
    /etc/systemd/system/taransvar.service \
    /etc/systemd/system/tarasec-management-firewall.service \
    /etc/systemd/system/tarasec-hotspot.service \
    /etc/systemd/system/tarasec-hostapd-disconnect.service \
    /usr/local/sbin/tarasec-wifi-session-watch \
    /usr/local/sbin/tarasec-subscriber-logout \
    /usr/local/sbin/tarasec-access-check \
    /usr/local/sbin/tarasec-single-subscriber \
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
        nmcli connection down "$profile" >/dev/null 2>&1 || true
        nmcli connection delete "$profile" >/dev/null 2>&1 || true
    done < <(nmcli -t -f NAME connection show 2>/dev/null | grep '^tarasec-hotspot-' || true)
fi

echo
echo "=== REMOVE TARASEC FIREWALL CHAINS ==="
for table in filter nat mangle; do
    command -v iptables >/dev/null 2>&1 || break
    while read -r chain; do
        [ -n "$chain" ] || continue
        while read -r rule; do
            [ -n "$rule" ] || continue
            rule="${rule/-A /-D }"
            iptables -t "$table" $rule 2>/dev/null || true
        done < <(iptables -t "$table" -S 2>/dev/null | grep -- "-j $chain" || true)
        iptables -t "$table" -F "$chain" 2>/dev/null || true
        iptables -t "$table" -X "$chain" 2>/dev/null || true
    done < <(iptables -t "$table" -S 2>/dev/null | awk '/^-N TARASEC-/{print $2}')
done

echo
echo "=== RESET OPENNDS TARASEC CONFIGURATION ==="
if [ -f /etc/config/opennds.tarasec-before ]; then
    mv -f /etc/config/opennds.tarasec-before /etc/config/opennds
else
    rm -f /etc/config/opennds
fi

if [ -f /etc/opennds/opennds.conf.tarasec-before ]; then
    mv -f /etc/opennds/opennds.conf.tarasec-before /etc/opennds/opennds.conf
else
    rm -f /etc/opennds/opennds.conf
fi

rm -f \
    /usr/lib/opennds/theme_tarasec.sh \
    /usr/lib/opennds/access_policy.pl \
    /usr/lib/opennds/custombinauth.sh \
    /etc/opennds/htdocs/tarasec* \
    /etc/opennds/tarasec* \
    /usr/lib/opennds/tarasec* \
    /usr/local/lib/opennds/tarasec* 2>/dev/null || true

echo
echo "=== REMOVE CAPTIVE APACHE CONFIGURATION ==="
if command -v a2disconf >/dev/null 2>&1; then
    a2disconf tarasec-captive-login >/dev/null 2>&1 || true
fi
rm -f \
    /etc/apache2/conf-available/tarasec-captive-login.conf \
    /etc/apache2/conf-enabled/tarasec-captive-login.conf \
    /etc/sudoers.d/tarasec-hotspot-logout

if [ -f /etc/apache2/apache2.conf ] && command -v python3 >/dev/null 2>&1; then
    python3 - <<'PY' || true
from pathlib import Path
p = Path('/etc/apache2/apache2.conf')
s = p.read_text()
block = '''<Directory /usr/lib/cgi-bin>\n  Options +ExecCGI\n</Directory>\n\nAddHandler cgi-script .cgi .pl\n'''
s = s.replace('\n' + block, '\n').replace(block, '')
p.write_text(s)
PY
fi

echo
echo "=== REMOVE TARASEC RUNTIME / CONFIGURATION ==="
rm -rf \
    /etc/tarasec \
    /root/wifi \
    /root/setup \
    /var/lib/tarasec \
    /var/log/tarasec \
    /run/tarasec
rm -f /etc/init/startup.conf /usr/lib/cgi-bin/debugserver

echo
echo "=== REMOVE DEPLOYED TARASEC WEB FILES ==="
# /var/www/html/hotspot is wholly TaraSec-owned by the hotspot installer and
# must be removed as a unit. Leaving stale files here can make a broken fresh
# installation appear to work.
rm -rf /var/www/html/hotspot

# For other web paths, remove only files that correspond to this checkout.
if [ -d "$REPO_ROOT/html" ] && [ -d /var/www/html ]; then
    while IFS= read -r -d '' src; do
        rel="${src#$REPO_ROOT/html/}"
        dst="/var/www/html/$rel"
        [ -e "$dst" ] || continue
        rm -f -- "$dst"
    done < <(find "$REPO_ROOT/html" -type f -print0)
    find /var/www/html -mindepth 1 -depth -type d -empty -delete 2>/dev/null || true
fi

echo
echo "=== REMOVE TARASEC DATABASE ==="
if command -v mysql >/dev/null 2>&1; then
    if ! mysql <<'SQL'
DROP DATABASE IF EXISTS taransvar;
DROP USER IF EXISTS 'scriptUsrAces3f3'@'localhost';
DROP USER IF EXISTS 'scriptUsrAces3f3'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    then
        echo "ERROR: MySQL refused the TaraSec database/account cleanup." >&2
        echo "The uninstall is not clean; refusing to report success." >&2
        exit 1
    fi

    DB_LEFT="$(mysql -N -s -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='taransvar';")"
    if [ "${DB_LEFT:-1}" != "0" ]; then
        echo "ERROR: taransvar database still exists after DROP DATABASE." >&2
        exit 1
    fi
    echo "TaraSec database removed and verified."
fi

echo
echo "=== NETBIRD ==="
if command -v netbird >/dev/null 2>&1; then
    netbird down 2>/dev/null || true
fi
if [ "$PURGE_NETBIRD" -eq 1 ]; then
    systemctl disable --now netbird.service 2>/dev/null || true
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get purge -y netbird 2>/dev/null || true
    fi
    rm -rf /etc/netbird /var/lib/netbird /var/log/netbird
else
    echo "NetBird software preserved; local TaraSec enrollment state was removed."
fi

echo
echo "=== OPTIONAL PACKAGE PURGE ==="
if [ "$PURGE_PACKAGES" -eq 1 ]; then
    cat <<'EOF'
WARNING: purging packages installed by TaraSec. Use this only on a dedicated or
disposable TaraSec machine because some packages may have existed beforehand.
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
    echo "Generic packages, including any installed openNDS package/binary, preserved."
    echo "The TaraSec openNDS configuration was reset, so reinstall can configure it afresh."
fi

for svc in NetworkManager apache2 mysql cron; do
    if systemctl list-unit-files --no-legend "$svc.service" 2>/dev/null | grep -q .; then
        systemctl restart "$svc.service" 2>/dev/null || true
    fi
done

echo
echo "=== POST-UNINSTALL CHECK ==="
echo "Remaining TaraSec paths (if any):"
remaining=0
for p in \
    /etc/tarasec /root/wifi /root/setup /var/lib/tarasec /var/log/tarasec \
    /var/www/html/hotspot \
    /etc/systemd/system/tarasec-wifi-session-watch.service \
    /etc/apache2/conf-available/tarasec-captive-login.conf; do
    if [ -e "$p" ]; then
        echo "  $p"
        remaining=1
    fi
done
[ "$remaining" -eq 0 ] && echo "  none"

echo
if command -v nmcli >/dev/null 2>&1; then
    echo "Remaining TaraSec NetworkManager profiles:"
    nmcli -t -f NAME connection show 2>/dev/null | grep '^tarasec-' || echo "  none"
fi

echo
if command -v mysql >/dev/null 2>&1; then
    db_exists="$(mysql -N -s -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='taransvar';" 2>/dev/null || echo '?')"
    echo "taransvar database present: $db_exists"
    if [ "$db_exists" != "0" ]; then
        echo "ERROR: database cleanup verification failed." >&2
        exit 1
    fi
fi

echo
NEW_WAN_IF="$(ip -4 route show default 2>/dev/null | awk 'NR==1 {print $5}')"
NEW_WAN_GW="$(ip -4 route show default 2>/dev/null | awk 'NR==1 {print $3}')"
echo "WAN interface after reset: ${NEW_WAN_IF:-none}"
echo "WAN gateway after reset:   ${NEW_WAN_GW:-none}"
if [ -n "$WAN_IF" ] && [ -n "$NEW_WAN_IF" ] && [ "$WAN_IF" != "$NEW_WAN_IF" ]; then
    echo "WARNING: default-route interface changed from $WAN_IF to $NEW_WAN_IF; inspect NetworkManager before reinstalling."
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
echo "The normal WAN/default-route configuration was not intentionally modified."
if [ "$PURGE_PACKAGES" -eq 0 ]; then
    echo "Generic packages were intentionally preserved."
fi
if [ "$PURGE_NETBIRD" -eq 0 ]; then
    echo "NetBird software was intentionally preserved, but this peer was disconnected."
fi
echo "A remote NetBird peer record may still need deletion from the NetBird dashboard/API."
