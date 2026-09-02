#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
WATCH_SRC="$REPO_ROOT/hotspot/opennds/tarasec-wifi-session-watch"
LOGOUT_SRC="$REPO_ROOT/hotspot/opennds/tarasec-subscriber-logout"
ACCOUNTING_SRC="$REPO_ROOT/hotspot/opennds/tarasec-hotspot-accounting"
USERS_SRC="$REPO_ROOT/misc/tarasec-users.pl"

if [ ! -f "$WATCH_SRC" ]; then
    echo "ERROR: missing $WATCH_SRC" >&2
    exit 1
fi
for required in "$LOGOUT_SRC" "$ACCOUNTING_SRC" "$USERS_SRC"; do
    if [ ! -f "$required" ]; then
        echo "ERROR: missing $required" >&2
        exit 1
    fi
done

if ! command -v iw >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y iw
fi

# Determine the active hotspot interface now and pass it explicitly to systemd.
HOTSPOT_IF="${TARASEC_HOTSPOT_IF:-}"
if [ -z "$HOTSPOT_IF" ] && [ -r /etc/config/opennds ]; then
    HOTSPOT_IF="$(sed -n "s/^[[:space:]]*option[[:space:]]\+gatewayinterface[[:space:]]*['\"]\?\([^'\"[:space:]]*\)['\"]\?.*/\1/p" /etc/config/opennds | head -1)"
fi
if [ -z "$HOTSPOT_IF" ] && command -v ndsctl >/dev/null 2>&1; then
    HOTSPOT_IF="$(ndsctl status 2>/dev/null | sed -n 's/^Managed interface:[[:space:]]*//p' | head -1 | tr -d '[][:space:]')"
fi
if [ -z "$HOTSPOT_IF" ] || ! ip link show "$HOTSPOT_IF" >/dev/null 2>&1; then
    echo "ERROR: could not determine active TaraSec hotspot interface." >&2
    echo "Set it explicitly, for example: sudo TARASEC_HOTSPOT_IF=wlp5s0 bash $0" >&2
    exit 1
fi

# Repository files do not need their executable bit preserved by GitHub.
# install(1) sets the required runtime mode explicitly here.
install -m 0755 "$WATCH_SRC" /usr/local/sbin/tarasec-wifi-session-watch
install -m 0755 "$LOGOUT_SRC" /usr/local/sbin/tarasec-subscriber-logout
install -m 0755 "$ACCOUNTING_SRC" /usr/local/sbin/tarasec-hotspot-accounting
install -m 0755 "$USERS_SRC" /usr/local/sbin/tarasec-users
/usr/local/sbin/tarasec-users --ensure-schema

cat > /etc/systemd/system/tarasec-wifi-session-watch.service <<EOF
[Unit]
Description=TaraSec hotspot Wi-Fi session disconnect watcher
After=network-online.target opennds.service
Wants=network-online.target

[Service]
Type=simple
Environment=TARASEC_HOTSPOT_IF=$HOTSPOT_IF
ExecStart=/usr/local/sbin/tarasec-wifi-session-watch
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable tarasec-wifi-session-watch.service >/dev/null 2>&1 || true
systemctl restart tarasec-wifi-session-watch.service
sleep 1
if ! systemctl is-active --quiet tarasec-wifi-session-watch.service; then
    echo "ERROR: TaraSec Wi-Fi session watcher did not stay active." >&2
    systemctl status tarasec-wifi-session-watch.service --no-pager >&2 || true
    journalctl -u tarasec-wifi-session-watch.service -n 50 --no-pager >&2 || true
    exit 1
fi

echo "TaraSec Wi-Fi session watcher installed and active on $HOTSPOT_IF."
