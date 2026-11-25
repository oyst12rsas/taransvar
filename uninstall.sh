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
  * Node, SSH gate, honeypot, operations, upgrade and reporting units
  * The TaraSec SSH management port (sshd moves back to the default port)
  * TaraSec ipsets, nftables tables, rsyslog/sysctl drop-ins and libraries

SSH authentication policy (keys, passwords, AllowUsers) is left unchanged.

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
    tarasec-hotspot-dns.service
    tarasec-opennds-dhcp-compat.service
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
    /etc/systemd/system/tarasec-hotspot-dns.service \
    /etc/systemd/system/tarasec-opennds-dhcp-compat.service \
    /etc/systemd/system/taransvar.service \
    /etc/systemd/system/tarasec-management-firewall.service \
    /etc/systemd/system/tarasec-hotspot.service \
    /etc/systemd/system/tarasec-hostapd-disconnect.service \
    /usr/local/sbin/tarasec-wifi-session-watch \
    /usr/local/sbin/tarasec-hotspot-accounting \
    /usr/local/sbin/tarasec-hotspot-dns-redirect \
    /usr/local/sbin/tarasec-opennds-dhcp-compat \
    /usr/local/sbin/tarasec-subscriber-logout \
    /usr/local/sbin/tarasec-access-check \
    /usr/local/sbin/tarasec-single-subscriber \
    /usr/local/sbin/tarasec-management-firewall \
    /usr/local/sbin/tarasec-mgmt-client \
    /usr/local/sbin/tarasec-hostapd-disconnect
systemctl daemon-reload || true

echo
echo "=== STOP ALL TARASEC SYSTEMD UNITS ==="
# Node, SSH gate, operations, upgrade and reporting units keep being added, so
# match them by name instead of a list that goes stale. Transient units (for
# example tarasec-ssh-window-* timers) only show up in list-units.
unit_patterns=('tarasec-*' 'taransvar*' 'taralink*' 'tara-hotspot-*')
mapfile -t tarasec_units < <(
    {
        systemctl list-units --all --no-legend --plain "${unit_patterns[@]}" 2>/dev/null | awk '{print $1}'
        systemctl list-unit-files --no-legend "${unit_patterns[@]}" 2>/dev/null | awk '{print $1}'
    } | grep -E '\.(timer|path|socket|service)$' | sort -u || true
)
# Stop triggers first so nothing restarts a service we have just stopped.
for suffix in timer path socket service; do
    for unit in "${tarasec_units[@]}"; do
        [ "${unit##*.}" = "$suffix" ] || continue
        echo "Stopping $unit"
        systemctl disable --now "$unit" >/dev/null 2>&1 || systemctl stop "$unit" 2>/dev/null || true
    done
done
for pattern in "${unit_patterns[@]}"; do
    rm -rf /etc/systemd/system/$pattern
    find /etc/systemd/system -path '*.wants/*' -name "$pattern" -delete 2>/dev/null || true
done
systemctl daemon-reload || true
systemctl reset-failed "${unit_patterns[@]}" 2>/dev/null || true

if lsmod 2>/dev/null | grep -q '^tarakernel '; then
    modprobe -r tarakernel 2>/dev/null \
        || echo "WARNING: tarakernel module is still in use; it will not load after reboot."
fi
rm -f /etc/modules-load.d/tarakernel.conf

echo
echo "=== RESTORE DEFAULT SSH LISTENER ==="
# The honeypot and decoys were stopped above, so the default port is free.
# Only the port is reverted; authentication policy stays as the owner set it.
ssh_backup="$(mktemp -d)"
ssh_changed=0
for f in /etc/systemd/system/ssh.socket.d/90-tarasec-port.conf \
         /etc/systemd/system/ssh.service.d/90-tarasec-decoys.conf \
         /etc/systemd/system/sshd.service.d/90-tarasec-decoys.conf; do
    [ -e "$f" ] || continue
    cp -a --parents "$f" "$ssh_backup/"
    rm -f "$f"
    rmdir --ignore-fail-on-non-empty "$(dirname "$f")"
    echo "Removed $f"
    ssh_changed=1
done
sshd_tarasec=/etc/ssh/sshd_config.d/90-tarasec.conf
if [ -f "$sshd_tarasec" ]; then
    cp -a --parents "$sshd_tarasec" "$ssh_backup/"
    # ssh_port_setup may have merged owner policy into this file; keep it.
    remaining="$(grep -viE '^[[:space:]]*(Port[[:space:]]|# Management port set by TaraSec)' "$sshd_tarasec" || true)"
    if printf '%s\n' "$remaining" | grep -qvE '^[[:space:]]*(#|$)'; then
        printf '%s\n' "$remaining" > "$sshd_tarasec"
        echo "Removed the Port setting from $sshd_tarasec (other policy kept)"
    else
        rm -f "$sshd_tarasec"
        echo "Removed $sshd_tarasec"
    fi
    ssh_changed=1
fi

restore_ssh_backup() {
    cp -a "$ssh_backup"/. / 2>/dev/null || true
    systemctl daemon-reload || true
}

if [ "$ssh_changed" -eq 1 ] && command -v sshd >/dev/null 2>&1; then
    mkdir -p /run/sshd
    if ! sshd -t; then
        restore_ssh_backup
        echo "ERROR: sshd rejected the configuration without the TaraSec port; restored it." >&2
        exit 1
    fi
    systemctl daemon-reload
    ssh_service=""
    for name in ssh.service sshd.service; do
        if [ "$(systemctl show "$name" -p LoadState --value 2>/dev/null)" = loaded ]; then
            ssh_service="$name"
            break
        fi
    done
    if [ -z "$ssh_service" ]; then
        echo "WARNING: no ssh.service/sshd.service found; the new port applies after reboot."
    elif [ "$(systemctl show "$ssh_service" -p KillMode --value)" != process ]; then
        # Restarting would end established sessions, possibly this one.
        echo "WARNING: $ssh_service does not use KillMode=process; not restarting it."
        echo "         The default SSH port applies after the next reboot."
    else
        if systemctl is-active --quiet ssh.socket || systemctl is-enabled --quiet ssh.socket 2>/dev/null; then
            systemctl restart ssh.socket
        fi
        systemctl restart "$ssh_service"
        ssh_port="$(sshd -T 2>/dev/null | awk '$1 == "port" {print $2; exit}')"
        ssh_port="${ssh_port:-22}"
        if ss -H -ltn "sport = :$ssh_port" 2>/dev/null | grep -q .; then
            echo "sshd is listening on port $ssh_port; established sessions were kept."
        else
            echo "WARNING: nothing is listening on port $ssh_port; check sshd before logging out." >&2
        fi
    fi
fi
rm -rf "$ssh_backup"

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
# Chains are named both TARASEC-* (hotspot) and TARASEC_* (SSH gate, honeypot).
for ipt in iptables ip6tables; do
    command -v "$ipt" >/dev/null 2>&1 || continue
    for table in filter nat mangle raw; do
        # Rules in built-in chains that use TaraSec ipsets or comments.
        while read -r rule; do
            [ -n "$rule" ] || continue
            "$ipt" -t "$table" ${rule/-A /-D } 2>/dev/null || true
        done < <("$ipt" -t "$table" -S 2>/dev/null \
                 | grep -E -- '^-A .*(--match-set tarasec_|--comment "?tarasec)' || true)
        while read -r chain; do
            [ -n "$chain" ] || continue
            while read -r rule; do
                [ -n "$rule" ] || continue
                "$ipt" -t "$table" ${rule/-A /-D } 2>/dev/null || true
            done < <("$ipt" -t "$table" -S 2>/dev/null | grep -E -- "^-A .* -[jg] ${chain}( |\$)" || true)
            "$ipt" -t "$table" -F "$chain" 2>/dev/null || true
            "$ipt" -t "$table" -X "$chain" 2>/dev/null || true
        done < <("$ipt" -t "$table" -S 2>/dev/null | awk '/^-N TARASEC[-_]/{print $2}')
    done
done

if command -v ipset >/dev/null 2>&1; then
    while read -r set; do
        [ -n "$set" ] || continue
        ipset destroy "$set" 2>/dev/null || echo "WARNING: ipset $set is still referenced; not removed."
    done < <(ipset list -n 2>/dev/null | grep '^tarasec_' || true)
fi

if command -v nft >/dev/null 2>&1; then
    while read -r family name; do
        nft delete table "$family" "$name" 2>/dev/null || true
    done < <(nft list tables 2>/dev/null | awk '$1 == "table" && $3 ~ /^tarasec/ {print $2, $3}')
fi

rm -f /etc/NetworkManager/dnsmasq-shared.d/tarasec-status-client.conf
if [ -L /tmp/dhcp.leases ]; then
    lease_target="$(readlink /tmp/dhcp.leases 2>/dev/null || true)"
    case "$lease_target" in
        /var/lib/NetworkManager/dnsmasq-*.leases) rm -f /tmp/dhcp.leases ;;
    esac
fi

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
    /etc/tarasec[-.]* \
    /etc/tarasecfw.conf* \
    /root/wifi \
    /root/setup \
    /var/lib/tarasec \
    /var/lib/tarasec-* \
    /var/log/tarasec \
    /var/log/tarasec-* \
    /run/tarasec \
    /usr/local/lib/tarasec \
    /usr/local/lib/tarasec-* \
    /usr/local/sbin/tarasec \
    /usr/local/sbin/tarasec-* \
    /usr/local/bin/tarasec-* \
    /opt/tarasec-google
rm -f \
    /etc/rsyslog.d/30-tarasec-*.conf \
    /etc/sysctl.d/99-taransvar-ipsec.conf \
    /etc/NetworkManager/dnsmasq-shared.d/tarasec-*.conf
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
if [ "$PURGE_NETBIRD" -eq 1 ]; then
    if command -v netbird >/dev/null 2>&1; then
        netbird down 2>/dev/null || true
    fi
    systemctl disable --now netbird.service 2>/dev/null || true
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get purge -y netbird 2>/dev/null || true
    fi
    rm -rf /etc/netbird /var/lib/netbird /var/log/netbird
else
    echo "NetBird software, state and active connection preserved."
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

for svc in NetworkManager apache2 mysql cron rsyslog; do
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
    /etc/systemd/system/tarasec-hotspot-dns.service \
    /etc/systemd/system/tarasec-opennds-dhcp-compat.service \
    /etc/NetworkManager/dnsmasq-shared.d/tarasec-status-client.conf \
    /etc/apache2/conf-available/tarasec-captive-login.conf; do
    if [ -e "$p" ]; then
        echo "  $p"
        remaining=1
    fi
done
for p in /etc/tarasec* /var/lib/tarasec* /usr/local/lib/tarasec* /usr/local/sbin/tarasec* \
         /etc/systemd/system/tarasec-* /etc/systemd/system/taralink* \
         /etc/ssh/sshd_config.d/90-tarasec.conf /etc/systemd/system/ssh.socket.d/90-tarasec-port.conf; do
    [ -e "$p" ] || continue
    echo "  $p"
    remaining=1
done
[ "$remaining" -eq 0 ] && echo "  none"

echo
echo "Remaining TaraSec systemd units:"
systemctl list-units --all --no-legend --plain 'tarasec-*' 'taralink*' 2>/dev/null | awk '{print "  " $1}' | grep . || echo "  none"
if command -v ipset >/dev/null 2>&1; then
    echo "Remaining TaraSec ipsets:"
    ipset list -n 2>/dev/null | grep '^tarasec_' | sed 's/^/  /' || echo "  none"
fi
for f in /etc/ssh/sshd_config.d/*tarasec*; do
    [ -e "$f" ] && echo "Kept SSH authentication policy: $f"
done

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
    echo "NetBird software, peer state and connection were intentionally preserved."
fi
echo "A remote NetBird peer record may still need deletion from the NetBird dashboard/API."
